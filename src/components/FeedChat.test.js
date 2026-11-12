import React, { act } from 'react';
import { createRoot } from 'react-dom/client';
import { Simulate } from 'react-dom/test-utils';
import { TextDecoder, TextEncoder } from 'util';
import FeedViewer from './FeedViewer';
import useLiveFeed from '../hooks/useLiveFeed';
import { parseChat } from '../utils/chatUtils';

jest.mock('../hooks/useLiveFeed');
global.TextDecoder = TextDecoder;
let container, root, state, originalFetch;
const notes = [
  { path: 'first.txt', name: 'first.txt', type: 'note', modified: 1000, size: 30, text: 'First selected passage. More text.' },
  { path: 'private.txt', name: 'private.txt', type: 'note', modified: 1000, size: 20, text: 'Leave this out.' },
];
const button = text => [...container.querySelectorAll('button')].find(item => item.textContent === text);
const click = async target => act(async () => Simulate.click(target));

beforeEach(() => {
  global.IS_REACT_ACT_ENVIRONMENT = true;
  HTMLElement.prototype.scrollTo = jest.fn();
  window.__LOCAL_FEED__ = { base: '/_feed/test' };
  originalFetch = global.fetch;
  global.fetch = jest.fn(async url => {
    if (url.endsWith('/providers')) return { ok: true, json: async () => ({ contextChat: 1, ollama: { ok: true, haveModel: true, model: 'test' }, openai: { configured: true } }) };
    let sent = false;
    return { ok: true, body: { getReader: () => ({
      read: async () => { if (sent) return { done: true }; sent = true; return { value: new TextEncoder().encode('{"delta":"Useful answer"}\n{"done":true}\n'), done: false }; },
      cancel: async () => {}, releaseLock: () => {},
    }) } };
  });
  navigator.clipboard = { writeText: jest.fn().mockResolvedValue() };
  state = { snapshot: { folder: '/my-notes', items: notes }, hasFolder: true, isServer: true, refresh: jest.fn(), stop: jest.fn(), saveNote: jest.fn().mockResolvedValue('Conversation-chat.md'), readChat: jest.fn() };
  useLiveFeed.mockImplementation(() => state);
  container = document.createElement('div'); document.body.appendChild(container); root = createRoot(container);
  act(() => root.render(<FeedViewer onOpenReader={jest.fn()} />));
});

afterEach(() => {
  act(() => root.unmount()); container.remove(); delete window.__LOCAL_FEED__; global.fetch = originalFetch; window.getSelection()?.removeAllRanges();
});

test('only checked passages are sent and copied; a saved conversation restores history and sources', async () => {
  await click(container.querySelectorAll('.feed-card-actions button')[2]);
  await click([...container.querySelectorAll('.feed-card')][1].querySelectorAll('.feed-card-actions button')[2]);
  const excluded = container.querySelectorAll('.feed-context-source input')[1];
  act(() => Simulate.change(excluded, { target: { checked: false } }));
  await click(button('Copy context'));
  expect(navigator.clipboard.writeText.mock.calls[0][0]).toContain(notes[0].text);
  expect(navigator.clipboard.writeText.mock.calls[0][0]).not.toContain(notes[1].text);
  act(() => Simulate.change(container.querySelector('.feed-chat-composer textarea'), { target: { value: 'Explain this' } }));
  await act(async () => Simulate.submit(container.querySelector('.feed-chat-composer')));
  const request = JSON.parse(global.fetch.mock.calls.find(([url]) => url.endsWith('/ask'))[1].body);
  expect(request.context).toEqual([{ path: notes[0].path, text: notes[0].text }]);
  expect(container.querySelector('.feed-chat-history').textContent).toContain('Useful answer');
  await click(button('Save chat'));
  const saved = state.saveNote.mock.calls[0];
  const session = parseChat(saved[1]);
  expect(session.messages).toHaveLength(2);
  expect(session.context[1].enabled).toBe(false);
  expect(session.messages[0].sources).toEqual(request.context);
  expect(container.querySelector('[role="status"]').textContent).toContain('/my-notes');
  await click(button('Save answer as note'));
  expect(state.saveNote.mock.calls[1][0]).toBe('Conversation-answer.md');
  expect(state.saveNote.mock.calls[1][1]).toContain('Useful answer');
  state.readChat.mockResolvedValue(saved[1]);
  state.snapshot = { ...state.snapshot, items: [{ ...notes[0], name: saved[0], path: saved[0], text: saved[1], chat: true }] };
  act(() => root.render(<FeedViewer onOpenReader={jest.fn()} />));
  await click(button('New chat'));
  await click([...container.querySelectorAll('.feed-card-actions button')].find(item => item.textContent === 'Copy'));
  expect(navigator.clipboard.writeText.mock.calls.at(-1)[0]).toContain('Useful answer');
  expect(navigator.clipboard.writeText.mock.calls.at(-1)[0]).not.toContain('textviewer-chat');
  expect(navigator.clipboard.writeText.mock.calls.at(-1)[0]).not.toContain('Leave this out.');
  await click(button('Continue chat'));
  expect(container.querySelectorAll('.feed-chat-message')).toHaveLength(2);
  expect(container.querySelectorAll('.feed-context-source')).toHaveLength(2);
});

test('a highlighted passage is collected separately from the whole note', async () => {
  const note = container.querySelector('.feed-note');
  const text = note.querySelector('p').firstChild;
  const range = document.createRange(); range.setStart(text, 0); range.setEnd(text, 5);
  window.getSelection().addRange(range);
  act(() => Simulate.mouseUp(note));
  await click(button('Add selection'));
  expect(container.querySelector('.feed-context-source textarea').value).toBe('First');
});
