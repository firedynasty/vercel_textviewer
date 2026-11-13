import React from 'react';
import { marked } from 'marked';
import { formatTextAsMarkdown } from '../utils/textFormatting';
import { visibleChatMarkdown } from '../utils/chatUtils';

// Build React elements from Markdown tokens: raw HTML is text, never executable.
function safeLink(href) {
  return /^(https?:|mailto:)/i.test(href || '') ? href : undefined;
}

const NAMED_ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: '\u00a0' };

// marked escapes inline text and code spans for HTML output. React escapes its own
// output, so those entities have to be decoded here or they show up as "Here&#39;s".
export function decodeEntities(value) {
  if (typeof value !== 'string' || !value.includes('&')) return value;
  return value.replace(/&(#\d{1,7}|#[xX][\da-fA-F]{1,6}|[a-zA-Z][a-zA-Z\d]{1,31});/g, (entity, body) => {
    if (body[0] !== '#') return NAMED_ENTITIES[body.toLowerCase()] ?? entity;
    const code = body[1] === 'x' || body[1] === 'X' ? parseInt(body.slice(2), 16) : Number(body.slice(1));
    if (!Number.isInteger(code) || code <= 0 || code > 0x10ffff || (code >= 0xd800 && code <= 0xdfff)) return entity;
    return String.fromCodePoint(code);
  });
}

function inline(tokens = []) {
  return tokens.map((token, key) => {
    const children = token.tokens ? inline(token.tokens) : decodeEntities(token.text);
    switch (token.type) {
      case 'strong': return <strong key={key}>{children}</strong>;
      case 'em': return <em key={key}>{children}</em>;
      case 'del': return <del key={key}>{children}</del>;
      case 'codespan': return <code key={key}>{decodeEntities(token.text)}</code>;
      case 'br': return <br key={key} />;
      case 'link': return <a key={key} href={safeLink(token.href)} target="_blank" rel="noreferrer">{children}</a>;
      case 'image': return <span key={key}>[Image: {token.text || token.href}]</span>;
      default: return <React.Fragment key={key}>{children || decodeEntities(token.raw)}</React.Fragment>;
    }
  });
}

function blocks(tokens, headingId) {
  return tokens.map((token, key) => {
    switch (token.type) {
      case 'space': return null;
      case 'heading': return React.createElement(`h${token.depth}`, { key, id: headingId?.(token.text) }, inline(token.tokens));
      case 'paragraph': return <p key={key}>{inline(token.tokens)}</p>;
      case 'text': return <p key={key}>{token.tokens ? inline(token.tokens) : decodeEntities(token.text)}</p>;
      case 'code': return <pre key={key}><code>{token.text}</code></pre>;
      case 'blockquote': return <blockquote key={key}>{blocks(token.tokens, headingId)}</blockquote>;
      case 'hr': return <hr key={key} />;
      case 'list': {
        const Tag = token.ordered ? 'ol' : 'ul';
        return <Tag key={key} start={token.ordered ? token.start : undefined}>{token.items.map((item, index) => <li key={index}>{item.task && <input type="checkbox" checked={item.checked} readOnly aria-label="Task" />}{blocks(item.tokens, headingId)}</li>)}</Tag>;
      }
      case 'table': return <div className="feed-table" key={key}><table><thead><tr>{token.header.map((cell, index) => <th key={index}>{inline(cell.tokens)}</th>)}</tr></thead><tbody>{token.rows.map((row, index) => <tr key={index}>{row.map((cell, cellIndex) => <td key={cellIndex}>{inline(cell.tokens)}</td>)}</tr>)}</tbody></table></div>;
      default: return <p key={key}>{token.text || token.raw}</p>;  // html and unknown blocks keep marked's raw source
    }
  });
}

export default function FeedMarkdown({ text, plainText = false, headingId, className = 'feed-markdown' }) {
  const visible = visibleChatMarkdown(text);
  const source = plainText ? formatTextAsMarkdown(visible) : visible;
  return <div className={className}>{blocks(marked.lexer(source, { gfm: true, breaks: plainText }), headingId)}</div>;
}
