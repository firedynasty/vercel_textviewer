import JSZip from 'jszip';
import { TextDecoder } from 'util';
import { changedPaths, feedType, filterFeed, loadServerNotes, NOTE_LIMIT, scanDirectory, sortFeed } from './feedUtils';

// Exercise the same real parser used by Webpack rather than Mammoth's Node I/O.
jest.mock('mammoth', () => jest.requireActual('mammoth/mammoth.browser'));
global.TextDecoder = TextDecoder;

const note = { path: 'daily.txt', name: 'daily.txt', type: 'note', modified: 100, size: 12, text: 'Practice #basketball' };

test('orders recent saves first and searches note contents and hashtags', () => {
  const image = { path: 'court.png', type: 'image', modified: 200, size: 50 };
  expect(sortFeed([note, image])).toEqual([image, note]);
  expect(filterFeed([note, image], '#basketball practice', 'all')).toEqual([note]);
  expect(filterFeed([note, image], 'court', 'note')).toEqual([]);
  expect(feedType('PHOTO.AVIF')).toBe('image');
  expect(feedType('clip.webm')).toBe('video');
  expect(feedType('Practice.DOCX')).toBe('note');
  expect(feedType('Reference.PDF')).toBe('pdf');
  expect(feedType('code.js')).toBe(null);
});

test('tracks additions, edits, and deletions without treating reordering as changes', () => {
  const image = { path: 'court.png', modified: 200, size: 50 };
  expect([...changedPaths([note, image], [image, note])]).toEqual([]);
  expect(changedPaths([note, image], [{ ...note, modified: 101 }, { path: 'new.md', modified: 300, size: 4 }])).toEqual(new Set(['daily.txt', 'court.png', 'new.md']));
  expect(changedPaths([{ ...note, error: 'Unavailable' }], [note])).toEqual(new Set(['daily.txt']));
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

async function docx(text) {
  const zip = new JSZip();
  zip.file('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
  zip.file('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
  zip.file('word/document.xml', `<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>${text}</w:t></w:r></w:p></w:body></w:document>`);
  return zip.generateAsync({ type: 'arraybuffer', compression: 'DEFLATE' });
}

test('browser folder DOCX notes expose searchable text and an original URL, cache it, and reload edits', async () => {
  URL.createObjectURL = jest.fn().mockReturnValue('blob:document');
  const buffer = await docx('Practice 你好 #basketball');
  const arrayBuffer = jest.fn().mockResolvedValue(buffer);
  const document = { kind: 'file', getFile: jest.fn().mockResolvedValue({ size: buffer.byteLength, lastModified: 100, arrayBuffer }) };
  const root = directory([['Practice.DOCX', document], ['other.txt', file('other.txt', 'Other')]]);
  const first = await scanDirectory(root, false);
  const notes = filterFeed(first.items, '#basketball 你好', 'note');
  expect(notes).toHaveLength(1);
  expect(notes[0]).toMatchObject({ type: 'note', text: 'Practice 你好 #basketball\n\n', url: 'blob:document', truncated: false });
  await scanDirectory(root, false, first.cache);
  expect(arrayBuffer).toHaveBeenCalledTimes(1);
  document.getFile.mockResolvedValue({ size: buffer.byteLength, lastModified: 200, arrayBuffer: () => docx('Edited') });
  const edited = await scanDirectory(root, false, first.cache);
  expect(edited.items[0].text).toBe('Edited\n\n');
});

test('local-server DOCX notes fetch binary once per revision, reload edits, and discard deleted cache entries', async () => {
  const buffer = await docx('Server #notes');
  global.fetch = jest.fn().mockResolvedValue({ ok: true, arrayBuffer: () => Promise.resolve(buffer) });
  try {
    const metadata = { ...note, path: 'note.docx', name: 'note.docx', url: '/files/note.docx?v=1' };
    const signal = new AbortController().signal;
    const first = await loadServerNotes([metadata, note], new Map(), signal);
    expect(first.items[0].text).toBe('Server #notes\n\n');
    expect(first.items[1]).toBe(note);
    expect(fetch).toHaveBeenCalledWith(metadata.url, { signal, cache: 'no-store' });
    const second = await loadServerNotes([metadata], first.cache, signal);
    expect(second.items[0]).toBe(first.items[0]);
    expect(fetch).toHaveBeenCalledTimes(1);
    await loadServerNotes([{ ...metadata, modified: 200 }], second.cache, signal);
    expect(fetch).toHaveBeenCalledTimes(2);
    expect((await loadServerNotes([], second.cache)).cache.size).toBe(0);
  } finally { delete global.fetch; }
});

test('DOCX preview limits apply to extracted text, and unreadable files do not interrupt other notes', async () => {
  URL.createObjectURL = jest.fn().mockReturnValue('blob:document');
  const buffer = await docx('x'.repeat(NOTE_LIMIT + 20));
  expect(buffer.byteLength).toBeLessThan(NOTE_LIMIT);
  const root = directory([
    ['large.docx', { kind: 'file', getFile: async () => ({ size: buffer.byteLength, lastModified: 100, arrayBuffer: async () => buffer }) }],
    ['broken.docx', { kind: 'file', getFile: async () => ({ size: 0, lastModified: 100, arrayBuffer: async () => new ArrayBuffer(0) }) }],
    ['other.txt', file('other.txt', 'Still available')],
  ]);
  const { items } = await scanDirectory(root, false);
  const large = items.find(item => item.name === 'large.docx');
  expect(large.truncated).toBe(true);
  expect(large.text.length).toBe(NOTE_LIMIT);
  expect(items.find(item => item.name === 'broken.docx')).toMatchObject({ text: '', error: expect.stringContaining('Could not read this DOCX') });
  expect(items.find(item => item.name === 'other.txt').text).toBe('Still available');
});

test('failed DOCX requests show a per-file warning and cancelled requests stay cancelled', async () => {
  const metadata = { ...note, path: 'note.docx', name: 'note.docx', url: '/files/note.docx' };
  global.fetch = jest.fn().mockResolvedValue({ ok: false, status: 404 });
  try {
    const failed = await loadServerNotes([metadata, note]);
    expect(failed.items[0].error).toContain('Could not read this DOCX');
    expect(failed.items[1]).toBe(note);
    const error = new DOMException('Cancelled', 'AbortError');
    fetch.mockRejectedValue(error);
    await expect(loadServerNotes([metadata])).rejects.toBe(error);
  } finally { delete global.fetch; }
});

test('PDF folder entries keep their path and a PDF-typed URL without reading or parsing their contents', async () => {
  URL.createObjectURL = jest.fn().mockReturnValue('blob:pdf');
  const pdf = new File(['%PDF-1.4'], 'guide.PDF', { lastModified: 100 });
  pdf.arrayBuffer = jest.fn();
  pdf.text = jest.fn();
  const handle = { kind: 'file', getFile: async () => pdf };
  const root = directory([['references', directory([['guide.PDF', handle]])]]);
  expect((await scanDirectory(root, false)).items).toEqual([]);
  const first = await scanDirectory(root, true);
  expect(first.items[0]).toMatchObject({ path: 'references/guide.PDF', type: 'pdf', url: 'blob:pdf' });
  expect(first.items[0].text).toBeUndefined();
  expect(URL.createObjectURL.mock.calls[0][0].type).toBe('application/pdf');
  expect(pdf.arrayBuffer).not.toHaveBeenCalled();
  expect(pdf.text).not.toHaveBeenCalled();
  expect((await scanDirectory(root, true, first.cache)).items[0]).toBe(first.items[0]);
  expect(URL.createObjectURL).toHaveBeenCalledTimes(1);
  expect(filterFeed(first.items, 'references guide', 'pdf')).toEqual(first.items);
  expect(filterFeed(first.items, '', 'note')).toEqual([]);
  expect((await loadServerNotes(first.items)).items[0]).toBe(first.items[0]);
});
