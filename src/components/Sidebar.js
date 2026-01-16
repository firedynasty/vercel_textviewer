import React from 'react';

function Sidebar({ files, currentIndex, onFileSelect }) {
  return (
    <div className="sidebar">
      <div className="sidebar-content">
        {files.map((file, index) => (
          <div
            key={file.key}
            className={`sidebar-item ${index === currentIndex ? 'active' : ''} ${file.type === 'divider' ? 'divider' : ''}`}
            onClick={() => file.type !== 'divider' && onFileSelect(index)}
          >
            <span className="sidebar-item-label">
              {file.type === 'divider' ? file.key : file.key}
            </span>
            {file.type !== 'divider' && (
              <span className="sidebar-item-type">
                {file.type === 'markdown' ? 'MD' :
                 file.type === 'rtf' ? 'RTF' :
                 file.type === 'text' ? 'TXT' :
                 file.type === 'video' ? 'VID' :
                 file.type === 'image' ? 'IMG' : ''}
              </span>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}

export default Sidebar;
