import React, { act } from 'react';
import { createRoot } from 'react-dom/client';
import { Simulate } from 'react-dom/test-utils';
import FeedViewer from './FeedViewer';
import useLiveFeed from '../hooks/useLiveFeed';
import { exampleWorksheet, parseWorksheet } from '../utils/worksheetUtils';

jest.mock('../hooks/useLiveFeed');
let root, container, state, originalFetch;
const button = text => [...container.querySelectorAll('button')].find(element => element.textContent === text);
const click = async element => act(async () => Simulate.click(element));
const change = (element, value) => act(() => Simulate.change(element, { target: { value } }));
const note = { path: 'fractions.txt', name: 'fractions.txt', type: 'note', modified: 1000, size: 30, text: 'I keep adding the denominators.' };

beforeEach(() => {
  global.IS_REACT_ACT_ENVIRONMENT = true;
  HTMLElement.prototype.scrollTo = jest.fn();
  HTMLDialogElement.prototype.showModal = jest.fn();
  HTMLDialogElement.prototype.close = jest.fn();
  window.__LOCAL_FEED__ = { base: '/_feed/test' };
  originalFetch = global.fetch;
  global.fetch = jest.fn(async url => ({ ok: true, json: async () => url.endsWith('/providers')
    ? { contextChat: 1, ollama: { ok: true, haveModel: true } } : exampleWorksheet() }));
  window.confirm = jest.fn().mockReturnValue(true);
  state = { snapshot: { folder: '/math', items: [note] }, hasFolder: true, isServer: true,
    refresh: jest.fn(), stop: jest.fn(), saveNote: jest.fn().mockResolvedValue('fractions-worksheet.md'), readWorksheet: jest.fn() };
  useLiveFeed.mockImplementation(() => state);
  container = document.createElement('div'); document.body.appendChild(container); root = createRoot(container);
  act(() => root.render(<FeedViewer onOpenReader={jest.fn()} />));
});
afterEach(() => { act(() => root.unmount()); container.remove(); delete window.__LOCAL_FEED__; global.fetch = originalFetch; });

test('example needs no AI request, checks answers, saves progress, and restores it from its feed card', async () => {
  await click(button('Practice worksheets ↗'));
  await click(button('Try fractions example'));
  expect(global.fetch.mock.calls.every(([url]) => url.endsWith('/providers'))).toBe(true);
  expect(container.querySelectorAll('.practice-problem')).toHaveLength(6);
  const input = container.querySelector('#practice-answer-1');
  change(input, '2/5');
  await act(async () => Simulate.submit(container.querySelector('.practice-problem form')));
  expect(container.querySelector('.practice-feedback').textContent).toContain('Not quite');
  change(input, '5/6');
  await act(async () => Simulate.submit(container.querySelector('.practice-problem form')));
  expect(container.querySelector('.practice-sheet-heading').textContent).toContain('1 of 6 correct');
  await click(button('Show worked steps'));
  expect(container.querySelector('.practice-solution').textContent).toContain('3/6 + 2/6');
  await click(button('Save worksheet'));
  const text = state.saveNote.mock.calls[0][1];
  expect(parseWorksheet(text).attempts[1]).toEqual({ value: '5/6', checked: true, revealed: true });
  state.readWorksheet.mockResolvedValue(text);
  state.snapshot = { folder: '/math', items: [{ ...note, path: 'fractions-worksheet.md', name: 'fractions-worksheet.md', text, worksheet: true }] };
  await click(container.querySelector('[aria-label="Close practice"]'));
  act(() => root.render(<FeedViewer onOpenReader={jest.fn()} />));
  await click(button('Continue practice'));
  expect(container.querySelector('#practice-answer-1').value).toBe('5/6');
  expect(container.querySelector('.practice-sheet-heading').textContent).toContain('1 of 6 correct');
});

test('generation forwards only checked sources and shows provider errors without replacing the draft', async () => {
  await click(button('Add note'));
  await click(button('Practise these notes ↗'));
  await act(async () => Simulate.submit(container.querySelector('.practice-settings form')));
  const request = global.fetch.mock.calls.find(([url]) => url.endsWith('/practice/generate'));
  expect(JSON.parse(request[1].body)).toMatchObject({ topic: 'Adding fractions with unlike denominators', count: 6,
    context: [{ path: 'fractions.txt', text: note.text }], messages: [] });
  expect(container.querySelectorAll('.practice-problem')).toHaveLength(6);
  global.fetch.mockImplementation(async () => ({ ok: false, json: async () => ({ error: 'Provider unavailable' }) }));
  await act(async () => Simulate.submit(container.querySelector('.practice-settings form')));
  expect(container.querySelector('.practice-error').textContent).toBe('Provider unavailable');
  expect(container.querySelectorAll('.practice-problem')).toHaveLength(6);
});

test('browser-only mode offers the example while AI generation is unavailable', async () => {
  delete window.__LOCAL_FEED__;
  act(() => root.render(<FeedViewer onOpenReader={jest.fn()} />));
  await click(button('Practice worksheets ↗'));
  expect(button('Generate practice').disabled).toBe(true);
  await click(button('Try fractions example'));
  expect(container.querySelectorAll('.practice-problem')).toHaveLength(6);
});
