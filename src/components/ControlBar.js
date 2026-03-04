import React, { useRef, useState } from 'react';

function ControlBar({
  currentFile,
  fontSize,
  onFontSizeChange,
  darkMode,
  onDarkModeToggle,
  onCopyContent,
  onPasteContent,
  onFilesLoaded,
  // PDF props
  pdfState,
  onPdfStateChange,
  onCopyPageText,
  // Syntax highlight props
  syntaxHighlightEnabled,
  onToggleSyntaxHighlight,
  // Sidebar props
  showSidebar,
  onToggleSidebar,
  // Dropbox props
  onOpenDropbox,
  onOpenDropboxRecursive,
  // Slideshow props
  slideshowEnabled,
  onToggleSlideshow,
  hasImages,
  // Tag filter props
  allTags,
  activeTagFilter,
  onTagFilterChange,
  // Text wrap toggle props
  wrapText,
  onToggleWrapText,
}) {
  const folderInputRef = useRef(null);
  const shallowFolderInputRef = useRef(null);
  const pageInputRef = useRef(null);
  const [tagSearch, setTagSearch] = useState('');

  const filteredTags = allTags && allTags.length > 0
    ? (tagSearch
        ? allTags.filter(t => t.tag.includes(tagSearch.toLowerCase()))
        : allTags)
    : [];

  const handleDragOver = (e) => {
    e.preventDefault();
    e.stopPropagation();
    e.currentTarget.classList.add('drag-over');
  };

  const handleDragLeave = (e) => {
    e.preventDefault();
    e.stopPropagation();
    e.currentTarget.classList.remove('drag-over');
  };

  const handleDrop = (e) => {
    e.preventDefault();
    e.stopPropagation();
    e.currentTarget.classList.remove('drag-over');

    const items = e.dataTransfer.items;
    if (items) {
      const entries = [];
      for (let i = 0; i < items.length; i++) {
        const entry = items[i].webkitGetAsEntry();
        if (entry) {
          entries.push(entry);
        }
      }
      if (entries.length > 0) {
        processEntries(entries);
      }
    }
  };

  const processEntries = async (entries) => {
    const files = [];

    const readEntry = async (entry, path = '') => {
      if (entry.isFile) {
        return new Promise((resolve) => {
          entry.file((file) => {
            // Create a new file with the relative path
            const relativePath = path + file.name;
            Object.defineProperty(file, 'webkitRelativePath', {
              value: relativePath,
              writable: false
            });
            files.push(file);
            resolve();
          });
        });
      } else if (entry.isDirectory) {
        const dirReader = entry.createReader();
        return new Promise((resolve) => {
          const readEntries = () => {
            dirReader.readEntries(async (entries) => {
              if (entries.length === 0) {
                resolve();
              } else {
                for (const entry of entries) {
                  await readEntry(entry, path + entry.name + '/');
                }
                readEntries();
              }
            });
          };
          readEntries();
        });
      }
    };

    for (const entry of entries) {
      await readEntry(entry, entry.name + '/');
    }

    if (files.length > 0) {
      onFilesLoaded(files);
    }
  };

  const handleDropShallow = (e) => {
    e.preventDefault();
    e.stopPropagation();
    e.currentTarget.classList.remove('drag-over');

    const items = e.dataTransfer.items;
    if (items) {
      const entries = [];
      for (let i = 0; i < items.length; i++) {
        const entry = items[i].webkitGetAsEntry();
        if (entry) {
          entries.push(entry);
        }
      }
      if (entries.length > 0) {
        processEntriesShallow(entries);
      }
    }
  };

  const processEntriesShallow = async (entries) => {
    const files = [];

    const readEntry = async (entry, path = '', depth = 0) => {
      if (entry.isFile) {
        return new Promise((resolve) => {
          entry.file((file) => {
            const relativePath = path + file.name;
            Object.defineProperty(file, 'webkitRelativePath', {
              value: relativePath,
              writable: false
            });
            files.push(file);
            resolve();
          });
        });
      } else if (entry.isDirectory && depth < 1) {
        const dirReader = entry.createReader();
        return new Promise((resolve) => {
          const readEntries = () => {
            dirReader.readEntries(async (entries) => {
              if (entries.length === 0) {
                resolve();
              } else {
                for (const entry of entries) {
                  await readEntry(entry, path + entry.name + '/', depth + 1);
                }
                readEntries();
              }
            });
          };
          readEntries();
        });
      }
    };

    for (const entry of entries) {
      await readEntry(entry, entry.name + '/', 0);
    }

    if (files.length > 0) {
      onFilesLoaded(files);
    }
  };

  const handleFolderSelect = (e) => {
    if (e.target.files && e.target.files.length > 0) {
      onFilesLoaded(e.target.files);
    }
  };

  const handleShallowFolderSelect = (e) => {
    if (e.target.files && e.target.files.length > 0) {
      // Filter to only files at depth 1: rootFolder/file.ext (2 parts)
      const filtered = Array.from(e.target.files).filter(file => {
        const parts = file.webkitRelativePath.split('/');
        return parts.length <= 2;
      });
      if (filtered.length > 0) {
        onFilesLoaded(filtered);
      }
    }
  };


  const handlePageInputKeyDown = (e) => {
    if (e.key === 'Enter') {
      const pageNum = parseInt(e.target.value);
      if (pageNum >= 1 && pageNum <= pdfState?.totalPages) {
        onPdfStateChange({ ...pdfState, currentPage: pageNum });
      }
      e.target.blur();
    }
  };

  const [videoSpeed, setVideoSpeed] = useState(1);
  const isPdf = currentFile && currentFile.type === 'pdf';
  const isMarkdown = currentFile && currentFile.type === 'markdown';
  const isVideo = currentFile && currentFile.type === 'video';
  const canCopy = currentFile && (currentFile.type === 'text' || currentFile.type === 'rtf' || currentFile.type === 'markdown' || currentFile.type === 'docx');

  const handleSpeedToggle = () => {
    const newSpeed = videoSpeed === 1 ? 0.5 : 1;
    setVideoSpeed(newSpeed);
    const video = document.querySelector('.preview-video');
    if (video) {
      video.playbackRate = newSpeed;
    }
  };

  return (
    <div className="control-bar">
      {!showSidebar && (
        <button
          className="hamburger-btn"
          onClick={onToggleSidebar}
          title="Open sidebar"
        >
          &#9776;
        </button>
      )}

      <button
        className="drop-folder-btn"
        onClick={() => folderInputRef.current?.click()}
        onDragOver={handleDragOver}
        onDragLeave={handleDragLeave}
        onDrop={handleDrop}
      >
        Drop Folder Here
      </button>

      <input
        type="file"
        ref={folderInputRef}
        style={{ display: 'none' }}
        webkitdirectory=""
        directory=""
        multiple
        onChange={handleFolderSelect}
      />

      <button
        className="dropbox-btn"
        onClick={onOpenDropbox}
      >
        Dropbox
      </button>

      <button
        className="dropbox-btn"
        onClick={onOpenDropboxRecursive}
      >
        DB-recursive
      </button>

      <button
        className="drop-folder-btn"
        onClick={() => shallowFolderInputRef.current?.click()}
        onDragOver={handleDragOver}
        onDragLeave={handleDragLeave}
        onDrop={handleDropShallow}
        title="Load folder without subfolders (depth 1)"
      >
        Folder Flat
      </button>

      <input
        type="file"
        ref={shallowFolderInputRef}
        style={{ display: 'none' }}
        webkitdirectory=""
        directory=""
        multiple
        onChange={handleShallowFolderSelect}
      />

      {/* Video speed toggle */}
      {isVideo && (
        <button
          className="speed-toggle-btn"
          onClick={handleSpeedToggle}
          style={{
            background: videoSpeed === 0.5
              ? 'linear-gradient(45deg, #00BCD4, #0097A7)'
              : 'linear-gradient(45deg, #7E57C2, #5E35B1)',
            padding: '6px 12px',
            fontSize: '12px',
            color: '#fff',
            border: 'none',
            borderRadius: '4px',
            cursor: 'pointer',
          }}
          title="Toggle playback speed (1x / 0.5x)"
        >
          {videoSpeed}x
        </button>
      )}

      {/* Copy/Paste content buttons - hide for PDF */}
      {!isPdf && (
        <div className="edit-controls">
          <button
            className="edit-btn"
            onClick={onCopyContent}
            disabled={!canCopy}
            title="Copy file contents to clipboard"
          >
            Copy
          </button>
          <button
            className="edit-btn paste-btn"
            onClick={onPasteContent}
            title="Paste clipboard contents as markdown viewer"
          >
            Paste
          </button>
        </div>
      )}

      {/* Syntax Highlight button for markdown files */}
      {isMarkdown && (
        <button
          className={`syntax-highlight-btn ${syntaxHighlightEnabled ? 'active' : ''}`}
          onClick={onToggleSyntaxHighlight}
          title={syntaxHighlightEnabled ? 'Disable Syntax Highlighting' : 'Enable Syntax Highlighting'}
        >
          {syntaxHighlightEnabled ? '🎨' : '{ }'}
        </button>
      )}

      {/* PDF Controls */}
      {isPdf && pdfState && (
        <div className="pdf-controls">
          {/* Thumbnail toggle */}
          <button
            className={`pdf-thumbnail-btn ${pdfState.thumbnailMode ? 'active' : ''}`}
            onClick={() => onPdfStateChange({ ...pdfState, thumbnailMode: !pdfState.thumbnailMode })}
            title="Toggle Thumbnail View"
          >
            {pdfState.thumbnailMode ? '📄' : '🔲'}
          </button>

          {/* Prev page */}
          <button
            className="pdf-nav-btn"
            onClick={() => {
              if (pdfState.currentPage > 1) {
                onPdfStateChange({ ...pdfState, currentPage: pdfState.currentPage - 1 });
              }
            }}
            disabled={pdfState.currentPage <= 1}
            title="Previous Page"
          >
            ◀
          </button>

          {/* Page input */}
          <input
            type="number"
            ref={pageInputRef}
            className="pdf-page-input"
            value={pdfState.currentPage}
            min={1}
            max={pdfState.totalPages}
            onChange={(e) => {
              const val = parseInt(e.target.value);
              if (val >= 1 && val <= pdfState.totalPages) {
                onPdfStateChange({ ...pdfState, currentPage: val });
              }
            }}
            onKeyDown={handlePageInputKeyDown}
            title="Go to page"
          />

          {/* Page indicator (just shows "/ total") */}
          <span className="pdf-page-indicator">/ {pdfState.totalPages}</span>

          {/* Next page */}
          <button
            className="pdf-nav-btn"
            onClick={() => {
              if (pdfState.currentPage < pdfState.totalPages) {
                onPdfStateChange({ ...pdfState, currentPage: pdfState.currentPage + 1 });
              }
            }}
            disabled={pdfState.currentPage >= pdfState.totalPages}
            title="Next Page"
          >
            ▶
          </button>

          {/* Copy Page Text button */}
          <button
            className="pdf-copy-btn"
            onClick={onCopyPageText}
            title="Copy all text from current page to clipboard"
          >
            📄 Copy Text
          </button>

          {/* Rotate button */}
          <button
            className="pdf-rotate-btn"
            onClick={() => {
              const newRotation = ((pdfState.rotation || 0) + 90) % 360;
              onPdfStateChange({ ...pdfState, rotation: newRotation });
            }}
            title="Rotate 90°"
          >
            🔄
          </button>
        </div>
      )}

      {/* Font/Zoom controls - reused for PDF zoom */}
      <div className="font-size-controls">
        <button
          className="font-size-btn"
          onClick={() => {
            if (isPdf && pdfState) {
              onPdfStateChange({ ...pdfState, scale: Math.max(0.5, pdfState.scale - 0.2) });
            } else {
              onFontSizeChange(-2);
            }
          }}
          title={isPdf ? "Zoom Out" : "Decrease Font Size"}
        >
          -
        </button>
        <button
          className="font-size-btn"
          onClick={() => {
            if (isPdf && pdfState) {
              onPdfStateChange({ ...pdfState, scale: Math.min(3, pdfState.scale + 0.2) });
            } else {
              onFontSizeChange(2);
            }
          }}
          title={isPdf ? "Zoom In" : "Increase Font Size"}
        >
          +
        </button>
        <button
          className="dark-mode-btn"
          onClick={onDarkModeToggle}
          title={darkMode ? 'Light Mode' : 'Dark Mode'}
        >
          {darkMode ? '☀' : '☾'}
        </button>
      </div>

      {/* Text wrap toggle */}
      <div className="recording-controls">
        <button
          className={`record-btn${wrapText ? ' recording' : ''}`}
          onClick={onToggleWrapText}
          title={wrapText ? 'Unwrap text' : 'Wrap text'}
        >
          {wrapText ? '↩️' : '↔️'}
        </button>
      </div>

      {/* Tag Filter */}
      {filteredTags.length > 0 && (
        <div className="tag-filter-section">
          <input
            className="tag-search-input"
            type="text"
            placeholder="Filter tags..."
            value={tagSearch}
            onChange={(e) => setTagSearch(e.target.value)}
          />
          <div className="tag-chips">
            {filteredTags.map(({ tag, count }) => (
              <button
                key={tag}
                className={`tag-chip ${activeTagFilter === tag ? 'active' : ''}`}
                onClick={() => onTagFilterChange(activeTagFilter === tag ? null : tag)}
                title={`${count} file${count > 1 ? 's' : ''}`}
              >
                ${tag}
                <span className="tag-count">{count}</span>
              </button>
            ))}
          </div>
        </div>
      )}

      {/* Slideshow Toggle */}
      {hasImages && (
        <div className="slideshow-controls">
          <label className="slideshow-switch">
            <input
              type="checkbox"
              checked={slideshowEnabled}
              onChange={onToggleSlideshow}
            />
            <span className="slideshow-slider"></span>
          </label>
          <span className="slideshow-label">
            {slideshowEnabled ? 'Slideshow ON' : 'Slideshow'}
          </span>
        </div>
      )}

      {currentFile && currentFile.type !== 'divider' && (
        <span className="current-file-name">{currentFile.key}</span>
      )}
    </div>
  );
}

export default ControlBar;
