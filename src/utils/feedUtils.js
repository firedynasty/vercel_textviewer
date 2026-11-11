export const NOTE_LIMIT = 256 * 1024;

export function feedType(name) {
  const extension = name.split('.').pop().toLowerCase();
  if (['txt', 'md', 'markdown'].includes(extension)) return 'note';
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

// Hold new ordering and content until the reader chooses to apply the update.
export function changedPaths(previous, next) {
  const before = new Map(previous.map(item => [item.path, revision(item)]));
  const after = new Map(next.map(item => [item.path, revision(item)]));
  return new Set([
    ...next.filter(item => before.get(item.path) !== revision(item)).map(item => item.path),
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
        } else if (type === 'note') {
          item = { ...metadata, text: await file.slice(0, NOTE_LIMIT).text(), truncated: file.size > NOTE_LIMIT };
        } else {
          item = { ...metadata, url: URL.createObjectURL(file) };
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
