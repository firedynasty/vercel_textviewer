import React, { useRef } from 'react';

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
  tts,
  onOpenCloudNotes
}) {
  const folderInputRef = useRef(null);

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

      <div className="font-size-controls">
        <button
          className="font-size-btn"
          onClick={() => onFontSizeChange(-2)}
        >
          -
        </button>
        <button
          className="font-size-btn"
          onClick={() => onFontSizeChange(2)}
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
      </div>

      {/* TTS Controls */}
      <div className="tts-controls">
        <button
          className="tts-nav-btn"
          onClick={tts.prevSentence}
          disabled={tts.currentSentenceIndex === 0}
          title="Previous Sentence [ "
        >
          [
        </button>

        <span className="tts-indicator">
          {tts.sentenceIndicator}
        </span>

        <button
          className="tts-nav-btn"
          onClick={tts.nextSentence}
          disabled={tts.currentSentenceIndex >= tts.sentences.length - 1}
          title="Next Sentence ]"
        >
          ]
        </button>

        <button
          className={`tts-play-btn ${tts.isPlaying ? 'playing' : ''}`}
          onClick={() => {
            if (tts.isPlaying) {
              tts.stop();
            } else {
              // Check if there's selected text
              const selection = window.getSelection().toString().trim();
              console.log('Play clicked, selection:', selection);
              if (selection && selection.length > 1) {
                // Try to play from selection
                const found = tts.playFromSelection(selection);
                if (!found) {
                  // Fallback to regular play if no match found
                  console.log('No match, using regular play');
                  tts.play();
                }
              } else {
                tts.play();
              }
            }
          }}
          title={tts.isPlaying ? 'Stop' : 'Play (select text first to start from there)'}
        >
          {tts.isPlaying ? '⏹' : '▶️'}
        </button>

        <select
          className="tts-select"
          value={tts.speed}
          onChange={(e) => tts.setSpeed(parseFloat(e.target.value))}
          title="Speed"
        >
          <option value="0.5">0.5x</option>
          <option value="0.75">0.75x</option>
          <option value="1">1x</option>
          <option value="1.25">1.25x</option>
          <option value="1.5">1.5x</option>
          <option value="2">2x</option>
        </select>

        <select
          className="tts-select"
          value={tts.language}
          onChange={(e) => tts.setLanguage(e.target.value)}
          title="Language"
        >
          <option value="en-US">English</option>
          <option value="zh-HK">Cantonese</option>
          <option value="zh-CN">Mandarin</option>
          <option value="es-ES">Spanish</option>
          <option value="he-IL">Hebrew</option>
        </select>

        <div className="tts-count-controls">
          <button
            className="tts-count-btn"
            onClick={() => tts.setSentenceCount(Math.max(1, tts.sentenceCount - 1))}
            title="Decrease sentence count"
          >
            -
          </button>
          <input
            type="number"
            className="tts-count-input"
            value={tts.sentenceCount}
            onChange={(e) => tts.setSentenceCount(Math.max(1, parseInt(e.target.value) || 1))}
            min="1"
            title="Sentences to read"
          />
          <button
            className="tts-count-btn"
            onClick={() => tts.setSentenceCount(tts.sentenceCount + 1)}
            title="Increase sentence count"
          >
            +
          </button>
        </div>

        <div className="tts-repeat-toggle">
          <label className="tts-radio-label">
            <input
              type="radio"
              name="repeatMode"
              value="continue"
              checked={tts.repeatMode === 'continue'}
              onChange={() => tts.setRepeatMode('continue')}
            />
            <span>→ next</span>
          </label>
          <label className="tts-radio-label">
            <input
              type="radio"
              name="repeatMode"
              value="repeat"
              checked={tts.repeatMode === 'repeat'}
              onChange={() => tts.setRepeatMode('repeat')}
            />
            <span>↻ repeat</span>
          </label>
        </div>
      </div>

      {currentFile && currentFile.type !== 'divider' && (
        <span className="current-file-name">{currentFile.key}</span>
      )}
    </div>
  );
}

export default ControlBar;
