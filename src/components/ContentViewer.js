import React, { useState, useEffect } from 'react';
import { marked } from 'marked';
import { rtfToPlainText, isRtfFile } from '../utils/fileUtils';

function ContentViewer({
  file,
  fontSize,
  isEditing,
  editContent,
  onEditChange,
  imagePathToBlobUrl,
  onPrev,
  onNext,
  onPlayFromSelection
}) {
  const [textContent, setTextContent] = useState('');
  const [markdownHtml, setMarkdownHtml] = useState('');
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!file || file.type === 'divider') return;

    if (file.type === 'text' || file.type === 'rtf' || file.type === 'markdown') {
      setLoading(true);
      fetch(file.url)
        .then(res => res.text())
        .then(text => {
          // Handle RTF files
          if (file.originalName && isRtfFile(file.originalName)) {
            text = rtfToPlainText(text);
          }

          if (file.type === 'markdown') {
            // Process markdown images to use blob URLs
            let processedText = text.replace(/!\[(.*?)\]\(([^)]+)\)/g, (match, alt, path) => {
              const encodedPath = path.replace(/ /g, '%20');
              return `![${alt}](${encodedPath})`;
            });

            const html = marked.parse(processedText);

            // Replace image src with blob URLs
            let processedHtml = html;
            if (imagePathToBlobUrl) {
              Object.keys(imagePathToBlobUrl).forEach(path => {
                const encodedPath = path.replace(/ /g, '%20');
                const regex = new RegExp(`src="${encodedPath}"`, 'g');
                processedHtml = processedHtml.replace(regex, `src="${imagePathToBlobUrl[path]}"`);

                // Also try without encoding
                const regex2 = new RegExp(`src="${path}"`, 'g');
                processedHtml = processedHtml.replace(regex2, `src="${imagePathToBlobUrl[path]}"`);
              });
            }

            setMarkdownHtml(processedHtml);
            setTextContent(text);
          } else {
            setTextContent(text);
          }
          setLoading(false);
        })
        .catch(err => {
          console.error('Error loading file:', err);
          setTextContent('Error loading file');
          setLoading(false);
        });
    }
  }, [file, imagePathToBlobUrl]);

  if (!file) {
    return (
      <div className="content-viewer empty">
        <div className="empty-message">
          <h2>Text Viewer</h2>
          <p>Drag and drop a folder to get started</p>
          <p className="supported-formats">
            Supports: .txt, .rtf, .md, .jpg, .png, .gif, .mp4, .webm
          </p>
        </div>
      </div>
    );
  }

  if (file.type === 'divider') {
    return (
      <div className="content-viewer divider-view">
        <div className="divider-content">
          <h1>{file.key}</h1>
        </div>
      </div>
    );
  }

  if (loading) {
    return (
      <div className="content-viewer loading">
        <div className="loading-message">Loading...</div>
      </div>
    );
  }

  return (
    <div className="content-viewer">
      <button className="nav-btn prev-btn" onClick={onPrev} aria-label="Previous">
        <svg width="64" height="64" viewBox="0 0 64 64">
          <path d="M44 8 L20 32 L44 56" stroke="white" strokeWidth="8" fill="none" strokeLinecap="round" strokeLinejoin="round"/>
        </svg>
      </button>

      <div className="content-area">
        {file.type === 'image' && (
          <img
            src={file.url}
            alt={file.key}
            className="preview-image"
          />
        )}

        {file.type === 'video' && (
          <video
            src={file.url}
            controls
            autoPlay
            loop
            muted
            className="preview-video"
          />
        )}

        {(file.type === 'text' || file.type === 'rtf') && !isEditing && (
          <div
            className="preview-text"
            style={{ fontSize: `${fontSize}px` }}
            onDoubleClick={() => {
              const selection = window.getSelection().toString();
              console.log('Double-click detected, selection:', selection);
              if (selection && onPlayFromSelection) {
                console.log('Calling onPlayFromSelection');
                onPlayFromSelection(selection);
              }
            }}
          >
            <div className="content-title">{file.key}</div>
            <pre>{textContent}</pre>
          </div>
        )}

        {(file.type === 'text' || file.type === 'rtf') && isEditing && (
          <textarea
            className="preview-edit"
            style={{ fontSize: `${fontSize}px` }}
            value={editContent}
            onChange={(e) => onEditChange(e.target.value)}
          />
        )}

        {file.type === 'markdown' && !isEditing && (
          <div
            className="preview-markdown"
            style={{ fontSize: `${fontSize}px` }}
            dangerouslySetInnerHTML={{ __html: `<div class="content-title">${file.key}</div>${markdownHtml}` }}
            onDoubleClick={() => {
              const selection = window.getSelection().toString();
              console.log('Double-click detected, selection:', selection);
              if (selection && onPlayFromSelection) {
                console.log('Calling onPlayFromSelection');
                onPlayFromSelection(selection);
              }
            }}
          />
        )}

        {file.type === 'markdown' && isEditing && (
          <textarea
            className="preview-edit"
            style={{ fontSize: `${fontSize}px` }}
            value={editContent}
            onChange={(e) => onEditChange(e.target.value)}
          />
        )}
      </div>

      <button className="nav-btn next-btn" onClick={onNext} aria-label="Next">
        <svg width="64" height="64" viewBox="0 0 64 64">
          <path d="M20 8 L44 32 L20 56" stroke="white" strokeWidth="8" fill="none" strokeLinecap="round" strokeLinejoin="round"/>
        </svg>
      </button>
    </div>
  );
}

export default ContentViewer;
