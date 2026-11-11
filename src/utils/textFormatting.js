// Adapt the Drive viewer's plain-text formatting for Markdown rendering.
// Only the display changes; callers keep the original text for copying/editing.
export function formatTextAsMarkdown(text) {
  const lines = text.replace(/\r\n?/g, '\n').replace(/[\u200C\u200B\u00AD\uFEFF]/g, '').split('\n');
  let fence = null;
  return lines.map(line => {
    const marker = line.match(/^ {0,3}(`{3,}|~{3,})(.*)$/);
    if (fence) {
      if (marker && marker[1][0] === fence.character && marker[1].length >= fence.length && !marker[2].trim()) fence = null;
      return line;
    }
    if (marker) {
      fence = { character: marker[1][0], length: marker[1].length };
      return line;
    }
    // Keep indented code intact; ALL CAPS section labels become small headings.
    if (!/^( {4}|\t)/.test(line) && /^[A-Z][A-Z\s\d,&'"-]{10,}$/.test(line.trim())) {
      return `#### ${line.trim()}`;
    }
    return line;
  }).join('\n');
}
