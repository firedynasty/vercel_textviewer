import React, { useState } from 'react';

// Abbreviate parent segments in divider paths: "game-film_breakdowns/in_gae" → "gam../in_gae"
function shortenDividerPath(path) {
  const parts = path.split('/');
  if (parts.length <= 1) return path;
  const shortened = parts.slice(0, -1).map(p => p.slice(0, 3) + '..').concat(parts[parts.length - 1]);
  return shortened.join('/');
}

function getFullPath(files, index) {
  const file = files[index];
  if (!file) return '';
  if (file.type === 'divider') return file.key;
  // If Dropbox file, use dropboxPath
  if (file.dropboxPath) return file.dropboxPath;
  // Otherwise, find the nearest preceding divider to build the path
  const fileName = file.originalName || file.key;
  for (let i = index - 1; i >= 0; i--) {
    if (files[i].type === 'divider') {
      const folder = files[i].key;
      return folder === './' ? fileName : `${folder}/${fileName}`;
    }
  }
  return fileName;
}

function formatModifiedDate(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  const mon = (d.getMonth() + 1).toString().padStart(2, '0');
  const day = d.getDate().toString().padStart(2, '0');
  const yr = d.getFullYear().toString().slice(-2);
  return `${mon}/${day}/${yr}`;
}

function Sidebar({ files, currentIndex, onFileSelect, onNext, isOpen, onClose, activeTagFilter, fileTags, mdHeadings, onScrollToHeading, dropboxFileMode }) {
  const [searchFilter, setSearchFilter] = useState('');
  const [pathModal, setPathModal] = useState(null); // { path, x, y }
  const [showAllPaths, setShowAllPaths] = useState(false);
  const [sortMode, setSortMode] = useState(null); // null | 'date' | 'name'

  const handleClick = (file, index) => {
    if (file.type === 'divider') return;
    onFileSelect(index);
  };

  const handleLabelClick = (e, index) => {
    if (files[index] && files[index].type !== 'divider') return;
    const fullPath = getFullPath(files, index);
    const rect = e.currentTarget.getBoundingClientRect();
    setPathModal({ path: fullPath, x: rect.left, y: rect.bottom + 4 });
  };

  // Determine which files are visible when a tag filter is active
  const isFileVisible = (file, index) => {
    if (file.type === 'divider') {
      if (activeTagFilter || searchFilter) return false;
      return true;
    }
    if (activeTagFilter) {
      const tags = fileTags && fileTags[index];
      if (!tags || !tags.includes(activeTagFilter)) return false;
    }
    if (searchFilter) {
      const query = searchFilter.toLowerCase();
      if (!file.key.toLowerCase().includes(query)) return false;
    }
    // Display filter by file type
    if (dropboxFileMode === 'imgs' && file.type !== 'image' && file.type !== 'video') return false;
    if (dropboxFileMode === 'txt' && !['text', 'markdown', 'rtf', 'docx', 'html'].includes(file.type)) return false;
    return true;
  };

  if (!isOpen) return null;

  return (
    <div className="sidebar">
      <div className="sidebar-header">
        <input
          className="sidebar-search-input"
          type="text"
          placeholder={activeTagFilter ? `Filter $${activeTagFilter}...` : 'Filter files...'}
          value={searchFilter}
          onChange={(e) => setSearchFilter(e.target.value)}
        />
        <button className="sidebar-paths-btn" onClick={() => setShowAllPaths(true)} title="Show all folder paths">
          &#128193;
        </button>
        <button
          className={`sidebar-sort-btn ${sortMode === 'date' ? 'active' : ''}`}
          onClick={() => setSortMode(sortMode === 'date' ? null : 'date')}
          title={sortMode === 'date' ? 'Sort by folder (default)' : 'Sort by last modified'}
        >
          &#128337;
        </button>
        <button
          className={`sidebar-sort-btn ${sortMode === 'name' ? 'active' : ''}`}
          onClick={() => setSortMode(sortMode === 'name' ? null : 'name')}
          title={sortMode === 'name' ? 'Sort by folder (default)' : 'Sort by name'}
        >
          A&#8595;
        </button>
        <button className="sidebar-next-btn" onClick={onNext} title="Next file">
          &raquo;
        </button>
        <button className="sidebar-close-btn" onClick={onClose} title="Close sidebar">
          &times;
        </button>
      </div>
      <div className="sidebar-content">
        {(() => {
          if (sortMode === 'date' || sortMode === 'name') {
            // Build sorted list: non-divider files sorted by date or name
            const indexedFiles = files
              .map((file, index) => ({ file, index }))
              .filter(({ file, index }) => file.type !== 'divider' && isFileVisible(file, index))
              .sort((a, b) => {
                if (sortMode === 'date') {
                  const aDate = a.file.serverModified || a.file.file?.lastModified || 0;
                  const bDate = b.file.serverModified || b.file.file?.lastModified || 0;
                  const aTime = typeof aDate === 'number' ? aDate : new Date(aDate).getTime();
                  const bTime = typeof bDate === 'number' ? bDate : new Date(bDate).getTime();
                  return bTime - aTime; // newest first
                } else {
                  const aName = (a.file.originalName || a.file.key).toLowerCase();
                  const bName = (b.file.originalName || b.file.key).toLowerCase();
                  return aName.localeCompare(bName);
                }
              });
            return indexedFiles.map(({ file, index }) => (
              <div
                key={`${file.key}-${index}`}
                className={`sidebar-item ${index === currentIndex ? 'active' : ''}`}
                onClick={() => handleClick(file, index)}
              >
                <span className="sidebar-item-label" style={{ flex: 1 }}>
                  {file.originalName || file.key}
                </span>
                {sortMode === 'date' && file.serverModified && (
                  <span className="sidebar-item-date">{formatModifiedDate(file.serverModified)}</span>
                )}
              </div>
            ));
          }
          return files.map((file, index) => {
            if (!isFileVisible(file, index)) return null;
            return (
              <div
                key={`${file.key}-${index}`}
                className={`sidebar-item ${index === currentIndex ? 'active' : ''} ${file.type === 'divider' ? 'divider' : ''}`}
                onClick={() => handleClick(file, index)}
              >
                <span
                  className="sidebar-item-label"
                  title={file.type === 'divider' ? file.key : undefined}
                  onClick={(e) => handleLabelClick(e, index)}
                >
                  {file.type === 'divider' ? shortenDividerPath(file.key) : (file.originalName || file.key)}
                </span>
              </div>
            );
          });
        })()}
      </div>

      {pathModal && (
        <div className="sidebar-path-modal-overlay" onClick={() => setPathModal(null)}>
          <div
            className="sidebar-path-modal"
            style={{ left: pathModal.x, top: pathModal.y }}
            onClick={(e) => e.stopPropagation()}
          >
            <div className="sidebar-path-modal-text">{pathModal.path}</div>
            <button className="sidebar-path-modal-close" onClick={() => setPathModal(null)}>&times;</button>
          </div>
        </div>
      )}

      {showAllPaths && (
        <div className="sidebar-all-paths-overlay" onClick={() => setShowAllPaths(false)}>
          <div className="sidebar-all-paths-modal" onClick={(e) => e.stopPropagation()}>
            <div className="sidebar-all-paths-header">
              <span>All Folder Paths</span>
              <button className="sidebar-path-modal-close" onClick={() => setShowAllPaths(false)}>&times;</button>
            </div>
            <div className="sidebar-all-paths-list">
              {files
                .filter(f => f.type === 'divider')
                .map((f, i) => (
                  <div key={i} className="sidebar-all-paths-item">{f.key}</div>
                ))}
            </div>
          </div>
        </div>
      )}

      <div className="sidebar-tail">
        {mdHeadings && mdHeadings.length > 0 && (
          <>
            <div className="sidebar-toc-header">OUTLINE</div>
            {mdHeadings.map((h, i) => (
              <div
                key={i}
                className={`sidebar-toc-item sidebar-toc-level-${h.level}`}
                style={{ paddingLeft: `${(h.level - 1) * 14 + 10}px` }}
                onClick={() => onScrollToHeading && onScrollToHeading(h.id)}
              >
                {h.text}
              </div>
            ))}
          </>
        )}
      </div>
    </div>
  );
}

export default Sidebar;
