export const NOTE_LIMIT = 256 * 1024;

export function feedType(name) {
  const extension = name.split('.').pop().toLowerCase();
  if (['txt', 'md', 'markdown', 'docx'].includes(extension)) return 'note';
  if (extension === 'pdf') return 'pdf';
  if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg'].includes(extension)) return 'image';
  if (['mp4', 'webm', 'mov', 'm4v', 'ogv'].includes(extension)) return 'video';
  return null;
}

export function revision(item) {
  return `${item.modified}:${item.size}`;
}

export function sortFeed(items) {
  return [...items].sort((a, b) => b.modified - a.modified || a.path.localeCompare(b.path));
}

// Sort the visible feed: newest save first, or by the filename shown in each card header.
export function sortItems(items, mode) {
  return mode === 'name'
    ? [...items].sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' }) || a.path.localeCompare(b.path))
    : items;
}

// Decide the filename a rename should produce, or explain why it cannot.
export function renameTarget(currentName, input) {
  const target = (input || '').trim();
  const suffix = currentName.slice(currentName.lastIndexOf('.'));
  if (!target) throw new Error('Choose a new filename.');
  if (/[/\\]/.test(target)) throw new Error('Choose a filename without folders.');
  if (target.startsWith('.')) throw new Error('Choose a filename that does not start with a dot.');
  if (/[\u0000-\u001f\u007f]/.test(target)) throw new Error('Choose a filename without control characters.');
  const named = /\.[^.]+$/.test(target) ? target : target + suffix;
  if (named.length > 200) throw new Error('Choose a shorter filename.');
  if (named.slice(named.lastIndexOf('.')).toLowerCase() !== suffix.toLowerCase()) {
    throw new Error(`Keep the ${suffix} extension so the feed can read this file.`);
  }
  return named;
}

// Hold new ordering and content until the reader chooses to apply the update.
export function changedPaths(previous, next) {
  // Retrying an unavailable document can change its error without a new save.
  const signature = item => `${revision(item)}:${item.error || ''}`;
  const before = new Map(previous.map(item => [item.path, signature(item)]));
  const after = new Map(next.map(item => [item.path, signature(item)]));
  return new Set([
    ...next.filter(item => before.get(item.path) !== signature(item)).map(item => item.path),
    ...previous.filter(item => !after.has(item.path)).map(item => item.path),
  ]);
}

export function filterFeed(items, query, type) {
  const words = query.trim().toLowerCase().split(/\s+/).filter(Boolean);
  return items.filter(item => {
    if (type !== 'all' && item.type !== type) return false;
    const searchable = `${item.path}\n${item.text || ''}`.toLowerCase();
    return words.every(word => searchable.includes(word));
  });
}

export function isDocxNote(item) {
  return item.type === 'note' && /\.docx$/i.test(item.name);
}

async function readDocxNote(readBuffer) {
  try {
    // DOCX is a ZIP archive: read the whole file before limiting extracted text.
    const arrayBuffer = await readBuffer();
    const mammoth = await import('mammoth');
    const { value } = await mammoth.extractRawText({ arrayBuffer });
    return { text: value.slice(0, NOTE_LIMIT), truncated: value.length > NOTE_LIMIT };
  } catch (error) {
    if (error.name === 'AbortError') throw error;
    return { text: '', error: 'Could not read this DOCX file. It may be damaged, encrypted, or unavailable.' };
  }
}

// The local server lists DOCX metadata; extract its text in the browser too.
export async function loadServerNotes(items, cache = new Map(), signal) {
  const nextCache = new Map();
  const loaded = [];
  for (const item of items) {
    if (!isDocxNote(item)) {
      loaded.push(item);
      continue;
    }
    const previous = cache.get(item.path);
    const note = previous && revision(previous) === revision(item) ? previous : {
      ...item,
      ...await readDocxNote(async () => {
        const response = await fetch(item.url, { signal, cache: 'no-store' });
        if (!response.ok) throw new Error(`Could not load DOCX (${response.status}).`);
        return response.arrayBuffer();
      }),
    };
    nextCache.set(item.path, note);
    loaded.push(note);
  }
  return { items: loaded, cache: nextCache };
}

export async function scanDirectory(handle, recursive, cache = new Map()) {
  const nextCache = new Map();
  const items = [];
  const walk = async (directory, prefix = '') => {
    for await (const [name, entry] of directory.entries()) {
      if (name.startsWith('.') || ['node_modules', '__pycache__'].includes(name)) continue;
      const path = prefix + name;
      if (entry.kind === 'directory') {
        if (recursive) await walk(entry, `${path}/`);
        continue;
      }
      const type = feedType(name);
      if (!type) continue;
      try {
        const file = await entry.getFile();
        const metadata = { path, name, type, modified: file.lastModified, size: file.size };
        const previous = cache.get(path);
        let item;
        if (previous && revision(previous) === revision(metadata)) {
          item = previous;
        } else if (isDocxNote(metadata)) {
          item = { ...metadata, url: URL.createObjectURL(file), ...await readDocxNote(() => file.arrayBuffer()) };
        } else if (type === 'note') {
          item = { ...metadata, text: await file.slice(0, NOTE_LIMIT).text(), truncated: file.size > NOTE_LIMIT };
        } else {
          // A folder handle may omit MIME types; PDFs should open in the browser.
          const source = type === 'pdf' ? new Blob([file], { type: 'application/pdf' }) : file;
          item = { ...metadata, url: URL.createObjectURL(source) };
        }
        nextCache.set(path, item);
        items.push(item);
      } catch (error) {
        // A file may disappear during a scan. Permission errors need attention.
        if (error.name !== 'NotFoundError') throw error;
      }
    }
  };
  await walk(handle);
  return { items: sortFeed(items), cache: nextCache };
}
