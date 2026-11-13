import React, { act } from 'react';
import { createRoot } from 'react-dom/client';
import FeedMarkdown from './FeedMarkdown';
import { decodeEntities } from './FeedMarkdown';

let container, root;

beforeEach(() => {
  global.IS_REACT_ACT_ENVIRONMENT = true;
  container = document.createElement('div');
  document.body.appendChild(container);
  root = createRoot(container);
});
afterEach(() => { act(() => root.unmount()); container.remove(); });

const render = (text, props = {}) => act(() => root.render(<FeedMarkdown text={text} {...props} />));

test('quotes, apostrophes, and entities reach the page as characters, not escape sequences', () => {
  render('Here\'s the "cleaned-up" version & more.\n\nGoing from "how big" to `a & b`, 5 < 6, plus &amp; and &#8594;.\n');
  const text = container.textContent;
  expect(text).toContain('Here\'s the "cleaned-up" version & more.');
  expect(text).toContain('Going from "how big" to a & b, 5 < 6, plus & and →.');
  expect(text).not.toMatch(/&(#\d+|amp|quot|lt|gt);/);
  expect(container.querySelector('code').textContent).toBe('a & b');
});

test('tables render as tables with decoded cells, and fenced code keeps its source', () => {
  render('| Connection | Time |\n| --- | --- |\n| 1 Gb/s (fiber) | **8 seconds** |\n| ISP\'s "up to" | 8,000 ÷ 1,000 |\n\n```\nlet a = "x" & 1;\n```\n');
  expect(container.querySelectorAll('.feed-table table')).toHaveLength(1);
  expect([...container.querySelectorAll('th')].map(cell => cell.textContent)).toEqual(['Connection', 'Time']);
  const cells = [...container.querySelectorAll('td')].map(cell => cell.textContent);
  expect(cells).toEqual(['1 Gb/s (fiber)', '8 seconds', 'ISP\'s "up to"', '8,000 ÷ 1,000']);
  expect(container.querySelector('td strong').textContent).toBe('8 seconds');
  expect(container.querySelector('pre code').textContent).toBe('let a = "x" & 1;');
});

test('markup in a note stays visible text and is never executed', () => {
  render('A <script>alert(1)</script> tag and <b>bold</b> markup.');
  expect(container.querySelector('script')).toBeNull();
  expect(container.querySelector('b')).toBeNull();
  expect(container.textContent).toContain('<script>alert(1)</script>');
  expect(decodeEntities('&unknown; &#xZZ; &#55296; kept')).toBe('&unknown; &#xZZ; &#55296; kept');
});
