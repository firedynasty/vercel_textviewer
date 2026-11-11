import { formatTextAsMarkdown } from './textFormatting';

test('formats Drive-style section labels and normalizes pasted text', () => {
  const source = '\uFEFFPROJECT NOTES\r\n\r\n* First\r\n* Second\r\nhttps://exam\u200Bple.com';
  expect(formatTextAsMarkdown(source)).toBe('#### PROJECT NOTES\n\n* First\n* Second\nhttps://example.com');
  expect(source).toContain('\r\n');
});

test('preserves code blocks, regular headings, and inline formatting', () => {
  const source = '# Heading\n**Bold**\n\n```text\nPROJECT NOTES\n```\n\n~~~~\nPROJECT NOTES\n~~~\nPROJECT NOTES\n~~~~\n\n    PROJECT NOTES\n\tPROJECT NOTES';
  expect(formatTextAsMarkdown(source)).toBe(source);
});
