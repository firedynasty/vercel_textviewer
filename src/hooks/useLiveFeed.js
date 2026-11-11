import { useCallback, useEffect, useRef, useState } from 'react';
import { loadServerNotes, scanDirectory, sortFeed } from '../utils/feedUtils';

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

  return {
    snapshot, error, loading, stopped, chooseFolder, stop,
    refresh: () => {
      cache.current = new Map();
      setRefreshKey(value => value + 1);
    },
    isServer: Boolean(server), hasFolder: Boolean(server || directory),
  };
}
