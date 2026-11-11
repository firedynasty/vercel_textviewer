import React from 'react';
import { createRoot } from 'react-dom/client';
import { act, Simulate } from 'react-dom/test-utils';
import FeedViewer from './FeedViewer';
import FeedMarkdown from './FeedMarkdown';
import useLiveFeed from '../hooks/useLiveFeed';

jest.mock('../hooks/useLiveFeed');

let container, root, state;
const original = { path: 'note.txt', name: 'note.txt', type: 'note', modified: 1000, size: 11, text: 'First #test' };

beforeEach(() => {
  global.IS_REACT_ACT_ENVIRONMENT = true;
  HTMLElement.prototype.scrollTo = jest.fn();
  HTMLDialogElement.prototype.showModal = function () { this.open = true; };
  HTMLDialogElement.prototype.close = function () { this.open = false; };
  container = document.createElement('div');
  document.body.appendChild(container);
  root = createRoot(container);
  state = { snapshot: { folder: '/notes', items: [original] }, hasFolder: true, isServer: true, refresh: jest.fn(), stop: jest.fn() };
  useLiveFeed.mockImplementation(() => state);
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
});

function render() { act(() => root.render(<FeedViewer onOpenReader={jest.fn()} />)); }

test('holds changes while reading, applies them on request, and removes deleted files', () => {
  render();
  container.querySelector('.feed-scroll').scrollTop = 300;
  state = { ...state, snapshot: { folder: '/notes', items: [{ ...original, modified: 2000, text: 'Edited #test' }] } };
  render();
  expect(container.querySelector('.feed-note').textContent).toContain('First');
  expect(container.querySelector('.feed-update').textContent).toContain('1 change');
  act(() => Simulate.click(container.querySelector('.feed-update')));
  expect(container.querySelector('.feed-note').textContent).toContain('Edited');
  container.querySelector('.feed-scroll').scrollTop = 0;
  state = { ...state, snapshot: { folder: '/notes', items: [] } };
  render();
  expect(container.querySelectorAll('.feed-card')).toHaveLength(0);
});

test('updates automatically at the top and searches note contents', () => {
  render();
  state = { ...state, snapshot: { folder: '/notes', items: [original, { ...original, path: 'other.md', name: 'other.md', text: 'Other #work' }] } };
  render();
  expect(container.querySelectorAll('.feed-card')).toHaveLength(2);
  act(() => Simulate.change(container.querySelector('input[type="search"]'), { target: { value: '#work' } }));
  expect(container.querySelectorAll('.feed-card')).toHaveLength(1);
  expect(container.querySelector('.feed-card').textContent).toContain('other.md');
});

test('Markdown renders formatting while keeping raw HTML and javascript links inert', () => {
  act(() => root.render(<FeedMarkdown text={'# Title\n\n**Bold** and [bad](javascript:alert%281%29)\n\n<script>window.attacked=true</script>'} />));
  expect(container.querySelector('h1').textContent).toBe('Title');
  expect(container.querySelector('strong').textContent).toBe('Bold');
  expect(container.querySelector('script')).toBe(null);
  expect(container.querySelector('a').getAttribute('href')).toBe(null);
  expect(window.attacked).toBeUndefined();
});

test('TXT>MD starts on, formats plain-text notes, and reversibly toggles the original text', () => {
  const text = 'PROJECT NOTES\n\n**Plan**\n* First\n* Second\n\nLine one\nLine two\n\nhttps://example.com';
  state.snapshot.items = [{ ...original, text }];
  render();
  const toggle = container.querySelector('.feed-format-toggle');
  expect(toggle.getAttribute('aria-pressed')).toBe('true');
  expect(container.querySelector('.feed-note h4').textContent).toBe('PROJECT NOTES');
  expect(container.querySelector('.feed-note strong').textContent).toBe('Plan');
  expect(container.querySelectorAll('.feed-note li')).toHaveLength(2);
  expect(container.querySelector('.feed-note br')).not.toBe(null);
  expect(container.querySelector('.feed-note a').href).toBe('https://example.com/');
  act(() => Simulate.click(toggle));
  expect(toggle.getAttribute('aria-pressed')).toBe('false');
  expect(container.querySelector('.feed-plain-text').textContent).toBe(text);
  expect(container.querySelector('.feed-note strong')).toBe(null);
  // A saved update should respect the user's current display setting.
  state = { ...state, snapshot: { folder: '/notes', items: [{ ...original, modified: 2000, text: '**Updated**' }] } };
  render();
  expect(container.querySelector('.feed-plain-text').textContent).toBe('**Updated**');
  act(() => Simulate.click(toggle));
  expect(container.querySelector('.feed-note strong').textContent).toBe('Updated');
});

test('expanded notes follow TXT>MD and copying keeps the original source', async () => {
  const text = '# Heading\n\n* First\n* Second';
  state.snapshot.items = [{ ...original, text }];
  navigator.clipboard = { writeText: jest.fn().mockResolvedValue() };
  render();
  await act(async () => Simulate.click([...container.querySelectorAll('.feed-card-actions button')].find(button => button.textContent === 'Copy')));
  expect(navigator.clipboard.writeText).toHaveBeenCalledWith(text);
  const expand = () => Simulate.click([...container.querySelectorAll('.feed-card-actions button')].find(button => button.textContent === 'Expand'));
  act(expand);
  expect(container.querySelector('dialog .feed-markdown h1').textContent).toBe('Heading');
  act(() => Simulate.click(container.querySelector('dialog button')));
  act(() => Simulate.click(container.querySelector('.feed-format-toggle')));
  act(expand);
  expect(container.querySelector('dialog .feed-plain-text').textContent).toBe(text);
});
