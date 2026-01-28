import React, { useRef } from 'react';
import { useAudioRecorder } from '../hooks/useAudioRecorder';

function ControlBar({
  currentFile,
  fontSize,
  onFontSizeChange,
  darkMode,
  onDarkModeToggle,
  isEditing,
  onEdit,
  onSave,
  onCancel,
  onFilesLoaded,
  onOpenCloudNotes,
  // PDF props
  pdfState,
  onPdfStateChange,
  onCopyPageText,
  // Reading aids props
  readingAidsEnabled,
  onToggleReadingAids,
  // Audio props
  audioFile,
  isAudioPlaying,
  onAudioPlayPause,
  onAudioStop
}) {
  const { isRecording, isProcessing, startRecording, stopRecording } = useAudioRecorder();
  const folderInputRef = useRef(null);
  const fileInputRef = useRef(null);
  const pageInputRef = useRef(null);

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

  const handleFolderSelect = (e) => {
    if (e.target.files && e.target.files.length > 0) {
      onFilesLoaded(e.target.files);
    }
  };

  const handleFileSelect = (e) => {
    if (e.target.files && e.target.files.length > 0) {
      onFilesLoaded(e.target.files);
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

  const isPdf = currentFile && currentFile.type === 'pdf';
  const canEdit = currentFile && (currentFile.type === 'text' || currentFile.type === 'rtf' || currentFile.type === 'markdown');

  return (
    <div className="control-bar">
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
        className="select-files-btn"
        onClick={() => fileInputRef.current?.click()}
      >
        Select Files
      </button>

      <input
        type="file"
        ref={fileInputRef}
        style={{ display: 'none' }}
        multiple
        accept=".txt,.rtf,.md,.pdf,.jpg,.jpeg,.png,.gif,.bmp,.webp,.mp4,.webm,.ogg,.mov,.mp3,.wav,.m4a"
        onChange={handleFileSelect}
      />

      {/* Edit controls - hide for PDF */}
      {!isPdf && (
        <div className="edit-controls">
          {!isEditing ? (
            <button
              className="edit-btn"
              onClick={onEdit}
              disabled={!canEdit}
            >
              Edit
            </button>
          ) : (
            <>
              <button className="save-btn" onClick={onSave}>Save</button>
              <button className="cancel-btn" onClick={onCancel}>Cancel</button>
            </>
          )}
        </div>
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
        <button
          className="cloud-notes-btn"
          onClick={onOpenCloudNotes}
          title="Cloud Notes"
        >
          ☁️
        </button>
        <button
          className={`reading-aids-btn ${readingAidsEnabled ? 'active' : ''}`}
          onClick={onToggleReadingAids}
          title="Toggle Reading Ruler"
        >
          {readingAidsEnabled ? '📏' : '📐'}
        </button>
      </div>

      {/* Audio Recording Controls */}
      <div className="recording-controls">
        {!isRecording ? (
          <button
            className="record-btn"
            onClick={startRecording}
            disabled={isProcessing}
            title="Start Recording"
          >
            🎙️
          </button>
        ) : (
          <button
            className="record-btn recording"
            onClick={stopRecording}
            title="Stop & Save Recording"
          >
            ⏹️
          </button>
        )}
        {isRecording && <span className="recording-indicator">REC</span>}
        {isProcessing && <span className="processing-indicator">Saving...</span>}
      </div>

      {/* Audio Player Controls */}
      {audioFile && (
        <div className="audio-controls">
          <span className="audio-label" title={audioFile.key}>
            🔊 {audioFile.key.length > 20 ? audioFile.key.substring(0, 20) + '...' : audioFile.key}
          </span>
          <button
            className={`audio-play-btn ${isAudioPlaying ? 'playing' : ''}`}
            onClick={onAudioPlayPause}
            title={isAudioPlaying ? 'Pause' : 'Play'}
          >
            {isAudioPlaying ? '⏸' : '▶️'}
          </button>
          <button
            className="audio-stop-btn"
            onClick={onAudioStop}
            title="Stop"
          >
            ⏹
          </button>
        </div>
      )}

      {currentFile && currentFile.type !== 'divider' && (
        <span className="current-file-name">{currentFile.key}</span>
      )}
    </div>
  );
}

export default ControlBar;
