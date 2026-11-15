import { useCallback, useEffect, useRef, useState } from 'react';
import { authorizeUrl, exchangeCode, generateCodeChallenge, generateCodeVerifier, refreshAccess, revoke } from '../utils/dropboxAuth';
import { clearCache } from '../utils/dropboxCache';

const APP_KEY = process.env.REACT_APP_DROPBOX_FEED_APP_KEY;
const REDIRECT_URI = window.location.origin;
const REFRESH_KEY = 'dropbox-feed-refresh';
const VERIFIER_KEY = 'dropbox-feed-verifier';
export const RETURN_KEY = 'dropbox-feed-return';

// The app folder is this app's whole universe, so the feed always scans from its root
// and the breadcrumb narrows from there.
export const FEED_ROOT = '';

export default function useDropboxFeed() {
  const [token, setToken] = useState(null);
  const [error, setError] = useState('');
  const [restoring, setRestoring] = useState(() => Boolean(APP_KEY)
    && (Boolean(localStorage.getItem(REFRESH_KEY)) || new URLSearchParams(window.location.search).has('code')));
  const refreshToken = useRef(localStorage.getItem(REFRESH_KEY));
  const started = useRef(false);

  const store = useCallback(data => {
    if (data.refresh_token) {
      refreshToken.current = data.refresh_token;
      try { localStorage.setItem(REFRESH_KEY, data.refresh_token); } catch { /* private window */ }
    }
    setToken(data.access_token);
  }, []);

  // Sign-in returns to the bare origin, so the feed view and the open subfolder are restored here.
  useEffect(() => {
    if (started.current) return;
    started.current = true;
    if (!APP_KEY) return;
    const params = new URLSearchParams(window.location.search);
    const code = params.get('code');
    const verifier = sessionStorage.getItem(VERIFIER_KEY);
    const restoreUrl = () => {
      const saved = sessionStorage.getItem(RETURN_KEY) || '';
      sessionStorage.removeItem(RETURN_KEY);
      window.history.replaceState({}, document.title, REDIRECT_URI + saved);
    };

    if (code && verifier) {
      sessionStorage.removeItem(VERIFIER_KEY);
      restoreUrl();
      exchangeCode({ appKey: APP_KEY, redirectUri: REDIRECT_URI, code, verifier })
        .then(store)
        .catch(problem => setError(problem.message))
        .finally(() => setRestoring(false));
      return;
    }
    if (code) restoreUrl();
    if (!refreshToken.current) { setRestoring(false); return; }
    refreshAccess({ appKey: APP_KEY, refreshToken: refreshToken.current })
      .then(store)
      .catch(() => {
        // A revoked or stale refresh token simply means signing in again.
        refreshToken.current = null;
        try { localStorage.removeItem(REFRESH_KEY); } catch { /* private window */ }
      })
      .finally(() => setRestoring(false));
  }, [store]);

  const signIn = useCallback(async () => {
    if (!APP_KEY) {
      setError('Missing REACT_APP_DROPBOX_FEED_APP_KEY. Add it to .env.local and rebuild.');
      return;
    }
    try {
      const verifier = generateCodeVerifier();
      const challenge = await generateCodeChallenge(verifier);
      sessionStorage.setItem(VERIFIER_KEY, verifier);
      sessionStorage.setItem(RETURN_KEY, window.location.search || '?view=feed');
      window.location.href = authorizeUrl({ appKey: APP_KEY, redirectUri: REDIRECT_URI, challenge, offline: true });
    } catch (problem) {
      setError(problem.message);
    }
  }, []);

  const signOut = useCallback(async () => {
    if (token) await revoke(token);
    refreshToken.current = null;
    try { localStorage.removeItem(REFRESH_KEY); } catch { /* private window */ }
    await clearCache(FEED_ROOT);
    setToken(null);
    setError('');
  }, [token]);

  // An access token lasts about four hours; a scan that sees 401 asks for a new one.
  const renew = useCallback(async () => {
    if (!APP_KEY || !refreshToken.current) return null;
    const data = await refreshAccess({ appKey: APP_KEY, refreshToken: refreshToken.current });
    store(data);
    return data.access_token;
  }, [store]);

  return {
    token, error, restoring, signIn, signOut, renew,
    configured: Boolean(APP_KEY),
    signedIn: Boolean(token),
  };
}
