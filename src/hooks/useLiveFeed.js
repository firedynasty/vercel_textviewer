import { useCallback, useEffect, useRef, useState } from 'react';
import { loadServerNotes, renameTarget, scanDirectory, sortFeed } from '../utils/feedUtils';
import { scanDropbox } from '../utils/dropboxFeed';
import { readCache, writeCache } from '../utils/dropboxCache';
import { FEED_ROOT } from './useDropboxFeed';
import { CHAT_MARKER, hasControlCharacters } from '../utils/chatUtils';
import { WORKSHEET_MARKER } from '../utils/worksheetUtils';

export default function useLiveFeed(recursive, retainedItems, dropbox) {
  const server = window.__LOCAL_FEED__;
  const dropboxToken = dropbox?.token || null;
  const hydrated = useRef(false);
  // The hook returns a fresh object each render, so the scan tracks the token and reads the rest by ref.
  const dropboxRef = useRef(dropbox);
  dropboxRef.current = dropbox;
  const [directory, setDirectory] = useState(null);
  const [snapshot, setSnapshot] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(Boolean(server));
  const [stopped, setStopped] = useState(false);
  const [refreshKey, setRefreshKey] = useState(0);
  const cache = useRef(new Map());
  const allocatedUrls = useRef(new Set());

  // A pending snapshot may replace media while an older card is still being read.
  useEffect(() => {
    const used = new Set([...(snapshot?.items || []), ...(retainedItems || []), ...cache.current.values()].map(item => item.url));
    for (const url of allocatedUrls.current) {
      if (!used.has(url)) {
        URL.revokeObjectURL(url);
        allocatedUrls.current.delete(url);
      }
    }
  }, [snapshot, retainedItems]);

  useEffect(() => () => allocatedUrls.current.forEach(url => URL.revokeObjectURL(url)), []);

  const chooseFolder = useCallback(async () => {
    if (!window.showDirectoryPicker) {
      setError('Open this page in desktop Chrome, or use the local feed launcher.');
      return;
    }
    try {
      const handle = await window.showDirectoryPicker({ mode: 'read' });
      setDirectory(handle);
      setSnapshot(null);
      cache.current = new Map();
      setError('');
    } catch (problem) {
      if (problem.name !== 'AbortError') setError(problem.message);
    }
  }, []);

  useEffect(() => {
    if ((!server && !directory && !dropboxToken) || stopped) return;
    let cancelled = false;
    let timer;
    const controller = new AbortController();
    let since = ''; // Last server fingerprint: an unchanged folder answers in a few bytes instead of every note.
    setLoading(true);
    const scan = async () => {
      try {
        let next;
        if (server) {
          const response = await fetch(`${server.base}/api/feed?recursive=${recursive ? '1' : '0'}${since ? `&since=${since}` : ''}`, {
            signal: controller.signal, cache: 'no-store',
          });
          if (!response.ok) throw new Error(`Folder unavailable (${response.status}). Reopen it with the feed launcher.`);
          next = await response.json();
          if (next.unchanged) {
            if (!cancelled) setError('');
            return;
          }
          const result = await loadServerNotes(next.items, cache.current, controller.signal);
          if (cancelled) return;
          cache.current = result.cache;
          next.items = sortFeed(result.items);
          since = next.fingerprint || '';
        } else if (directory) {
          const result = await scanDirectory(directory, recursive, cache.current);
          for (const item of result.items) if (item.url) allocatedUrls.current.add(item.url);
          if (cancelled) return;
          cache.current = result.cache;
          next = { folder: directory.name, items: result.items };
        } else {
          // Notes cached from an earlier visit mean a reopened feed re-reads only what changed.
          if (!hydrated.current) {
            cache.current = await readCache(FEED_ROOT);
            hydrated.current = true;
          }
          let result;
          try {
            result = await scanDropbox(dropboxToken, FEED_ROOT, cache.current, controller.signal);
          } catch (problem) {
            if (!problem.expired) throw problem;
            const renewed = await dropboxRef.current.renew();
            if (!renewed) throw problem;
            result = await scanDropbox(renewed, FEED_ROOT, cache.current, controller.signal);
          }
          if (cancelled) return;
          cache.current = result.cache;
          writeCache(FEED_ROOT, result.cache);
          next = { folder: 'Dropbox', items: result.items };
        }
        if (!cancelled) {
          setSnapshot(next);
          setError('');
        }
      } catch (problem) {
        if (!cancelled && problem.name !== 'AbortError') setError(problem.message);
      } finally {
        if (!cancelled) {
          setLoading(false);
          timer = setTimeout(scan, server || directory ? 2000 : 30000);
        }
      }
    };
    scan();
    return () => {
      cancelled = true;
      clearTimeout(timer);
      controller.abort();
    };
  }, [directory, recursive, refreshKey, stopped, server, dropboxToken]);

  const stop = async () => {
    if (!server) return;
    try {
      const response = await fetch(`${server.base}/api/stop`, { method: 'POST' });
      if (!response.ok) throw new Error('Could not stop the server.');
      setStopped(true);
      setError('');
    } catch (problem) { setError(problem.message); }
  };

  const saveNote = async (filename, text) => {
    if (stopped) throw new Error('Reopen the folder to reconnect before saving.');
    let path;
    if (server) {
      const response = await fetch(`${server.base}/api/notes`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ filename, text }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || 'Could not save this note.');
      path = result.path;
    } else {
      if (!directory) throw new Error('Choose a folder before saving.');
      if (!/^[^/\\]+\.md$/i.test(filename) || filename.startsWith('.') || hasControlCharacters(filename)) throw new Error('Choose a Markdown filename without folders.');
      if (new Blob([text]).size > 2 * 1024 * 1024) throw new Error('This note exceeds 2 MiB.');
      const permission = await directory.requestPermission({ mode: 'readwrite' });
      if (permission !== 'granted') throw new Error('Allow write access to save a note in this folder.');
      for (let number = 1; number <= 10000; number++) {
        path = number === 1 ? filename : `${filename.slice(0, -3)}-${number}.md`;
        try { await directory.getFileHandle(path); }
        catch (problem) {
          if (problem.name !== 'NotFoundError') throw problem;
          const handle = await directory.getFileHandle(path, { create: true });
          const output = await handle.createWritable();
          try { await output.write(text); await output.close(); }
          catch (error) { await output.abort().catch(() => {}); throw error; }
          setRefreshKey(value => value + 1);
          return path;
        }
      }
      throw new Error('Choose another filename.');
    }
    setRefreshKey(value => value + 1);
    return path;
  };

  // Let the desktop decide which app owns this file, the way double-clicking it would.
  // { editor: true } opens a .txt/.md note in CotEditor instead of its default app.
  const openExternally = async (item, { editor = false } = {}) => {
    if (!server) throw new Error('Open this folder with the Finder Quick Action to open files in their desktop app.');
    if (stopped) throw new Error('Reopen the folder to reconnect before opening files.');
    const response = await fetch(`${server.base}/api/open`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(editor ? { path: item.path, editor: true } : { path: item.path }),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(result.error || 'Could not open this file in a desktop app.');
    return result.path;
  };

  // Rename a file in place: same folder, same extension, never replacing another file.
  const renameItem = async (item, name) => {
    if (stopped) throw new Error('Reopen the folder to reconnect before renaming.');
    const target = renameTarget(item.name, name);
    let path;
    if (target === item.name) return item.path;
    if (server) {
      const response = await fetch(`${server.base}/api/rename`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ path: item.path, name: target }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || 'Could not rename this file.');
      path = result.path;
    } else {
      if (!directory) throw new Error('Choose a folder before renaming.');
      const parts = item.path.split('/');
      let parent = directory;
      for (const part of parts.slice(0, -1)) parent = await parent.getDirectoryHandle(part);
      const handle = await parent.getFileHandle(parts[parts.length - 1]);
      if (typeof handle.move !== 'function') throw new Error('This browser cannot rename files. Open the folder with the Finder launcher.');
      if (await directory.requestPermission({ mode: 'readwrite' }) !== 'granted') throw new Error('Allow write access to rename files in this folder.');
      let taken = true;
      try { await parent.getFileHandle(target); }
      catch (problem) {
        if (problem.name !== 'NotFoundError') throw problem;
        taken = false;
      }
      if (taken) throw new Error('A file with that name is already in this folder.');
      await handle.move(target);
      path = [...parts.slice(0, -1), target].join('/');
    }
    cache.current.delete(item.path);
    setRefreshKey(value => value + 1);
    return path;
  };

  const readSaved = async (item, kind, marker) => {
    let text;
    if (server) {
      const response = await fetch(`${server.base}/api/${kind}/load?path=${encodeURIComponent(item.path)}`, { cache: 'no-store' });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Could not open this conversation.');
      text = data.text;
    } else {
      const parts = item.path.split('/');
      let handle = directory;
      for (const part of parts.slice(0, -1)) handle = await handle.getDirectoryHandle(part);
      const file = await (await handle.getFileHandle(parts[parts.length - 1])).getFile();
      if (file.size > 2 * 1024 * 1024) throw new Error('This conversation exceeds 2 MiB.');
      text = await file.text();
    }
    if (!text.startsWith(marker)) throw new Error('This is not a supported saved file.');
    return text;
  };

  return {
    snapshot, error, loading, stopped, chooseFolder, stop, saveNote, renameItem, openExternally,
    readChat: item => readSaved(item, 'chat', CHAT_MARKER),
    readWorksheet: item => readSaved(item, 'practice', WORKSHEET_MARKER),
    refresh: () => {
      cache.current = new Map();
      setRefreshKey(value => value + 1);
    },
    isServer: Boolean(server), hasFolder: Boolean(server || directory || dropboxToken),
    isDropbox: Boolean(!server && !directory && dropboxToken),
  };
}
