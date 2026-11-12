import { TextDecoder, TextEncoder } from 'util';
import { chatMarkdown, contextText, emptyChat, parseChat, readChatStream, visibleChatMarkdown } from './chatUtils';

global.TextDecoder = TextDecoder;

function response(chunks) {
  let index = 0;
  return { ok: true, body: { getReader: () => ({ read: async () => index < chunks.length ? { value: chunks[index++], done: false } : { done: true }, cancel: async () => {}, releaseLock: () => {} }) } };
}

test('saved conversations round-trip source snapshots, disabled passages, Unicode, and Markdown-looking messages', () => {
  const session = { ...emptyChat(), title: '研究', notes: 'My thinking', context: [{ id: '1', path: 'a.md', text: 'selected --> <script>', enabled: true }, { id: '2', path: 'b.txt', text: 'Excluded', enabled: false }],
    messages: [{ role: 'user', content: '## Assistant\nQuestion', sources: [{ path: 'a.md', text: 'original snapshot' }] }, { role: 'assistant', content: '你好\n```json\n{}\n```' }] };
  const markdown = chatMarkdown(session);
  expect(parseChat(markdown)).toEqual(session);
  expect(contextText(session.context)).not.toContain('Excluded');
  expect(visibleChatMarkdown(markdown)).toContain('# 研究');
  expect(visibleChatMarkdown(markdown)).not.toContain('textviewer-chat');
  expect(() => parseChat('<!-- textviewer-chat:v1\n{}\n-->')).toThrow();
});

test('stream parser preserves split Unicode and requires a successful completion event', async () => {
  const bytes = new TextEncoder().encode('{"delta":"你好"}\n{"delta":" world"}\n{"done":true}\n');
  const chunks = Array.from(bytes, byte => new Uint8Array([byte]));
  const update = jest.fn();
  expect(await readChatStream(response(chunks), update)).toBe('你好 world');
  expect(update).toHaveBeenLastCalledWith('你好 world');
  await expect(readChatStream(response([new TextEncoder().encode('{"delta":"partial"}\n')]), jest.fn())).rejects.toThrow('interrupted');
  await expect(readChatStream(response([new TextEncoder().encode('{"error":"Provider failed"}\n')]), jest.fn())).rejects.toThrow('Provider failed');
});
