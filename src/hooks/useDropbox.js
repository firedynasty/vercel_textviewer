import { useState, useEffect, useCallback } from 'react';

const APP_KEY = process.env.REACT_APP_DROPBOX_APP_KEY;
const REDIRECT_URI = window.location.origin;

// PKCE helpers using crypto.subtle
const generateCodeVerifier = () => {
  const array = new Uint8Array(64);
  crypto.getRandomValues(array);
  return btoa(String.fromCharCode(...array))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');
};

const generateCodeChallenge = async (verifier) => {
  const encoder = new TextEncoder();
  const data = encoder.encode(verifier);
  const digest = await crypto.subtle.digest('SHA-256', data);
  return btoa(String.fromCharCode(...new Uint8Array(digest)))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');
};

export function useDropbox() {
  const [accessToken, setAccessToken] = useState(null);
  const [status, setStatus] = useState('');

  const isAuthenticated = !!accessToken;

  // Handle OAuth redirect on mount
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const code = params.get('code');

    if (code) {
      const verifier = sessionStorage.getItem('dropbox_code_verifier');
      if (verifier) {
        const body = new URLSearchParams({
          code,
          grant_type: 'authorization_code',
          client_id: APP_KEY,
          redirect_uri: REDIRECT_URI,
          code_verifier: verifier,
        });

        fetch('https://api.dropboxapi.com/oauth2/token', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
        })
          .then((res) => res.json())
          .then((data) => {
            if (data.access_token) {
              setAccessToken(data.access_token);
              setStatus('Signed in');
              sessionStorage.removeItem('dropbox_code_verifier');
              window.history.replaceState({}, document.title, REDIRECT_URI);
            } else {
              setStatus('Auth failed: ' + (data.error_description || data.error || 'Unknown error'));
            }
          })
          .catch((err) => setStatus('Auth error: ' + err.message));
      }
    }
  }, []);

  const signIn = useCallback(async () => {
    if (!APP_KEY) {
      setStatus('Missing REACT_APP_DROPBOX_APP_KEY');
      return;
    }
    const verifier = generateCodeVerifier();
    const challenge = await generateCodeChallenge(verifier);
    sessionStorage.setItem('dropbox_code_verifier', verifier);
    sessionStorage.setItem('dropbox_pending_browse', 'true');

    const authUrl = `https://www.dropbox.com/oauth2/authorize?client_id=${APP_KEY}&response_type=code&code_challenge=${challenge}&code_challenge_method=S256&redirect_uri=${encodeURIComponent(REDIRECT_URI)}&token_access_type=online`;
    window.location.href = authUrl;
  }, []);

  const signOut = useCallback(async () => {
    if (accessToken) {
      try {
        await fetch('https://api.dropboxapi.com/2/auth/token/revoke', {
          method: 'POST',
          headers: { Authorization: `Bearer ${accessToken}` },
        });
      } catch {
        // Ignore revoke errors
      }
      setAccessToken(null);
      setStatus('Signed out');
    }
  }, [accessToken]);

  const searchFolders = useCallback(async (query) => {
    if (!query.trim() || !accessToken) return [];

    setStatus('Searching folders...');
    try {
      const response = await fetch('https://api.dropboxapi.com/2/files/search_v2', {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${accessToken}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          query,
          options: {
            max_results: 10,
            file_categories: [{ '.tag': 'folder' }],
          },
        }),
      });

      if (!response.ok) throw new Error('Folder search failed');

      const data = await response.json();
      const folders = (data.matches || [])
        .map((m) => m.metadata?.metadata || m.metadata)
        .filter((m) => m['.tag'] === 'folder')
        .map((m) => ({
          name: m.name,
          path: m.path_lower || m.path_display,
        }));

      setStatus(`Found ${folders.length} folder(s)`);
      return folders;
    } catch (error) {
      setStatus('Error: ' + error.message);
      return [];
    }
  }, [accessToken]);

  const listFolder = useCallback(async (path) => {
    if (!accessToken) return [];

    setStatus('Loading folder...');
    try {
      let allEntries = [];
      let hasMore = true;
      let cursor = null;

      while (hasMore) {
        const url = cursor
          ? 'https://api.dropboxapi.com/2/files/list_folder/continue'
          : 'https://api.dropboxapi.com/2/files/list_folder';
        const body = cursor ? { cursor } : { path };

        const response = await fetch(url, {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${accessToken}`,
            'Content-Type': 'application/json',
          },
          body: JSON.stringify(body),
        });

        if (!response.ok) throw new Error('Failed to list folder');

        const data = await response.json();
        const entries = (data.entries || []).map((entry) => ({
          name: entry.name,
          path: entry.path_lower || entry.path_display,
          isFolder: entry['.tag'] === 'folder',
        }));

        allEntries = allEntries.concat(entries);
        hasMore = data.has_more;
        cursor = data.cursor;
      }

      // Sort: folders first, then files, alphabetical within each
      allEntries.sort((a, b) => {
        if (a.isFolder !== b.isFolder) return a.isFolder ? -1 : 1;
        return a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' });
      });

      setStatus(`Loaded ${allEntries.length} items`);
      return allEntries;
    } catch (error) {
      setStatus('Error: ' + error.message);
      return [];
    }
  }, [accessToken]);

  const downloadFile = useCallback(async (dropboxPath) => {
    if (!accessToken) return null;

    try {
      const response = await fetch('https://content.dropboxapi.com/2/files/download', {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${accessToken}`,
          'Dropbox-API-Arg': JSON.stringify({ path: dropboxPath }),
        },
      });

      if (!response.ok) throw new Error('Download failed');

      return await response.blob();
    } catch (error) {
      setStatus('Download error: ' + error.message);
      return null;
    }
  }, [accessToken]);

  return {
    accessToken,
    isAuthenticated,
    status,
    signIn,
    signOut,
    searchFolders,
    listFolder,
    downloadFile,
  };
}
