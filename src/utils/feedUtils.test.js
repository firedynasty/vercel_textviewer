import { changedPaths, feedType, filterFeed, scanDirectory, sortFeed } from './feedUtils';

const note = { path: 'daily.txt', name: 'daily.txt', type: 'note', modified: 100, size: 12, text: 'Practice #basketball' };

test('orders recent saves first and searches note contents and hashtags', () => {
  const image = { path: 'court.png', type: 'image', modified: 200, size: 50 };
  expect(sortFeed([note, image])).toEqual([image, note]);
  expect(filterFeed([note, image], '#basketball practice', 'all')).toEqual([note]);
  expect(filterFeed([note, image], 'court', 'note')).toEqual([]);
  expect(feedType('PHOTO.AVIF')).toBe('image');
  expect(feedType('clip.webm')).toBe('video');
  expect(feedType('code.js')).toBe(null);
});

test('tracks additions, edits, and deletions without treating reordering as changes', () => {
  const image = { path: 'court.png', modified: 200, size: 50 };
  expect([...changedPaths([note, image], [image, note])]).toEqual([]);
  expect(changedPaths([note, image], [{ ...note, modified: 101 }, { path: 'new.md', modified: 300, size: 4 }])).toEqual(new Set(['daily.txt', 'court.png', 'new.md']));
});

function directory(entries) {
  return { kind: 'directory', entries: async function* () { yield* entries; } };
}

function file(name, content, modified = 100) {
  const text = jest.fn().mockResolvedValue(content);
  return { kind: 'file', getFile: jest.fn().mockResolvedValue({ name, lastModified: modified, size: content.length, slice: () => ({ text }) }), text };
}

test('rescans directory handles, caches unchanged text, and finds nested notes only when requested', async () => {
  const daily = file('daily.txt', 'First');
  const nested = file('nested.md', '# Second');
  const root = directory([['daily.txt', daily], ['.secret.txt', file('.secret.txt', 'secret')], ['nested', directory([['nested.md', nested]])]]);
  const first = await scanDirectory(root, false);
  expect(first.items.map(item => item.path)).toEqual(['daily.txt']);
  const second = await scanDirectory(root, true, first.cache);
  expect(second.items.map(item => item.path)).toContain('nested/nested.md');
  expect(daily.text).toHaveBeenCalledTimes(1);
  const changed = directory([['daily.txt', file('daily.txt', 'Edited', 200)]]);
  const third = await scanDirectory(changed, false, second.cache);
  expect(third.items).toHaveLength(1);
  expect(third.items[0].text).toBe('Edited');
});
