import React, { useRef, useState, useEffect } from 'react';
import { isImageFile, isVideoFile } from '../utils/fileUtils';

function getShortLabel(path) {
  const parts = path.replace(/\/+$/, '').split('/').filter(Boolean);
  return parts.slice(-2).join('/');
}

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
  // Pin / ruler props
  isPinned,
  onTogglePin,
  rulerEnabled,
  onToggleRuler,
  controlBarHidden,
  onMouseEnterBar,
  onMouseLeaveBar,
  // Syntax highlight props
  syntaxHighlightEnabled,
  onToggleSyntaxHighlight,
  // Sidebar props
  showSidebar,
  onToggleSidebar,
  // Dropbox props
  onOpenDropbox,
  onOpenDropboxRecursive,
  dropboxFileMode,
  onDropboxFileModeChange,
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
  // Dropbox file operations (DB-nonrecursive only)
  onEdit,
  onRename,
  onNewFile,
  isDropboxNonRecursive,
  isEditing,
  onSave,
  onCancel,
  // Local file system props
  isLocalFS,
  onLocalDirOpen,
  onLocalDirOpenRecursive,
  // Direct Dropbox folder load (skips browser modal)
  onLoadDropboxPath,
  dropbox,
  // Persistent audio player
  persistentAudio,
  onClearAudio,
}) {
  const folderInputRef = useRef(null);
  const shallowFolderInputRef = useRef(null);
  const picsOnlyFolderInputRef = useRef(null);
  const pageInputRef = useRef(null);
  const [tagSearch, setTagSearch] = useState('');
  const [copyStatus, setCopyStatus] = useState('idle'); // 'idle' | 'ok' | 'fail'
  const [showPathPicker, setShowPathPicker] = useState(false);
  const [dirPaths, setDirPaths] = useState([]);
  const [selectedPath, setSelectedPath] = useState(() => localStorage.getItem('selectedDirPath') || '');
  const [imagePaths, setImagePaths] = useState([]);
  const [audioNotify, setAudioNotify] = useState(false);
  const prevAudioRef = useRef(null);

  // Flash notification when persistent audio changes
  useEffect(() => {
    if (persistentAudio && persistentAudio.url !== prevAudioRef.current) {
      prevAudioRef.current = persistentAudio.url;
      setAudioNotify(true);
      const timer = setTimeout(() => setAudioNotify(false), 2000);
      return () => clearTimeout(timer);
    }
  }, [persistentAudio]);

  useEffect(() => {
    const isLocal = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
    const url = isLocal ? '/dirpaths.json' : '/api/dirpaths';
    fetch(url)
      .then(res => res.json())
      .then(paths => {
        setDirPaths(paths);
        // If no selection yet, default to first path
        if (!localStorage.getItem('selectedDirPath') && paths.length > 0) {
          setSelectedPath(paths[0]);
          localStorage.setItem('selectedDirPath', paths[0]);
        }
      })
      .catch(() => {});

    const imageUrl = isLocal ? '/imagepaths.json' : '/api/imagepaths';
    fetch(imageUrl)
      .then(res => res.json())
      .then(paths => setImagePaths(paths))
      .catch(() => {});
  }, []);

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


  const isMediaFile = (name) => isImageFile(name) || isVideoFile(name);

  const handleDropPicsOnly = (e) => {
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
        processEntriesPicsOnly(entries);
      }
    }
  };

  const processEntriesPicsOnly = async (entries) => {
    const files = [];

    const readEntry = async (entry, path = '') => {
      if (entry.isFile) {
        return new Promise((resolve) => {
          entry.file((file) => {
            if (isMediaFile(file.name)) {
              const relativePath = path + file.name;
              Object.defineProperty(file, 'webkitRelativePath', {
                value: relativePath,
                writable: false
              });
              files.push(file);
            }
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

  const handlePicsOnlyFolderSelect = (e) => {
    if (e.target.files && e.target.files.length > 0) {
      const filtered = Array.from(e.target.files).filter(file => isMediaFile(file.name));
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


  const handleSpeedToggle = () => {
    const newSpeed = videoSpeed === 1 ? 0.5 : 1;
    setVideoSpeed(newSpeed);
    const video = document.querySelector('.preview-video');
    if (video) {
      video.playbackRate = newSpeed;
    }
  };

  return (
    <div
      className={`control-bar${controlBarHidden ? ' control-bar--hidden' : ''}`}
      onMouseEnter={onMouseEnterBar}
      onMouseLeave={onMouseLeaveBar}
    >
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
        className="dropbox-btn"
        onClick={onLocalDirOpen}
        onDragOver={handleDragOver}
        onDragLeave={handleDragLeave}
        onDrop={handleDropShallow}
      >
        Drop
      </button>

      <button
        className="dropbox-btn"
        onClick={onLocalDirOpenRecursive}
        onDragOver={handleDragOver}
        onDragLeave={handleDragLeave}
        onDrop={handleDrop}
      >
        Drop(r)
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

      {/* Path picker button (orange, styled like bible app 2:bsb) */}
      {dirPaths.length > 0 && (
        <div style={{ position: 'relative' }}>
          <button
            onClick={() => setShowPathPicker(prev => !prev)}
            style={{
              background: '#f97316',
              color: '#fff',
              border: 'none',
              borderRadius: '4px',
              padding: '4px 8px',
              fontSize: '12px',
              fontWeight: 600,
              cursor: 'pointer',
            }}
            title="Pick a directory path (copies to clipboard)"
          >
            {selectedPath ? getShortLabel(selectedPath) : 'Path'}
          </button>
          {showPathPicker && (
            <>
              <div
                style={{ position: 'fixed', inset: 0, zIndex: 40 }}
                onClick={() => setShowPathPicker(false)}
              />
              <div style={{
                position: 'absolute',
                left: 0,
                top: '100%',
                marginTop: '4px',
                zIndex: 50,
                borderRadius: '6px',
                boxShadow: '0 4px 12px rgba(0,0,0,0.3)',
                border: '1px solid #555',
                background: '#1f1f1f',
                minWidth: '200px',
              }}>
                {dirPaths.map((p) => (
                  <button
                    key={p}
                    onClick={() => {
                      setSelectedPath(p);
                      localStorage.setItem('selectedDirPath', p);
                      navigator.clipboard.writeText(p);
                      setShowPathPicker(false);
                    }}
                    style={{
                      display: 'block',
                      width: '100%',
                      textAlign: 'left',
                      padding: '6px 12px',
                      fontSize: '13px',
                      color: '#fff',
                      background: p === selectedPath ? 'rgba(249,115,22,0.3)' : 'transparent',
                      border: 'none',
                      cursor: 'pointer',
                      fontWeight: p === selectedPath ? 'bold' : 'normal',
                    }}
                    onMouseEnter={e => e.target.style.background = 'rgba(249,115,22,0.2)'}
                    onMouseLeave={e => e.target.style.background = p === selectedPath ? 'rgba(249,115,22,0.3)' : 'transparent'}
                  >
                    {getShortLabel(p)}
                  </button>
                ))}
              </div>
            </>
          )}
        </div>
      )}

      <div className="dropbox-file-mode">
        <label className={`file-mode-label ${dropboxFileMode === 'all' ? 'active' : ''}`}>
          <input
            type="radio"
            name="dropboxFileMode"
            value="all"
            checked={dropboxFileMode === 'all'}
            onChange={() => onDropboxFileModeChange('all')}
          />
          all
        </label>
        <label className={`file-mode-label ${dropboxFileMode === 'imgs' ? 'active' : ''}`}>
          <input
            type="radio"
            name="dropboxFileMode"
            value="imgs"
            checked={dropboxFileMode === 'imgs'}
            onChange={() => onDropboxFileModeChange('imgs')}
          />
          imgs
        </label>
        <label className={`file-mode-label ${dropboxFileMode === 'txt' ? 'active' : ''}`}>
          <input
            type="radio"
            name="dropboxFileMode"
            value="txt"
            checked={dropboxFileMode === 'txt'}
            onChange={() => onDropboxFileModeChange('txt')}
          />
          txt
        </label>
      </div>

      <button
        className="dropbox-btn"
        onClick={onOpenDropbox}
      >
        DB-nonr
      </button>

      <button
        className="dropbox-btn"
        onClick={onOpenDropboxRecursive}
        style={{ display: 'none' }}
      >
        DB-re
      </button>

      {dropbox && !dropbox.isAuthenticated && (
        <button
          className="dropbox-btn"
          onClick={() => dropbox.signIn('nonrecursive')}
          style={{ background: '#0061fe', fontSize: '11px' }}
        >
          DB Sign In (images)
        </button>
      )}

      {imagePaths.length > 0 && dropbox && dropbox.isAuthenticated && (
        <select
          defaultValue=""
          onChange={async (e) => {
            const path = e.target.value;
            if (!path) return;
            e.target.value = '';
            if (onLoadDropboxPath) onLoadDropboxPath(path);
          }}
          style={{
            background: '#2a2a3e',
            color: '#eee',
            border: '1px solid #555',
            borderRadius: '4px',
            padding: '2px 4px',
            fontSize: '11px',
            cursor: 'pointer',
          }}
        >
          <option value="">-- Images --</option>
          {imagePaths.map((p) => (
            <option key={p.path} value={p.path}>{p.label}</option>
          ))}
        </select>
      )}

      {/* File operations - for DB-nonrecursive or Local FS */}
      {(isDropboxNonRecursive || isLocalFS) && !isEditing && (
        <>
          <button className="copy-btn" onClick={onCopyContent} title="Copy file contents to clipboard">
            Copy
          </button>
          <button className="edit-btn" onClick={onEdit} title={isLocalFS ? "Edit and save back to local file" : "Edit and save back to Dropbox"}>
            Edit
          </button>
          {isDropboxNonRecursive && (
            <>
              <button className="rename-btn" onClick={onRename} title="Rename file on Dropbox">
                Rename
              </button>
              <button className="new-file-btn" onClick={onNewFile} title="Create new file from clipboard">
                + New File
              </button>
            </>
          )}
        </>
      )}

      {isEditing && (
        <>
          <button className="save-btn" onClick={onSave} title={isLocalFS ? "Save changes to local file" : "Save changes to Dropbox"}>
            Save
          </button>
          <button className="cancel-btn" onClick={onCancel} title="Discard changes">
            Cancel
          </button>
        </>
      )}

      <input
        type="file"
        ref={shallowFolderInputRef}
        style={{ display: 'none' }}
        webkitdirectory=""
        directory=""
        multiple
        onChange={handleShallowFolderSelect}
      />

      {/* Video controls: skip -5s, +5s, speed toggle */}
      {isVideo && (
        <>
          <button
            className="video-skip-btn"
            onClick={() => {
              const video = document.querySelector('.preview-video');
              if (video) video.currentTime = Math.max(0, video.currentTime - 5);
            }}
            style={{
              background: '#333',
              padding: '6px 10px',
              fontSize: '12px',
              color: '#fff',
              border: '1px solid #555',
              borderRadius: '4px',
              cursor: 'pointer',
            }}
            title="Skip back 5 seconds"
          >
            -5s
          </button>
          <button
            className="video-skip-btn"
            onClick={() => {
              const video = document.querySelector('.preview-video');
              if (video) video.currentTime = Math.min(video.duration || 0, video.currentTime + 5);
            }}
            style={{
              background: '#333',
              padding: '6px 10px',
              fontSize: '12px',
              color: '#fff',
              border: '1px solid #555',
              borderRadius: '4px',
              cursor: 'pointer',
            }}
            title="Skip forward 5 seconds"
          >
            +5s
          </button>
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
        </>
      )}

      <button
        className="paste-md-btn"
        onClick={onPasteContent}
        title="Load clipboard content as Markdown"
      >
        Paste in
      </button>

      <button
        className={`copy-text-btn${copyStatus === 'ok' ? ' copied' : copyStatus === 'fail' ? ' failed' : ''}`}
        onClick={() => {
          const result = onCopyContent();
          const p = result instanceof Promise ? result : Promise.resolve(result);
          p.then(ok => {
            setCopyStatus(ok !== false ? 'ok' : 'fail');
            setTimeout(() => setCopyStatus('idle'), 2000);
          }).catch(() => {
            setCopyStatus('fail');
            setTimeout(() => setCopyStatus('idle'), 2000);
          });
        }}
        title="Copy all text from current page to clipboard"
      >
        {copyStatus === 'ok' ? '✓ Copied' : copyStatus === 'fail' ? '✗ Failed' : '📋 Copy'}
      </button>

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
            🔲 Thumbnails
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

      {/* Word Wrap toggle */}
      <button
        className={`wrap-btn ${wrapText ? 'active' : ''}`}
        onClick={onToggleWrapText}
        title={wrapText ? 'Disable Word Wrap' : 'Enable Word Wrap'}
      >
        Wrap
      </button>

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

      {/* Drop folder (pics only) - recursive, images and videos only */}
      <button
        className="drop-folder-btn"
        onClick={() => picsOnlyFolderInputRef.current?.click()}
        onDragOver={handleDragOver}
        onDragLeave={handleDragLeave}
        onDrop={handleDropPicsOnly}
      >
        Pics
      </button>

      <button
        className="drop-folder-btn"
        onClick={() => window.open('https://pdf-viewer-six-ruby.vercel.app/pdfViewer.html', '_blank')}
        title="Open PDF Viewer"
      >
        PDF V
      </button>

      <input
        type="file"
        ref={picsOnlyFolderInputRef}
        style={{ display: 'none' }}
        webkitdirectory=""
        directory=""
        multiple
        onChange={handlePicsOnlyFolderSelect}
      />

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

      {/* Pin button */}
      <button
        className={`toolbar-pin-btn${isPinned ? ' pinned' : ''}`}
        onClick={onTogglePin}
        title={isPinned ? 'Unpin toolbar (/)' : 'Pin toolbar (/)'}
      >
        📌 {isPinned ? 'Pinned' : 'Pin'}
      </button>

      {/* Ruler button */}
      <button
        className={`toolbar-ruler-btn${rulerEnabled ? ' active' : ''}`}
        onClick={onToggleRuler}
        title={rulerEnabled ? 'Disable ruler (U)' : 'Enable ruler (U)'}
      >
        {rulerEnabled ? '✓ Ruler' : '👁 Ruler'}
      </button>

      {/* Page down button */}
      <button
        className="toolbar-ruler-btn"
        onClick={() => {
          const iframe = document.querySelector('.preview-html iframe');
          if (iframe && iframe.contentDocument) {
            iframe.contentDocument.documentElement.scrollBy({ top: iframe.clientHeight * 0.9, behavior: 'smooth' });
            return;
          }
          const scrollable = document.querySelector('.preview-text') || document.querySelector('.preview-markdown') || document.querySelector('.content-area');
          if (scrollable) scrollable.scrollBy({ top: scrollable.clientHeight * 0.9, behavior: 'smooth' });
        }}
        title="Page down"
      >
        ⬇ Page
      </button>

      {/* Persistent audio player */}
      {persistentAudio && (
        <div className={`toolbar-audio-player${audioNotify ? ' notify' : ''}`}>
          <audio
            id="audioElement"
            src={persistentAudio.url}
            controls
            autoPlay
          />
          <span className="toolbar-audio-name" title={persistentAudio.name}>
            {persistentAudio.name}
          </span>
          <button
            className="toolbar-audio-clear"
            onClick={onClearAudio}
            title="Clear audio player"
          >✕</button>
        </div>
      )}

    </div>
  );
}

export default ControlBar;
