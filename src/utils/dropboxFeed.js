// Read a Dropbox app folder as feed items, in the shape scanDirectory and the local server produce.
import { feedType, NOTE_LIMIT, sortFeed } from './feedUtils';
import { CHAT_MARKER } from './chatUtils';
import { WORKSHEET_MARKER } from './worksheetUtils';

const API = 'https://api.dropboxapi.com/2';
const CONTENT = 'https://content.dropboxapi.com/2';
// Videos cost a request each and carry the most bytes, so the online feed leaves them out.
const SKIPPED = new Set(['video']);
const THUMBNAIL_SIZE = 'w1024h768';
const THUMBNAIL_BATCH = 25;
const PARALLEL = 6;

export class DropboxError extends Error {
  constructor(message, status) {
    super(message);
    this.name = 'DropboxError';
    this.status = status;
    this.expired = status === 401;
  }
}

async function failure(response) {
  const body = await response.text().catch(() => '');
  let detail = body.slice(0, 200);
  try {
    const parsed = JSON.parse(body);
    detail = parsed.error_summary || parsed.error_description || detail;
  } catch {
    // Dropbox returns plain text for some failures.
  }
  if (response.status === 401) return new DropboxError('Dropbox sign-in has expired.', 401);
  if (response.status === 429) return new DropboxError('Dropbox is rate limiting this app. Try again shortly.', 429);
  return new DropboxError(detail || `Dropbox request failed (${response.status}).`, response.status);
}

async function call(token, endpoint, body, signal) {
  const response = await fetch(`${API}/${endpoint}`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
    signal,
  });
  if (!response.ok) throw await failure(response);
  return response.json();
}

// Dropbox paths are absolute inside the app folder; feed items are relative to the scanned root.
function relativePath(displayPath, root) {
  const prefix = root ? `${root}/` : '/';
  return displayPath.startsWith(prefix) ? displayPath.slice(prefix.length) : displayPath.replace(/^\//, '');
}

export async function listFolder(token, root, signal) {
  const entries = [];
  let data = await call(token, 'files/list_folder', {
    path: root, recursive: true, include_deleted: false, include_non_downloadable_files: false, limit: 2000,
  }, signal);
  entries.push(...data.entries);
  while (data.has_more) {
    data = await call(token, 'files/list_folder/continue', { cursor: data.cursor }, signal);
    entries.push(...data.entries);
  }
  return { entries, cursor: data.cursor };
}

export async function downloadText(token, path, signal) {
  const response = await fetch(`${CONTENT}/files/download`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}`, 'Dropbox-API-Arg': JSON.stringify({ path }) },
    signal,
  });
  if (!response.ok) throw await failure(response);
  const blob = await response.blob();
  return { text: await blob.slice(0, NOTE_LIMIT).text(), truncated: blob.size > NOTE_LIMIT };
}

// One request covers 25 images, instead of a temporary link per file.
export async function thumbnails(token, paths, signal) {
  const found = new Map();
  for (let start = 0; start < paths.length; start += THUMBNAIL_BATCH) {
    const group = paths.slice(start, start + THUMBNAIL_BATCH);
    const data = await call(token, 'files/get_thumbnail_batch', {
      entries: group.map(path => ({ path, format: 'jpeg', size: THUMBNAIL_SIZE, mode: 'strict' })),
    }, signal);
    for (const entry of data.entries || []) {
      if (entry['.tag'] === 'success' && entry.thumbnail) {
        found.set(entry.metadata.path_display, `data:image/jpeg;base64,${entry.thumbnail}`);
      }
    }
  }
  return found;
}

export async function temporaryLink(token, path, signal) {
  const data = await call(token, 'files/get_temporary_link', { path }, signal);
  return data.link;
}

async function inParallel(jobs, limit, signal) {
  let next = 0;
  const workers = Array.from({ length: Math.min(limit, jobs.length) }, async () => {
    while (next < jobs.length) {
      if (signal?.aborted) return;
      await jobs[next++]();
    }
  });
  await Promise.all(workers);
}

// Build the feed for a Dropbox folder, reusing every cached note whose rev has not moved.
export async function scanDropbox(token, root, cache = new Map(), signal) {
  const { entries, cursor } = await listFolder(token, root, signal);
  const nextCache = new Map();
  const items = [];
  const notesToRead = [];
  const imagesToRender = [];
  const pdfsToLink = [];

  for (const entry of entries) {
    if (entry['.tag'] !== 'file') continue;
    const type = feedType(entry.name);
    if (!type || SKIPPED.has(type)) continue;
    const path = relativePath(entry.path_display, root);
    if (!path) continue;
    const item = {
      path,
      name: entry.name,
      type,
      rev: entry.rev,
      size: entry.size,
      modified: Date.parse(entry.client_modified || entry.server_modified) || 0,
      remote: entry.path_display,
    };
    const previous = cache.get(path);
    if (previous && previous.rev === entry.rev && (type !== 'pdf')) {
      items.push(previous);
      nextCache.set(path, previous);
      continue;
    }
    if (type === 'note') notesToRead.push(item);
    else if (type === 'image') imagesToRender.push(item);
    else if (type === 'pdf') pdfsToLink.push(item);
    items.push(item);
    nextCache.set(path, item);
  }

  await inParallel(notesToRead.map(item => async () => {
    try {
      const { text, truncated } = await downloadText(token, item.remote, signal);
      item.text = text;
      item.truncated = truncated;
      item.chat = text.startsWith(CHAT_MARKER);
      item.worksheet = text.startsWith(WORKSHEET_MARKER);
    } catch (error) {
      if (error.expired || signal?.aborted) throw error;
      item.error = 'Could not read this note from Dropbox.';
      item.text = '';
    }
  }), PARALLEL, signal);

  if (imagesToRender.length) {
    const rendered = await thumbnails(token, imagesToRender.map(item => item.remote), signal);
    for (const item of imagesToRender) item.url = rendered.get(item.remote) || '';
  }

  // Temporary links expire in about four hours, so they are fetched fresh rather than cached.
  await inParallel(pdfsToLink.map(item => async () => {
    try {
      item.url = await temporaryLink(token, item.remote, signal);
    } catch (error) {
      if (error.expired || signal?.aborted) throw error;
      item.error = 'Could not open this PDF from Dropbox.';
    }
  }), PARALLEL, signal);

  return { items: sortFeed(items), cache: nextCache, cursor };
}
