import React, { useState, useCallback } from 'react';

function DropboxBrowser({ isOpen, onClose, onFolderSelected, dropbox, recursive }) {
  const [searchQuery, setSearchQuery] = useState('');
  const [searchResults, setSearchResults] = useState([]);
  const [folderContents, setFolderContents] = useState([]);
  const [currentPath, setCurrentPath] = useState('');
  const [breadcrumbs, setBreadcrumbs] = useState([]);
  const [loading, setLoading] = useState(false);

  const handleSearch = useCallback(async () => {
    if (!searchQuery.trim()) return;
    setLoading(true);
    setFolderContents([]);
    setCurrentPath('');
    setBreadcrumbs([]);

    const folders = await dropbox.searchFolders(searchQuery);
    setSearchResults(folders);
    setLoading(false);
  }, [searchQuery, dropbox]);

  const handleFolderClick = useCallback(async (path, name) => {
    setLoading(true);
    setSearchResults([]);

    const entries = await dropbox.listFolder(path);
    setFolderContents(entries);
    setCurrentPath(path);

    // Build breadcrumbs from path
    const parts = path.split('/').filter(Boolean);
    const crumbs = parts.map((part, i) => ({
      name: part,
      path: '/' + parts.slice(0, i + 1).join('/'),
    }));
    setBreadcrumbs(crumbs);

    setLoading(false);
  }, [dropbox]);

  const handleClose = useCallback(() => {
    setSearchQuery('');
    setSearchResults([]);
    setFolderContents([]);
    setCurrentPath('');
    setBreadcrumbs([]);
    setLoading(false);
    onClose();
  }, [onClose]);

  const handleLoadFolder = useCallback(async () => {
    if (folderContents.length === 0) return;

    if (recursive && dropbox.listFolderRecursive) {
      setLoading(true);
      const allEntries = await dropbox.listFolderRecursive(currentPath);
      setLoading(false);
      onFolderSelected(allEntries, currentPath);
    } else {
      onFolderSelected(folderContents, currentPath);
    }
    handleClose();
  }, [folderContents, currentPath, onFolderSelected, handleClose, recursive, dropbox]);

  if (!isOpen) return null;

  return (
    <div className="dropbox-browser-overlay" onClick={handleClose}>
      <div className="dropbox-browser-modal" onClick={(e) => e.stopPropagation()}>
        <div className="dropbox-browser-header">
          <h3>Dropbox Browser</h3>
          {dropbox.status && <span className="dropbox-browser-status">{dropbox.status}</span>}
          <button className="dropbox-browser-close" onClick={handleClose}>&times;</button>
        </div>

        {!dropbox.isAuthenticated ? (
          <div className="dropbox-browser-signin">
            <button className="dropbox-browser-signin-btn" onClick={dropbox.signIn}>
              Sign in with Dropbox
            </button>
          </div>
        ) : (
          <div className="dropbox-browser-content">
            <div className="dropbox-browser-search">
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                placeholder="Search for a folder..."
                autoFocus
              />
              <button onClick={handleSearch} disabled={loading}>
                {loading ? '...' : 'Search'}
              </button>
              <button className="dropbox-browser-signout" onClick={dropbox.signOut}>
                Sign Out
              </button>
            </div>

            {/* Breadcrumbs */}
            {breadcrumbs.length > 0 && (
              <div className="dropbox-browser-breadcrumb">
                {breadcrumbs.map((crumb, i) => (
                  <span key={crumb.path}>
                    {i > 0 && ' / '}
                    <span
                      className="dropbox-browser-breadcrumb-link"
                      onClick={() => handleFolderClick(crumb.path, crumb.name)}
                    >
                      {crumb.name}
                    </span>
                  </span>
                ))}
              </div>
            )}

            {/* Search results (folders) */}
            {searchResults.length > 0 && (
              <div className="dropbox-browser-list">
                {searchResults.map((folder) => (
                  <div
                    key={folder.path}
                    className="dropbox-browser-item folder"
                    onClick={() => handleFolderClick(folder.path, folder.name)}
                  >
                    <span className="dropbox-browser-icon">📁</span>
                    <span className="dropbox-browser-name">{folder.name}</span>
                    <span className="dropbox-browser-path">{folder.path}</span>
                  </div>
                ))}
              </div>
            )}

            {/* Folder contents */}
            {folderContents.length > 0 && (
              <>
                <div className="dropbox-browser-list">
                  {folderContents.map((entry) => (
                    <div
                      key={entry.path}
                      className={`dropbox-browser-item ${entry.isFolder ? 'folder' : 'file'}`}
                      onClick={entry.isFolder ? () => handleFolderClick(entry.path, entry.name) : undefined}
                    >
                      <span className="dropbox-browser-icon">
                        {entry.isFolder ? '📁' : '📄'}
                      </span>
                      <span className="dropbox-browser-name">{entry.name}</span>
                    </div>
                  ))}
                </div>
                <div className="dropbox-browser-footer">
                  <button
                    className="dropbox-browser-load-btn"
                    onClick={handleLoadFolder}
                  >
                    {recursive ? 'Load All Subfolders' : 'Load This Folder'} ({folderContents.filter(e => !e.isFolder).length} files)
                  </button>
                </div>
              </>
            )}

            {loading && <div className="dropbox-browser-loading">Loading...</div>}
          </div>
        )}
      </div>
    </div>
  );
}

export default DropboxBrowser;
