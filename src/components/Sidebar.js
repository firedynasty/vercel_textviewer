import React from 'react';

function Sidebar({ files, currentIndex, onFileSelect, onAudioSelect, currentAudioIndex, isOpen, onClose, activeTagFilter, fileTags }) {
  const handleClick = (file, index) => {
    if (file.type === 'divider') return;

    if (file.type === 'audio') {
      onAudioSelect(index);
    } else {
      onFileSelect(index);
    }
  };

  // Determine which files are visible when a tag filter is active
  const isFileVisible = (file, index) => {
    if (!activeTagFilter) return true;
    if (file.type === 'divider') return false; // hide dividers when filtering
    const tags = fileTags && fileTags[index];
    if (!tags) return false; // hide files without tags when filtering
    return tags.includes(activeTagFilter);
  };

  if (!isOpen) return null;

  return (
    <div className="sidebar">
      <div className="sidebar-header">
        <span className="sidebar-header-title">
          Files{activeTagFilter ? ` $${activeTagFilter}` : ''}
        </span>
        <button className="sidebar-close-btn" onClick={onClose} title="Close sidebar">
          &times;
        </button>
      </div>
      <div className="sidebar-content">
        {files.map((file, index) => {
          if (!isFileVisible(file, index)) return null;
          return (
            <div
              key={file.key}
              className={`sidebar-item ${index === currentIndex && file.type !== 'audio' ? 'active' : ''} ${file.type === 'divider' ? 'divider' : ''} ${file.type === 'audio' && index === currentAudioIndex ? 'audio-active' : ''}`}
              onClick={() => handleClick(file, index)}
            >
              <span className="sidebar-item-label">
                {file.key}
              </span>
              {file.type !== 'divider' && (
                <span className="sidebar-item-type">
                  {file.type === 'markdown' ? 'MD' :
                   file.type === 'rtf' ? 'RTF' :
                   file.type === 'text' ? 'TXT' :
                   file.type === 'video' ? 'VID' :
                   file.type === 'image' ? 'IMG' :
                   file.type === 'pdf' ? 'PDF' :
                   file.type === 'audio' ? '🔊' : ''}
                </span>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}

export default Sidebar;
