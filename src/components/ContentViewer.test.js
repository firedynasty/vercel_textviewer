import React from 'react';
import { createRoot } from 'react-dom/client';
import { act, Simulate } from 'react-dom/test-utils';
import ContentViewer from './ContentViewer';

jest.mock('pdfjs-dist', () => ({ GlobalWorkerOptions: {}, version: 'test' }));

test('the file reader formats TXT by default and supports switching back to plain text', async () => {
  global.IS_REACT_ACT_ENVIRONMENT = true;
  const container = document.createElement('div');
  document.body.appendChild(container);
  const root = createRoot(container);
  const source = 'PROJECT NOTES\n\n* First\n* Second\n\nhttps://example.com\n\n<img src=x onerror="alert(1)">';
  const previousFetch = global.fetch;
  global.fetch = jest.fn().mockResolvedValue({ text: async () => source });
  const headings = jest.fn();
  const file = { type: 'text', url: 'blob:note', key: 'note', originalName: 'note.txt' };
  try {
    await act(async () => root.render(<ContentViewer file={file} fontSize={18} onHeadingsExtracted={headings} />));
    expect(container.querySelector('.preview-markdown')).not.toBe(null);
    expect(container.querySelectorAll('.preview-markdown li')).toHaveLength(2);
    expect(container.querySelector('.preview-markdown img')).toBe(null);
    expect(container.querySelector('.preview-markdown h4').id).toBe('project-notes');
    expect(container.querySelector('.preview-markdown a').href).toBe('https://example.com/');
    expect(headings).toHaveBeenLastCalledWith([{ level: 4, text: 'PROJECT NOTES', id: 'project-notes' }]);
    act(() => Simulate.click(container.querySelector('.txt-md-toggle-btn')));
    expect(container.querySelector('.preview-text pre').textContent).toBe(source);
    act(() => Simulate.click(container.querySelector('.txt-md-toggle-btn')));
    expect(container.querySelector('.preview-markdown')).not.toBe(null);
  } finally {
    act(() => root.unmount());
    container.remove();
    global.fetch = previousFetch;
  }
});
