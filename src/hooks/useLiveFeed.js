import { useCallback, useEffect, useRef, useState } from 'react';
import { loadServerNotes, renameTarget, scanDirectory, sortFeed } from '../utils/feedUtils';
import { CHAT_MARKER, hasControlCharacters } from '../utils/chatUtils';
import { WORKSHEET_MARKER } from '../utils/worksheetUtils';

export default function useLiveFeed(recursive, retainedItems) {
  const server = window.__LOCAL_FEED__;
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
    if ((!server && !directory) || stopped) return;
    let cancelled = false;
    let timer;
    const controller = new AbortController();
    setLoading(true);
    const scan = async () => {
      try {
        let next;
        if (server) {
          const response = await fetch(`${server.base}/api/feed?recursive=${recursive ? '1' : '0'}`, {
            signal: controller.signal, cache: 'no-store',
          });
          if (!response.ok) throw new Error(`Folder unavailable (${response.status}). Reopen it with the feed launcher.`);
          next = await response.json();
          const result = await loadServerNotes(next.items, cache.current, controller.signal);
          if (cancelled) return;
          cache.current = result.cache;
          next.items = sortFeed(result.items);
        } else {
          const result = await scanDirectory(directory, recursive, cache.current);
          for (const item of result.items) if (item.url) allocatedUrls.current.add(item.url);
          if (cancelled) return;
          cache.current = result.cache;
          next = { folder: directory.name, items: result.items };
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
          timer = setTimeout(scan, 2000);
        }
      }
    };
    scan();
    return () => {
      cancelled = true;
      clearTimeout(timer);
      controller.abort();
    };
  }, [directory, recursive, refreshKey, stopped, server]);

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
    snapshot, error, loading, stopped, chooseFolder, stop, saveNote, renameItem,
    readChat: item => readSaved(item, 'chat', CHAT_MARKER),
    readWorksheet: item => readSaved(item, 'practice', WORKSHEET_MARKER),
    refresh: () => {
      cache.current = new Map();
      setRefreshKey(value => value + 1);
    },
    isServer: Boolean(server), hasFolder: Boolean(server || directory),
  };
}
