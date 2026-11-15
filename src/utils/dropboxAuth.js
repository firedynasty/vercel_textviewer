// PKCE browser sign-in for Dropbox. No app secret is involved, which is why a browser may hold these.
export const generateCodeVerifier = () => {
  const array = new Uint8Array(64);
  crypto.getRandomValues(array);
  return btoa(String.fromCharCode(...array)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

export const generateCodeChallenge = async (verifier) => {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier));
  return btoa(String.fromCharCode(...new Uint8Array(digest))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

export function authorizeUrl({ appKey, redirectUri, challenge, offline }) {
  const params = new URLSearchParams({
    client_id: appKey,
    response_type: 'code',
    code_challenge: challenge,
    code_challenge_method: 'S256',
    redirect_uri: redirectUri,
    token_access_type: offline ? 'offline' : 'online',
  });
  return `https://www.dropbox.com/oauth2/authorize?${params}`;
}

async function tokenRequest(body) {
  const response = await fetch('https://api.dropboxapi.com/oauth2/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(body).toString(),
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok || !data.access_token) {
    throw new Error(data.error_description || data.error || `Dropbox sign-in failed (${response.status}).`);
  }
  return data;
}

export function exchangeCode({ appKey, redirectUri, code, verifier }) {
  return tokenRequest({
    code, grant_type: 'authorization_code', client_id: appKey, redirect_uri: redirectUri, code_verifier: verifier,
  });
}

// A refresh token has no expiry, so this is what spares a phone the sign-in screen on every reload.
export function refreshAccess({ appKey, refreshToken }) {
  return tokenRequest({ grant_type: 'refresh_token', refresh_token: refreshToken, client_id: appKey });
}

export async function revoke(token) {
  try {
    await fetch('https://api.dropboxapi.com/2/auth/token/revoke', {
      method: 'POST', headers: { Authorization: `Bearer ${token}` },
    });
  } catch {
    // Signing out locally matters more than the server acknowledging it.
  }
}
