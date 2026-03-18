import React, { useState, useEffect, useCallback, useMemo } from 'react';
import Sidebar from './components/Sidebar';
import ContentViewer from './components/ContentViewer';
import ControlBar from './components/ControlBar';
import DropboxBrowser from './components/DropboxBrowser';
import { processFiles, processDropboxFolder, extractHashtags, isTextFile, isMarkdownFile } from './utils/fileUtils';
import { useDropbox } from './hooks/useDropbox';

function TextViewer() {
  const [files, setFiles] = useState([]);
  const [currentIndex, setCurrentIndex] = useState(0);
  const [displayedFileIndex, setDisplayedFileIndex] = useState(0);
  const [fontSize, setFontSize] = useState(18);
  const [darkMode, setDarkMode] = useState(false);
  const [isEditing, setIsEditing] = useState(false);
  const [dropboxBrowserOpen, setDropboxBrowserOpen] = useState(false);
  const [dropboxRecursiveOpen, setDropboxRecursiveOpen] = useState(false);

  const [editContent, setEditContent] = useState('');
  const [imagePathToBlobUrl, setImagePathToBlobUrl] = useState({});
  const [dropboxFolderPath, setDropboxFolderPath] = useState(null);
  const [isDropboxNonRecursive, setIsDropboxNonRecursive] = useState(false);


  // PDF state
  const [pdfState, setPdfState] = useState(null);
  const [pdfDocument, setPdfDocument] = useState(null);

  // Tag filter state
  const [fileTags, setFileTags] = useState({}); // { fileIndex: ['tag1', 'tag2'] }
  const [activeTagFilter, setActiveTagFilter] = useState(null);

  // Syntax highlighting state
  const [syntaxHighlightEnabled, setSyntaxHighlightEnabled] = useState(false);

  // Slideshow state
  const [slideshowEnabled, setSlideshowEnabled] = useState(false);

  // Sidebar visibility state
  const [showSidebar, setShowSidebar] = useState(true);

  // Text wrap toggle
  const [wrapText, setWrapText] = useState(false);

  // Markdown TOC / heading navigation
  const [mdHeadings, setMdHeadings] = useState([]);
  const [scrollToHeadingId, setScrollToHeadingId] = useState(null);

  // Dropbox hook
  const dropbox = useDropbox();

  const currentFile = files[currentIndex] || null;
  const displayedFile = files[displayedFileIndex] || null;

  // Derive sorted unique tags with counts from fileTags
  const allTags = useMemo(() => {
    const tagCounts = {};
    Object.values(fileTags).forEach(tags => {
      tags.forEach(tag => {
        tagCounts[tag] = (tagCounts[tag] || 0) + 1;
      });
    });
    return Object.entries(tagCounts)
      .sort((a, b) => a[0].localeCompare(b[0]))
      .map(([tag, count]) => ({ tag, count }));
  }, [fileTags]);

  const handleFilesLoaded = useCallback((fileList) => {
    const result = processFiles(fileList);

    if (result.error) {
      alert(result.error);
      return;
    }

    const firstDisplayableIndex = result.files.findIndex(
      f => f.type !== 'divider'
    );

    setFiles(result.files);
    setImagePathToBlobUrl(result.imagePathToBlobUrl || {});
    setCurrentIndex(0);
    setDisplayedFileIndex(firstDisplayableIndex >= 0 ? firstDisplayableIndex : 0);
    setIsEditing(false);
    setEditContent('');
    setPdfState(null);
    setFileTags({});
    setActiveTagFilter(null);
    setIsDropboxNonRecursive(false);
    setDropboxFolderPath(null);

    // Async scan text/md files for hashtags
    const loadedFiles = result.files;
    loadedFiles.forEach((file, index) => {
      if (file.url && file.originalName && (isTextFile(file.originalName) || isMarkdownFile(file.originalName))) {
        fetch(file.url)
          .then(res => res.text())
          .then(text => {
            const tags = extractHashtags(text);
            if (tags.length > 0) {
              setFileTags(prev => ({ ...prev, [index]: tags }));
            }
          })
          .catch(() => {});
      }
    });
  }, []);

  const handleDropboxFolderSelected = useCallback((entries, folderPath, nonRecursive = false) => {
    const result = processDropboxFolder(entries, folderPath);

    if (result.error) {
      alert(result.error);
      return;
    }

    const firstDisplayableIndex = result.files.findIndex(
      f => f.type !== 'divider'
    );

    setFiles(result.files);
    setImagePathToBlobUrl(result.imagePathToBlobUrl || {});
    setCurrentIndex(0);
    setDisplayedFileIndex(firstDisplayableIndex >= 0 ? firstDisplayableIndex : 0);
    setIsEditing(false);
    setEditContent('');
    setPdfState(null);
    setFileTags({});
    setActiveTagFilter(null);
    setDropboxBrowserOpen(false);
    setDropboxFolderPath(folderPath);
    setIsDropboxNonRecursive(nonRecursive);
  }, []);

  // Lazy-download a Dropbox file if it hasn't been fetched yet
  const ensureFileDownloaded = useCallback(async (index) => {
    const file = files[index];
    if (!file || file.url !== null || !file.dropboxPath) return;

    const blob = await dropbox.downloadFile(file.dropboxPath);
    if (!blob) return;

    const blobUrl = URL.createObjectURL(blob);
    setFiles(prevFiles => {
      const updated = [...prevFiles];
      updated[index] = { ...updated[index], url: blobUrl };
      return updated;
    });

    // Extract tags from downloaded text/md files
    if (file.originalName && (isTextFile(file.originalName) || isMarkdownFile(file.originalName))) {
      blob.text().then(text => {
        const tags = extractHashtags(text);
        if (tags.length > 0) {
          setFileTags(prev => ({ ...prev, [index]: tags }));
        }
      }).catch(() => {});
    }
  }, [files, dropbox]);

  const handleFileSelect = useCallback((index) => {
    if (isEditing) {
      if (!window.confirm('You have unsaved changes. Discard them?')) {
        return;
      }
    }
    setCurrentIndex(index);
    setIsEditing(false);
    setEditContent('');
    setPdfState(null);
    ensureFileDownloaded(index);
  }, [isEditing, ensureFileDownloaded]);

  const handlePrev = useCallback(() => {
    if (files.length === 0) return;
    const nextIndex = currentIndex > 0 ? currentIndex - 1 : files.length - 1;

    if (isEditing) {
      if (!window.confirm('You have unsaved changes. Discard them?')) {
        return;
      }
      setIsEditing(false);
    }
    setCurrentIndex(nextIndex);
    ensureFileDownloaded(nextIndex);
  }, [files, currentIndex, isEditing, ensureFileDownloaded]);

  const handleNext = useCallback(() => {
    if (files.length === 0) return;
    const nextIndex = currentIndex < files.length - 1 ? currentIndex + 1 : 0;

    if (isEditing) {
      if (!window.confirm('You have unsaved changes. Discard them?')) {
        return;
      }
      setIsEditing(false);
    }
    setCurrentIndex(nextIndex);
    ensureFileDownloaded(nextIndex);
  }, [files, currentIndex, isEditing, ensureFileDownloaded]);

  const handleFontSizeChange = useCallback((delta) => {
    setFontSize((prev) => Math.max(10, Math.min(40, prev + delta)));
  }, []);

  const handleDarkModeToggle = useCallback(() => {
    setDarkMode((prev) => !prev);
  }, []);

  const handleCopyContent = useCallback(() => {
    if (!displayedFile || (displayedFile.type !== 'text' && displayedFile.type !== 'rtf' && displayedFile.type !== 'markdown')) return;

    fetch(displayedFile.url)
      .then(res => res.text())
      .then(text => {
        navigator.clipboard.writeText(text).then(() => {
          // Brief visual feedback could be added here
        }).catch(err => {
          console.error('Failed to copy:', err);
        });
      })
      .catch(err => {
        console.error('Error loading file for copy:', err);
      });
  }, [displayedFile]);

  const handlePasteContent = useCallback(async () => {
    try {
      const text = await navigator.clipboard.readText();
      if (!text) return;

      const blob = new Blob([text], { type: 'text/markdown' });
      const url = URL.createObjectURL(blob);
      const pasteFile = {
        key: 'Clipboard Paste',
        originalName: 'clipboard.md',
        type: 'markdown',
        url,
      };

      setFiles(prev => {
        const newFiles = [pasteFile, ...prev];
        return newFiles;
      });
      setCurrentIndex(0);
      setDisplayedFileIndex(0);
      setIsEditing(false);
      setEditContent('');
      setPdfState(null);
    } catch (err) {
      console.error('Failed to read clipboard:', err);
    }
  }, []);

  const handleSave = useCallback(async () => {
    if (!displayedFile || !isEditing) return;

    // Create a new blob with the edited content
    const blob = new Blob([editContent], { type: 'text/plain' });
    const newUrl = URL.createObjectURL(blob);

    // Update the file in the array
    setFiles(prevFiles => {
      const newFiles = [...prevFiles];
      newFiles[displayedFileIndex] = {
        ...newFiles[displayedFileIndex],
        url: newUrl
      };
      return newFiles;
    });

    // If Dropbox file, upload back; otherwise download locally
    if (displayedFile.dropboxPath) {
      const result = await dropbox.uploadFile(displayedFile.dropboxPath, editContent, 'overwrite');
      if (!result) {
        alert('Failed to save to Dropbox');
      }
    } else {
      const a = document.createElement('a');
      a.href = newUrl;
      a.download = displayedFile.originalName || `${displayedFile.key}.txt`;
      a.click();
    }

    setIsEditing(false);
    setEditContent('');
  }, [displayedFile, displayedFileIndex, editContent, isEditing, dropbox]);

  const handleCancel = useCallback(() => {
    setIsEditing(false);
    setEditContent('');
  }, []);

  // Edit: enter edit mode and load file content
  const handleEdit = useCallback(async () => {
    if (!displayedFile || !displayedFile.dropboxPath) return;
    if (!['text', 'markdown'].includes(displayedFile.type)) return;

    if (displayedFile.url) {
      const res = await fetch(displayedFile.url);
      const text = await res.text();
      setEditContent(text);
      setIsEditing(true);
    }
  }, [displayedFile]);

  // Rename: rename file on Dropbox
  const handleRename = useCallback(async () => {
    if (!displayedFile || !displayedFile.dropboxPath) return;

    const currentName = displayedFile.originalName;
    let newName = window.prompt('Rename file:', currentName);
    if (!newName || newName === currentName) return;

    // Auto-add .txt if no extension specified
    if (!newName.includes('.')) {
      newName += '.txt';
    }

    const pathParts = displayedFile.dropboxPath.split('/');
    pathParts[pathParts.length - 1] = newName;
    const newPath = pathParts.join('/');

    const result = await dropbox.moveFile(displayedFile.dropboxPath, newPath);
    if (result) {
      setFiles(prevFiles => {
        const newFiles = [...prevFiles];
        const oldKey = newFiles[displayedFileIndex].key;
        const prefix = oldKey.match(/^\d+_/)?.[0] || '';
        const newDisplayName = newName.replace(/\.[^.]+$/, '');
        newFiles[displayedFileIndex] = {
          ...newFiles[displayedFileIndex],
          dropboxPath: result.metadata?.path_lower || newPath,
          originalName: newName,
          key: prefix + newDisplayName,
        };
        return newFiles;
      });
    } else {
      alert('Failed to rename on Dropbox');
    }
  }, [displayedFile, displayedFileIndex, dropbox]);

  // New File: create from clipboard in current Dropbox folder
  const handleNewFile = useCallback(async () => {
    if (!dropboxFolderPath || !dropbox.accessToken) return;

    let text;
    try {
      text = await navigator.clipboard.readText();
    } catch {
      alert('Failed to read clipboard');
      return;
    }
    if (!text) {
      alert('Clipboard is empty');
      return;
    }

    let fileName = window.prompt('New file name:', 'new_file.md');
    if (!fileName) return;

    // Auto-add .txt if no extension specified
    if (!fileName.includes('.')) {
      fileName += '.txt';
    }

    const uploadPath = `${dropboxFolderPath}/${fileName}`;
    const result = await dropbox.uploadFile(uploadPath, text, 'add');
    if (result) {
      const newFile = {
        key: fileName.replace(/\.[^.]+$/, ''),
        type: fileName.endsWith('.md') ? 'markdown' : 'text',
        url: URL.createObjectURL(new Blob([text], { type: 'text/plain' })),
        dropboxPath: result.path_lower || uploadPath,
        originalName: fileName,
      };
      setFiles(prev => [...prev, newFile]);
      setCurrentIndex(files.length);
    } else {
      alert('Failed to create file on Dropbox');
    }
  }, [dropboxFolderPath, dropbox, files.length]);

  // Copy current PDF page text to clipboard
  const handleCopyPageText = useCallback(async () => {
    if (!pdfDocument || !pdfState) {
      console.log('No PDF document loaded');
      return;
    }

    try {
      const page = await pdfDocument.getPage(pdfState.currentPage);
      const textContent = await page.getTextContent();
      const pageText = textContent.items.map(item => item.str).join(' ');

      if (!pageText) {
        console.log('No text content available on current page');
        return;
      }

      await navigator.clipboard.writeText(pageText);
      console.log('Page text copied to clipboard:', pageText.length, 'characters');
    } catch (error) {
      console.error('Failed to copy text:', error);
      alert('Failed to copy text to clipboard');
    }
  }, [pdfDocument, pdfState]);


  // Keyboard navigation
  useEffect(() => {
    const handleKeyDown = (e) => {
      // Don't handle keys when editing or in input fields
      if (isEditing) return;
      if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return;

      if (e.key === 'ArrowLeft') {
        handlePrev();
      } else if (e.key === 'ArrowRight') {
        handleNext();
      } else if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
        e.preventDefault();
        const scrollEl = document.querySelector('.preview-text') || document.querySelector('.preview-markdown') || document.querySelector('.pdf-canvas-container') || document.querySelector('.csv-table-wrapper');
        if (scrollEl) {
          const scrollAmount = scrollEl.clientHeight * 0.8;
          scrollEl.scrollBy({ top: e.key === 'ArrowUp' ? -scrollAmount : scrollAmount, behavior: 'smooth' });
        }
      } else if (e.key === 'Enter') {
        handleNext();
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [handlePrev, handleNext, isEditing]);

  // Global drag and drop
  useEffect(() => {
    const handleDragOver = (e) => {
      e.preventDefault();
    };

    const handleDrop = (e) => {
      e.preventDefault();
    };

    window.addEventListener('dragover', handleDragOver);
    window.addEventListener('drop', handleDrop);

    return () => {
      window.removeEventListener('dragover', handleDragOver);
      window.removeEventListener('drop', handleDrop);
    };
  }, []);

  // Handle navigation to different file types
  useEffect(() => {
    if (!currentFile) return;

    if (currentFile.type !== 'divider') {
      setDisplayedFileIndex(currentIndex);
    }
  }, [currentIndex, currentFile]);

  // Slideshow: cycle through image files on a timer
  useEffect(() => {
    if (!slideshowEnabled || files.length === 0) return;

    // Collect indices of image files
    const imageIndices = files
      .map((f, i) => (f.type === 'image' ? i : -1))
      .filter(i => i !== -1);

    if (imageIndices.length < 2) return;

    const interval = setInterval(() => {
      setCurrentIndex(prev => {
        const currentPos = imageIndices.indexOf(prev);
        const nextPos = (currentPos + 1) % imageIndices.length;
        // If current isn't an image, start from the first image
        if (currentPos === -1) return imageIndices[0];
        const nextIdx = imageIndices[nextPos];
        ensureFileDownloaded(nextIdx);
        return nextIdx;
      });
    }, 4000);

    return () => clearInterval(interval);
  }, [slideshowEnabled, files, ensureFileDownloaded]);

  // Auto-open Dropbox browser after OAuth redirect
  useEffect(() => {
    if (dropbox.isAuthenticated && sessionStorage.getItem('dropbox_pending_browse')) {
      sessionStorage.removeItem('dropbox_pending_browse');
      setDropboxBrowserOpen(true);
    }
  }, [dropbox.isAuthenticated]);

  return (
    <div className={`text-viewer ${darkMode ? 'dark-mode' : ''}`}>
      <Sidebar
        files={files}
        currentIndex={currentIndex}
        onFileSelect={handleFileSelect}
        onNext={handleNext}
        isOpen={showSidebar}
        onClose={() => setShowSidebar(false)}
        activeTagFilter={activeTagFilter}
        fileTags={fileTags}
        mdHeadings={mdHeadings}
        onScrollToHeading={setScrollToHeadingId}
      />

      <div className="main-content">
        <ControlBar
          currentFile={displayedFile}
          fontSize={fontSize}
          showSidebar={showSidebar}
          onToggleSidebar={() => setShowSidebar(prev => !prev)}
          onFontSizeChange={handleFontSizeChange}
          darkMode={darkMode}
          onDarkModeToggle={handleDarkModeToggle}
          isEditing={isEditing}
          onCopyContent={handleCopyContent}
          onPasteContent={handlePasteContent}
          onSave={handleSave}
          onCancel={handleCancel}
          onFilesLoaded={handleFilesLoaded}
          pdfState={pdfState}
          onPdfStateChange={setPdfState}
          onCopyPageText={handleCopyPageText}
          syntaxHighlightEnabled={syntaxHighlightEnabled}
          onToggleSyntaxHighlight={() => setSyntaxHighlightEnabled(prev => !prev)}
          onOpenDropbox={() => setDropboxBrowserOpen(true)}
          onOpenDropboxRecursive={() => setDropboxRecursiveOpen(true)}
          slideshowEnabled={slideshowEnabled}
          onToggleSlideshow={() => setSlideshowEnabled(prev => !prev)}
          hasImages={files.some(f => f.type === 'image')}
          allTags={allTags}
          activeTagFilter={activeTagFilter}
          onTagFilterChange={setActiveTagFilter}
          wrapText={wrapText}
          onToggleWrapText={() => setWrapText(prev => !prev)}
          onEdit={handleEdit}
          onRename={handleRename}
          onNewFile={handleNewFile}
          isDropboxNonRecursive={isDropboxNonRecursive}
        />

        <ContentViewer
          file={displayedFile}
          fontSize={fontSize}
          isEditing={isEditing}
          editContent={editContent}
          onEditChange={setEditContent}
          imagePathToBlobUrl={imagePathToBlobUrl}
          onPrev={handlePrev}
          onNext={handleNext}
          pdfState={pdfState}
          onPdfStateChange={setPdfState}
          onPdfDocumentLoad={setPdfDocument}
          syntaxHighlightEnabled={syntaxHighlightEnabled}
          wrapText={wrapText}
          onHeadingsExtracted={setMdHeadings}
          scrollToHeadingId={scrollToHeadingId}
          onScrollToHeadingDone={() => setScrollToHeadingId(null)}
        />

      </div>

      <DropboxBrowser
        isOpen={dropboxBrowserOpen}
        onClose={() => setDropboxBrowserOpen(false)}
        onFolderSelected={(entries, folderPath) => handleDropboxFolderSelected(entries, folderPath, true)}
        dropbox={dropbox}
      />

      <DropboxBrowser
        isOpen={dropboxRecursiveOpen}
        onClose={() => setDropboxRecursiveOpen(false)}
        onFolderSelected={(entries, folderPath) => handleDropboxFolderSelected(entries, folderPath, false)}
        dropbox={dropbox}
        recursive
      />

    </div>
  );
}

export default TextViewer;
