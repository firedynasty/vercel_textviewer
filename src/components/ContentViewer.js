import React, { useState, useEffect, useRef, useCallback } from 'react';
import { marked } from 'marked';
import { rtfToPlainText, isRtfFile } from '../utils/fileUtils';
import * as pdfjsLib from 'pdfjs-dist';

// Configure PDF.js worker - use unpkg to match the installed version
pdfjsLib.GlobalWorkerOptions.workerSrc = `//unpkg.com/pdfjs-dist@${pdfjsLib.version}/build/pdf.worker.min.js`;

function ContentViewer({
  file,
  fontSize,
  isEditing,
  editContent,
  onEditChange,
  imagePathToBlobUrl,
  onPrev,
  onNext,
  // PDF-specific props
  pdfState,
  onPdfStateChange,
  onPdfDocumentLoad
}) {
  const [textContent, setTextContent] = useState('');
  const [markdownHtml, setMarkdownHtml] = useState('');
  const [loading, setLoading] = useState(false);

  // PDF state
  const canvasRef = useRef(null);
  const thumbnailContainerRef = useRef(null);
  const pdfContainerRef = useRef(null);
  const [pdfDoc, setPdfDoc] = useState(null);

  // Load PDF document
  useEffect(() => {
    if (!file || file.type !== 'pdf') {
      setPdfDoc(null);
      if (onPdfDocumentLoad) onPdfDocumentLoad(null);
      return;
    }

    setLoading(true);
    pdfjsLib.getDocument(file.url).promise.then(pdf => {
      setPdfDoc(pdf);
      if (onPdfDocumentLoad) onPdfDocumentLoad(pdf);
      onPdfStateChange({
        totalPages: pdf.numPages,
        currentPage: 1,
        scale: pdfState?.scale || 2.1,
        thumbnailMode: false,
        rotation: pdfState?.rotation || 0
      });
      setLoading(false);
    }).catch(err => {
      console.error('Error loading PDF:', err);
      setLoading(false);
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [file, onPdfDocumentLoad]);

  // Render current PDF page
  useEffect(() => {
    if (!pdfDoc || !canvasRef.current || !pdfState || pdfState.thumbnailMode) return;

    const renderPage = async () => {
      const page = await pdfDoc.getPage(pdfState.currentPage);
      const viewport = page.getViewport({
        scale: pdfState.scale,
        rotation: pdfState.rotation || 0
      });

      const canvas = canvasRef.current;
      const context = canvas.getContext('2d');
      canvas.width = viewport.width;
      canvas.height = viewport.height;

      await page.render({
        canvasContext: context,
        viewport: viewport
      }).promise;

      // Scroll to top when page changes
      if (pdfContainerRef.current) {
        pdfContainerRef.current.scrollTo({ top: 0, left: 0, behavior: 'smooth' });
      }
    };

    renderPage();
  }, [pdfDoc, pdfState]);

  // Keyboard handler for PDF - spacebar scrolls down, then next page at bottom
  useEffect(() => {
    if (!file || file.type !== 'pdf' || !pdfState || pdfState.thumbnailMode) return;

    const handleKeyDown = (e) => {
      // Only handle if not in an input field
      if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return;

      if (e.key === ' ' || e.code === 'Space') {
        e.preventDefault(); // Prevent default page scroll

        const container = pdfContainerRef.current;
        if (!container) return;

        const scrollTop = container.scrollTop;
        const scrollHeight = container.scrollHeight;
        const clientHeight = container.clientHeight;
        const scrollAmount = clientHeight * 0.85; // Scroll 85% of visible height
        const bottomThreshold = 10; // pixels from bottom to consider "at bottom"

        // Check if we're at or very near the bottom
        const isAtBottom = scrollTop + clientHeight >= scrollHeight - bottomThreshold;

        if (isAtBottom) {
          // At bottom - go to next page if available
          if (pdfState.currentPage < pdfState.totalPages) {
            onPdfStateChange({ ...pdfState, currentPage: pdfState.currentPage + 1 });
          }
        } else {
          // Not at bottom - scroll down
          container.scrollBy({ top: scrollAmount, behavior: 'smooth' });
        }
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [file, pdfState, onPdfStateChange]);

  // Generate thumbnails
  const generateThumbnails = useCallback(async () => {
    if (!pdfDoc || !thumbnailContainerRef.current) return;

    thumbnailContainerRef.current.innerHTML = '';
    const thumbScale = 0.3;

    for (let pageNum = 1; pageNum <= pdfDoc.numPages; pageNum++) {
      // Check if container still exists (user may have switched modes)
      if (!thumbnailContainerRef.current) return;

      const page = await pdfDoc.getPage(pageNum);
      const viewport = page.getViewport({ scale: thumbScale });

      const thumbCanvas = document.createElement('canvas');
      thumbCanvas.width = viewport.width;
      thumbCanvas.height = viewport.height;
      const thumbCtx = thumbCanvas.getContext('2d');

      await page.render({
        canvasContext: thumbCtx,
        viewport: viewport
      }).promise;

      // Check again after async render
      if (!thumbnailContainerRef.current) return;

      const thumbItem = document.createElement('div');
      thumbItem.className = `thumbnail-item ${pageNum === pdfState?.currentPage ? 'current' : ''}`;
      thumbItem.dataset.page = pageNum;
      thumbItem.appendChild(thumbCanvas);

      const label = document.createElement('div');
      label.className = 'thumbnail-label';
      label.textContent = `${pageNum}`;
      thumbItem.appendChild(label);

      thumbItem.addEventListener('click', () => {
        onPdfStateChange({
          ...pdfState,
          currentPage: pageNum,
          thumbnailMode: false
        });
      });

      thumbnailContainerRef.current.appendChild(thumbItem);
    }
  }, [pdfDoc, pdfState, onPdfStateChange]);

  // Generate thumbnails when entering thumbnail mode
  useEffect(() => {
    if (pdfState?.thumbnailMode && pdfDoc) {
      generateThumbnails();
    }
  }, [pdfState?.thumbnailMode, pdfDoc, generateThumbnails]);

  // Load text/markdown content
  useEffect(() => {
    if (!file || file.type === 'divider' || file.type === 'pdf') return;

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
            Supports: .txt, .rtf, .md, .pdf, .jpg, .png, .gif, .mp4, .webm
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

  // PDF Viewer
  if (file.type === 'pdf') {
    return (
      <div className="content-viewer pdf-viewer">
        {!pdfState?.thumbnailMode ? (
          <>
            <button className="nav-btn prev-btn" onClick={() => {
              if (pdfState?.currentPage > 1) {
                onPdfStateChange({ ...pdfState, currentPage: pdfState.currentPage - 1 });
              }
            }} aria-label="Previous Page">
              <svg width="64" height="64" viewBox="0 0 64 64">
                <path d="M44 8 L20 32 L44 56" stroke="white" strokeWidth="8" fill="none" strokeLinecap="round" strokeLinejoin="round"/>
              </svg>
            </button>

            <div className="pdf-canvas-container" ref={pdfContainerRef}>
              <canvas ref={canvasRef} className="pdf-canvas" />
            </div>

            <button className="nav-btn next-btn" onClick={() => {
              if (pdfState?.currentPage < pdfState?.totalPages) {
                onPdfStateChange({ ...pdfState, currentPage: pdfState.currentPage + 1 });
              }
            }} aria-label="Next Page">
              <svg width="64" height="64" viewBox="0 0 64 64">
                <path d="M20 8 L44 32 L20 56" stroke="white" strokeWidth="8" fill="none" strokeLinecap="round" strokeLinejoin="round"/>
              </svg>
            </button>
          </>
        ) : (
          <div className="thumbnail-container" ref={thumbnailContainerRef}>
            {/* Thumbnails generated dynamically */}
          </div>
        )}
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
