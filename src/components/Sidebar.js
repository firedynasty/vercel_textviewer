import React from 'react';

// Abbreviate parent segments in divider paths: "game-film_breakdowns/in_gae" → "gam../in_gae"
function shortenDividerPath(path) {
  const parts = path.split('/');
  if (parts.length <= 1) return path;
  const shortened = parts.slice(0, -1).map(p => p.slice(0, 3) + '..').concat(parts[parts.length - 1]);
  return shortened.join('/');
}

function Sidebar({ files, currentIndex, onFileSelect, onNext, isOpen, onClose, activeTagFilter, fileTags }) {
  const handleClick = (file, index) => {
    if (file.type === 'divider') return;
    onFileSelect(index);
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
        <button className="sidebar-next-btn" onClick={onNext} title="Next file">
          &#9654;
        </button>
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
              className={`sidebar-item ${index === currentIndex ? 'active' : ''} ${file.type === 'divider' ? 'divider' : ''}`}
              onClick={() => handleClick(file, index)}
            >
              <span className="sidebar-item-label" title={file.type === 'divider' ? file.key : undefined}>
                {file.type === 'divider' ? shortenDividerPath(file.key) : file.key}
              </span>
              {file.type !== 'divider' && (
                <span className="sidebar-item-type">
                  {file.type === 'markdown' ? 'MD' :
                   file.type === 'rtf' ? 'RTF' :
                   file.type === 'text' ? 'TXT' :
                   file.type === 'video' ? 'VID' :
                   file.type === 'image' ? 'IMG' :
                   file.type === 'pdf' ? 'PDF' :
                   file.type === 'docx' ? 'DOCX' :
                   file.type === 'csv' ? 'CSV' :
                   file.type === 'xlsx' ? 'XLSX' : ''}
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
