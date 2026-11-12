import { WORKSHEET_MARKER } from './worksheetUtils';

export const CHAT_MARKER = '<!-- textviewer-chat:v1\n';
export const CONTEXT_LIMIT = 60000;

export function hasControlCharacters(text) {
  return [...text].some(character => character.charCodeAt(0) < 32 || character.charCodeAt(0) === 127);
}

export function emptyChat() {
  return { version: 1, title: 'Conversation', provider: 'ollama', web: false, context: [], messages: [], notes: '' };
}

export function contextSize(context) {
  return context.filter(item => item.enabled !== false).reduce((sum, item) => sum + item.path.length + item.text.length, 0);
}

export function contextText(context) {
  return context.filter(item => item.enabled !== false).map(item => `Source: ${item.path}\n\n${item.text}`).join('\n\n---\n\n');
}

export function validateSession(value) {
  if (!value || value.version !== 1 || typeof value.title !== 'string' || typeof value.notes !== 'string' || !['ollama', 'openai'].includes(value.provider)
    || !Array.isArray(value.context) || !Array.isArray(value.messages)
    || value.context.some(item => !item || typeof item.id !== 'string' || typeof item.path !== 'string' || typeof item.text !== 'string')
    || value.messages.some(item => !item || !['user', 'assistant'].includes(item.role) || typeof item.content !== 'string'
      || (item.sources !== undefined && (!Array.isArray(item.sources) || item.sources.some(source => !source || typeof source.path !== 'string' || typeof source.text !== 'string'))))) {
    throw new Error('This file does not contain a supported saved conversation.');
  }
  return { ...value, web: value.web === true };
}

export function parseChat(text) {
  if (!text.startsWith(CHAT_MARKER)) throw new Error('This is not a saved folder conversation.');
  const end = text.indexOf('\n-->', CHAT_MARKER.length);
  if (end < 0) throw new Error('The saved conversation is incomplete.');
  try { return validateSession(JSON.parse(text.slice(CHAT_MARKER.length, end))); }
  catch { throw new Error('The saved conversation is damaged or uses an unsupported format.'); }
}

export function visibleChatMarkdown(text) {
  const marker = text.startsWith(CHAT_MARKER) ? CHAT_MARKER : text.startsWith(WORKSHEET_MARKER) ? WORKSHEET_MARKER : null;
  if (!marker) return text;
  const end = text.indexOf('\n-->', marker.length);
  // A feed preview can end inside large metadata; the full file remains reopenable.
  return end < 0 ? (marker === CHAT_MARKER ? 'Saved conversation. Use Continue chat to open it.' : 'Saved worksheet. Use Continue practice to open it.') : text.slice(end + 4).trimStart();
}

export function chatMarkdown(session) {
  validateSession(session);
  const metadata = JSON.stringify(session).replace(/</g, '\\u003c').replace(/>/g, '\\u003e');
  const log = session.messages.map(message => {
    const sources = message.role === 'user' && message.sources?.length
      ? `\n\nSources for this question: ${message.sources.map(item => item.path).join(', ')}` : '';
    return `### ${message.role === 'user' ? 'You' : 'Assistant'}\n\n${message.content}${sources}`;
  }).join('\n\n');
  return `${CHAT_MARKER}${metadata}\n-->\n\n# ${session.title.replace(/[\r\n]/g, ' ')}\n\nSaved: ${new Date().toISOString()}\n\n## Selected context\n\n${contextText(session.context) || '(No sources selected)'}\n\n## Conversation\n\n${log || '(No messages yet)'}${session.notes.trim() ? `\n\n## My notes\n\n${session.notes}` : ''}\n`;
}

export function noteFilename(title, suffix = 'chat') {
  const cleaned = [...title].map(character => hasControlCharacters(character) ? ' ' : character).join('');
  const name = cleaned.replace(/[<>:"/\\|?*]/g, ' ').replace(/\s+/g, ' ').replace(/^\.+/, '').trim().slice(0, 65) || 'Conversation';
  return `${name}-${suffix}.md`;
}

export async function readChatStream(response, onDelta) {
  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throw new Error(error.error || `Chat failed (${response.status}).`);
  }
  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '', text = '', complete = false;
  const consume = line => {
    if (!line.trim()) return;
    const event = JSON.parse(line);
    if (event.error) throw new Error(event.error);
    if (typeof event.delta === 'string') { text += event.delta; onDelta(text); }
    if (event.done === true) complete = true;
  };
  try {
    while (true) {
      const { value, done } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      const lines = buffer.split('\n');
      buffer = lines.pop();
      lines.forEach(consume);
    }
    buffer += decoder.decode();
    consume(buffer);
    if (!complete || !text.trim()) throw new Error('The answer was interrupted or empty. Retry your question.');
    return text;
  } finally { await reader.cancel().catch(() => {}); reader.releaseLock(); }
}
