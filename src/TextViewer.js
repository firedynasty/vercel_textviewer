import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import Sidebar from './components/Sidebar';
import ContentViewer from './components/ContentViewer';
import ControlBar from './components/ControlBar';
import DropboxBrowser from './components/DropboxBrowser';
import { processFiles, processDropboxFolder, extractHashtags, isTextFile, isMarkdownFile, isImageFile } from './utils/fileUtils';
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
  const [dropboxFileMode, setDropboxFileMode] = useState('all'); // 'all', 'imgs', or 'txt'
  const [isLocalFS, setIsLocalFS] = useState(false);
  const [fileHandles, setFileHandles] = useState({}); // index -> FileSystemFileHandle


  // PDF state
  const [pdfState, setPdfState] = useState(null);
  const [pdfDocument, setPdfDocument] = useState(null);
  const [currentPdfPageText, setCurrentPdfPageText] = useState('');

  // Pin / ruler state
  const [isPinned, setIsPinned] = useState(true);
  const [controlBarVisible, setControlBarVisible] = useState(true);
  const [rulerEnabled, setRulerEnabled] = useState(false);
  const [rulerPos, setRulerPos] = useState({ x: 0, y: 0 });
  const hideTimerRef = useRef(null);
  const isPinnedRef = useRef(true); // mirrors isPinned without closure issues

  // Tag filter state
  const [fileTags, setFileTags] = useState({}); // { fileIndex: ['tag1', 'tag2'] }
  const [activeTagFilter, setActiveTagFilter] = useState(null);

  // Syntax highlighting state
  const [syntaxHighlightEnabled, setSyntaxHighlightEnabled] = useState(false);

  // Slideshow state
  const [slideshowEnabled, setSlideshowEnabled] = useState(false);

  // Sidebar visibility state
  const [showSidebar, setShowSidebar] = useState(true);

  // Reader mode (immersive fullscreen — hides sidebar + control bar, "/" to toggle)
  const [readerMode, setReaderMode] = useState(false);

  // Text wrap toggle
  const [wrapText, setWrapText] = useState(false);

  // Raw text content of the currently displayed text/markdown file
  const [currentTextContent, setCurrentTextContent] = useState('');

  // Markdown TOC / heading navigation
  const [mdHeadings, setMdHeadings] = useState([]);
  const [scrollToHeadingId, setScrollToHeadingId] = useState(null);

  // Persistent audio player (survives non-audio file clicks)
  const [persistentAudio, setPersistentAudio] = useState(null); // { url, name }

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
    setDropboxFileMode('all');
    setActiveTagFilter(null);
    setIsDropboxNonRecursive(false);
    setDropboxFolderPath(null);
    setIsLocalFS(false);
    setFileHandles({});

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

  const handleLocalDirOpen = useCallback(async (recursive = false) => {
    if (!window.showDirectoryPicker) {
      alert('Your browser does not support the File System Access API. Please use Chrome or Edge.');
      return;
    }
    try {
      const dirHandle = await window.showDirectoryPicker();
      const files = [];

      // Walk the directory tree, optionally recursive
      const walkDir = async (handle, pathPrefix, depth = 0) => {
        for await (const [name, entry] of handle.entries()) {
          if (entry.kind === 'file') {
            const file = await entry.getFile();
            const relativePath = pathPrefix + name;
            Object.defineProperty(file, 'webkitRelativePath', {
              value: relativePath,
              writable: false
            });
            files.push({ file, handle: entry, relativePath });
          } else if (entry.kind === 'directory' && (recursive || depth < 0)) {
            await walkDir(entry, pathPrefix + name + '/', depth + 1);
          }
        }
      };

      await walkDir(dirHandle, dirHandle.name + '/', 0);

      if (files.length === 0) {
        alert('No files found in the selected directory.');
        return;
      }

      const fileList = files.map(f => f.file);
      const result = processFiles(fileList);

      if (result.error) {
        alert(result.error);
        return;
      }

      // Map file handles by matching relativePath in result.files
      const handleMap = {};
      result.files.forEach((item, index) => {
        if (item.originalName && item.file) {
          const filePath = item.file.webkitRelativePath || item.file.name;
          const match = files.find(f => f.relativePath === filePath);
          if (match) {
            handleMap[index] = match.handle;
          }
        }
      });

      const firstDisplayableIndex = result.files.findIndex(f => f.type !== 'divider');

      setFiles(result.files);
      setImagePathToBlobUrl(result.imagePathToBlobUrl || {});
      setCurrentIndex(0);
      setDisplayedFileIndex(firstDisplayableIndex >= 0 ? firstDisplayableIndex : 0);
      setIsEditing(false);
      setEditContent('');
      setPdfState(null);
      setFileTags({});
      setDropboxFileMode('all');
      setActiveTagFilter(null);
      setIsDropboxNonRecursive(false);
      setDropboxFolderPath(null);
      setIsLocalFS(true);
      setFileHandles(handleMap);

      // Scan for hashtags
      result.files.forEach((file, index) => {
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
    } catch (err) {
      if (err.name !== 'AbortError') {
        console.error('Failed to open directory:', err);
        alert('Failed to open directory: ' + err.message);
      }
    }
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
    setIsLocalFS(false);
    setFileHandles({});
  }, []);

  // Lazy-download a Dropbox file if it hasn't been fetched yet
  const ensureFileDownloaded = useCallback(async (index) => {
    const file = files[index];
    if (!file || file.url !== null || !file.dropboxPath) return;

    // Prefer dropboxId (always ASCII) over path (may contain Unicode like macOS
    // narrow no-break space U+202F in screenshot filenames) — HTTP headers require ISO-8859-1.
    const downloadRef = file.dropboxId || file.dropboxPath;
    const blob = await dropbox.downloadFile(downloadRef);
    if (!blob) return;

    const blobUrl = URL.createObjectURL(blob);
    setFiles(prevFiles => {
      const updated = [...prevFiles];
      updated[index] = { ...updated[index], url: blobUrl };
      return updated;
    });

    // Extract tags from text/md files
    if (file.originalName && (isTextFile(file.originalName) || isMarkdownFile(file.originalName))) {
      blob.text().then(text => {
        const tags = extractHashtags(text);
        if (tags.length > 0) {
          setFileTags(prev => ({ ...prev, [index]: tags }));
        }
      }).catch(() => {});
    }
  }, [files, dropbox]);

  // On-demand image fetch for the markdown image modal.
  // Tries imagePathToBlobUrl first, then the files list (DB-recursive),
  // then listFolder (DB-nonrecursive).
  const handleImageRequest = useCallback(async (href, fileName) => {
    // 1. Already cached
    const decoded = decodeURIComponent(href);
    for (const key of [href, decoded, fileName]) {
      if (imagePathToBlobUrl[key]) return imagePathToBlobUrl[key];
    }

    // 2. Image listed in files (DB-recursive) — download if needed
    const imageFile = files.find(f => f.type === 'image' && f.originalName === fileName);
    if (imageFile) {
      if (imageFile.url) {
        setImagePathToBlobUrl(prev => ({ ...prev, [fileName]: imageFile.url, [href]: imageFile.url }));
        return imageFile.url;
      }
      if (imageFile.dropboxPath) {
        const ref = imageFile.dropboxId || imageFile.dropboxPath;
        const blob = await dropbox.downloadFile(ref);
        if (blob) {
          const url = URL.createObjectURL(blob);
          setImagePathToBlobUrl(prev => ({ ...prev, [fileName]: url, [href]: url }));
          return url;
        }
      }
    }

    // 3. listFolder fallback (DB-nonrecursive or local absolute paths)
    if (displayedFile?.dropboxPath) {
      const mdFolder = displayedFile.dropboxPath.substring(0, displayedFile.dropboxPath.lastIndexOf('/'));
      const topEntries = await dropbox.listFolder(mdFolder);
      const allEntries = [...topEntries];
      for (const sub of topEntries.filter(e => e.isFolder)) {
        const subEntries = await dropbox.listFolder(sub.path);
        allEntries.push(...subEntries);
      }
      const entry = allEntries.find(e => !e.isFolder && e.name === fileName);
      if (entry) {
        const ref = entry.id || entry.path;
        const blob = await dropbox.downloadFile(ref);
        if (blob) {
          const url = URL.createObjectURL(blob);
          setImagePathToBlobUrl(prev => ({ ...prev, [fileName]: url, [href]: url }));
          return url;
        }
      }
    }

    // 4. Local file — imagePathToBlobUrl should already have it by filename
    return null;
  }, [files, dropbox, imagePathToBlobUrl, displayedFile]);

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

  const matchesFileMode = useCallback((file) => {
    if (dropboxFileMode === 'all') return file.type !== 'divider';
    if (dropboxFileMode === 'imgs') return file.type === 'image' || file.type === 'video';
    if (dropboxFileMode === 'txt') return ['text', 'markdown', 'rtf', 'docx', 'html'].includes(file.type);
    return file.type !== 'divider';
  }, [dropboxFileMode]);

  const handlePrev = useCallback(() => {
    if (files.length === 0) return;

    if (isEditing) {
      if (!window.confirm('You have unsaved changes. Discard them?')) {
        return;
      }
      setIsEditing(false);
    }

    let nextIndex = currentIndex;
    for (let i = 0; i < files.length; i++) {
      nextIndex = nextIndex > 0 ? nextIndex - 1 : files.length - 1;
      if (matchesFileMode(files[nextIndex])) break;
    }
    setCurrentIndex(nextIndex);
    ensureFileDownloaded(nextIndex);
  }, [files, currentIndex, isEditing, ensureFileDownloaded, matchesFileMode]);

  const handleNext = useCallback(() => {
    if (files.length === 0) return;

    if (isEditing) {
      if (!window.confirm('You have unsaved changes. Discard them?')) {
        return;
      }
      setIsEditing(false);
    }

    let nextIndex = currentIndex;
    for (let i = 0; i < files.length; i++) {
      nextIndex = nextIndex < files.length - 1 ? nextIndex + 1 : 0;
      if (matchesFileMode(files[nextIndex])) break;
    }
    setCurrentIndex(nextIndex);
    ensureFileDownloaded(nextIndex);
  }, [files, currentIndex, isEditing, ensureFileDownloaded, matchesFileMode]);

  const handleFontSizeChange = useCallback((delta) => {
    setFontSize((prev) => Math.max(10, Math.min(40, prev + delta)));
  }, []);

  const handleDarkModeToggle = useCallback(() => {
    setDarkMode((prev) => !prev);
  }, []);

  // Keep isPinnedRef in sync
  useEffect(() => { isPinnedRef.current = isPinned; }, [isPinned]);

  // Pin / ruler callbacks
  const showControlBar = useCallback(() => {
    if (hideTimerRef.current) clearTimeout(hideTimerRef.current);
    setControlBarVisible(true);
  }, []);

  const startHideTimer = useCallback(() => {
    if (isPinnedRef.current) return;
    if (hideTimerRef.current) clearTimeout(hideTimerRef.current);
    hideTimerRef.current = setTimeout(() => setControlBarVisible(false), 1500);
  }, []);

  const handleTogglePin = useCallback(() => {
    const newPinned = !isPinnedRef.current;
    setIsPinned(newPinned);
    if (newPinned) {
      if (hideTimerRef.current) clearTimeout(hideTimerRef.current);
      setControlBarVisible(true);
    }
  }, []);

  const handleToggleRuler = useCallback(() => {
    setRulerEnabled(prev => !prev);
  }, []);

  const handleCopyContent = useCallback(() => {
    // For PDFs, delegate to the PDF page text handler
    if (currentPdfPageText) {
      return navigator.clipboard.writeText(currentPdfPageText).then(() => true).catch(() => false);
    }
    if (!currentTextContent) return false;
    const doFallbackCopy = (text) => {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      const ok = document.execCommand('copy');
      document.body.removeChild(ta);
      return ok;
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(currentTextContent)
        .then(() => true)
        .catch(() => doFallbackCopy(currentTextContent));
    } else {
      return Promise.resolve(doFallbackCopy(currentTextContent));
    }
  }, [currentTextContent, currentPdfPageText]);

  const handleWrapContent = useCallback(() => {
    if (!currentTextContent || !displayedFile) return;

    const blob = new Blob([currentTextContent], { type: 'text/markdown' });
    const url = URL.createObjectURL(blob);
    const wrappedFile = {
      key: displayedFile.key || 'Wrapped',
      originalName: (displayedFile.originalName || 'file').replace(/\.[^.]+$/, '') + '.md',
      type: 'markdown',
      url,
    };

    setFiles(prev => {
      const newFiles = [...prev];
      newFiles[currentIndex] = wrappedFile;
      return newFiles;
    });
    setIsEditing(false);
    setEditContent('');
    setPdfState(null);
  }, [currentTextContent, displayedFile, currentIndex]);

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

    // If Dropbox file, upload back; if local FS, write via handle; otherwise download
    if (displayedFile.dropboxPath) {
      const result = await dropbox.uploadFile(displayedFile.dropboxPath, editContent, 'overwrite');
      if (!result) {
        alert('Failed to save to Dropbox');
      }
    } else if (isLocalFS && fileHandles[displayedFileIndex]) {
      try {
        const handle = fileHandles[displayedFileIndex];
        const writable = await handle.createWritable();
        await writable.write(editContent);
        await writable.close();
      } catch (err) {
        console.error('Failed to save to local file:', err);
        alert('Failed to save to local file: ' + err.message);
      }
    } else {
      const a = document.createElement('a');
      a.href = newUrl;
      a.download = displayedFile.originalName || `${displayedFile.key}.txt`;
      a.click();
    }

    setIsEditing(false);
    setEditContent('');
  }, [displayedFile, displayedFileIndex, editContent, isEditing, dropbox, isLocalFS, fileHandles]);

  const handleCancel = useCallback(() => {
    setIsEditing(false);
    setEditContent('');
  }, []);

  // Edit: enter edit mode and load file content
  const handleEdit = useCallback(async () => {
    if (!displayedFile) return;
    if (!displayedFile.dropboxPath && !isLocalFS) return;
    if (!['text', 'markdown'].includes(displayedFile.type)) return;

    if (displayedFile.url) {
      const res = await fetch(displayedFile.url);
      const text = await res.text();
      setEditContent(text);
      setIsEditing(true);
    }
  }, [displayedFile, isLocalFS]);

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
        const newDisplayName = newName.replace(/\.[^.]+$/, '');
        newFiles[displayedFileIndex] = {
          ...newFiles[displayedFileIndex],
          dropboxPath: result.metadata?.path_lower || newPath,
          originalName: newName,
          key: newDisplayName,
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

  // Copy current PDF page text to clipboard (uses pre-extracted text, falls back to on-demand)
  const handleCopyPageText = useCallback(async () => {
    let textToCopy = currentPdfPageText;

    // Fallback: extract on-demand if pre-extracted text isn't available
    if (!textToCopy && pdfDocument && pdfState) {
      try {
        const page = await pdfDocument.getPage(pdfState.currentPage);
        const textContent = await page.getTextContent();
        textToCopy = textContent.items.map(item => item.str).join(' ');
      } catch (error) {
        console.error('Failed to extract PDF text:', error);
      }
    }

    if (!textToCopy) return;

    try {
      await navigator.clipboard.writeText(textToCopy);
    } catch (error) {
      console.error('Failed to copy text:', error);
      alert('Failed to copy text to clipboard');
    }
  }, [currentPdfPageText, pdfDocument, pdfState]);


  // Auto-wrap: trigger wrap 500ms after changing file (skip audio files)
  const autoWrapIndexRef = useRef(null);
  useEffect(() => {
    if (autoWrapIndexRef.current === currentIndex) return;
    if (files[currentIndex]?.type === 'audio') return;
    const timer = setTimeout(() => {
      autoWrapIndexRef.current = currentIndex;
      handleWrapContent();
    }, 500);
    return () => clearTimeout(timer);
  }, [currentIndex, handleWrapContent, files]);

  // Keyboard navigation
  useEffect(() => {
    const handleKeyDown = (e) => {
      // Don't handle keys when editing or in input fields
      if (isEditing) return;
      if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return;

      if (e.key === ' ') {
        e.preventDefault();
        if (e.target && e.target.blur) e.target.blur();
        const iframe = document.querySelector('.preview-html iframe');
        if (iframe && iframe.contentDocument) {
          iframe.contentDocument.documentElement.scrollBy({ top: iframe.clientHeight * 0.9, behavior: 'smooth' });
        } else {
          const scrollable = document.querySelector('.preview-text') || document.querySelector('.preview-markdown') || document.querySelector('.content-area');
          if (scrollable) scrollable.scrollBy({ top: scrollable.clientHeight * 0.9, behavior: 'smooth' });
        }
      } else if (e.key === 'ArrowLeft') {
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
      } else if (e.key === 'Escape') {
        // Exit reader mode first if active
        if (readerMode) {
          setReaderMode(false);
          return;
        }
        const modalClose = document.querySelector('.md-image-modal-close');
        if (modalClose) modalClose.click();
        // Stop persistent audio player
        const audioEl = document.getElementById('audioElement');
        if (audioEl) {
          audioEl.pause();
          setPersistentAudio(null);
        }
      } else if (e.key === '/') {
        e.preventDefault();
        setReaderMode(prev => !prev);
      } else if (e.key === 'u' || e.key === 'U') {
        handleToggleRuler();
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [handlePrev, handleNext, isEditing, handleTogglePin, handleToggleRuler, readerMode]);

  // Global mouse move: ruler tracking + reveal ControlBar when near top (unpinned)
  useEffect(() => {
    const handleMouseMove = (e) => {
      if (rulerEnabled) setRulerPos({ x: e.clientX, y: e.clientY });
      if (!isPinnedRef.current && e.clientY < 10) {
        if (hideTimerRef.current) clearTimeout(hideTimerRef.current);
        setControlBarVisible(true);
      }
    };
    window.addEventListener('mousemove', handleMouseMove);
    return () => window.removeEventListener('mousemove', handleMouseMove);
  }, [rulerEnabled]);

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

    if (currentFile.type === 'audio') {
      // Audio files only update the persistent player, not the content area
      const name = (currentFile.originalName || currentFile.key || '')
        .replace(/\.[^.]+$/, '');
      // Use dropboxPath as stable ID (Dropbox files start with url:null until downloaded)
      const stableId = currentFile.dropboxPath || currentFile.url || currentFile.key;
      setPersistentAudio(prev => ({
        url: currentFile.url || prev?.url,
        name,
        stableId,
      }));
    } else if (currentFile.type !== 'divider') {
      setDisplayedFileIndex(currentIndex);
    }
    // Clear text content when switching to a non-text file
    if (currentFile.type !== 'text' && currentFile.type !== 'rtf' && currentFile.type !== 'markdown' && currentFile.type !== 'audio') {
      setCurrentTextContent('');
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
    const pendingBrowse = sessionStorage.getItem('dropbox_pending_browse');
    if (dropbox.isAuthenticated && pendingBrowse) {
      sessionStorage.removeItem('dropbox_pending_browse');
      if (pendingBrowse === 'recursive') {
        setDropboxRecursiveOpen(true);
      } else {
        setDropboxBrowserOpen(true);
      }
    }
  }, [dropbox.isAuthenticated]);

  return (
    <div className={`text-viewer ${darkMode ? 'dark-mode' : ''}${rulerEnabled ? ' ruler-active' : ''}${readerMode ? ' reader-mode' : ''}`}>
      {!readerMode && <Sidebar
        files={files}
        currentIndex={displayedFileIndex}
        persistentAudio={persistentAudio}
        onFileSelect={handleFileSelect}
        onNext={handleNext}
        isOpen={showSidebar}
        onClose={() => setShowSidebar(false)}
        activeTagFilter={activeTagFilter}
        fileTags={fileTags}
        mdHeadings={mdHeadings}
        onScrollToHeading={setScrollToHeadingId}
        dropboxFileMode={dropboxFileMode}
      />}

      <div className="main-content">
        {!readerMode && <ControlBar
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
          dropboxFileMode={dropboxFileMode}
          onDropboxFileModeChange={setDropboxFileMode}
          slideshowEnabled={slideshowEnabled}
          onToggleSlideshow={() => setSlideshowEnabled(prev => !prev)}
          hasImages={files.some(f => f.type === 'image')}
          allTags={allTags}
          activeTagFilter={activeTagFilter}
          onTagFilterChange={setActiveTagFilter}
          wrapText={wrapText}
          onToggleWrapText={handleWrapContent}
          onEdit={handleEdit}
          onRename={handleRename}
          onNewFile={handleNewFile}
          isDropboxNonRecursive={isDropboxNonRecursive}
          isLocalFS={isLocalFS}
          onLocalDirOpen={() => handleLocalDirOpen(false)}
          onLocalDirOpenRecursive={() => handleLocalDirOpen(true)}
          onLoadDropboxPath={async (path) => {
            const entries = await dropbox.listFolder(path);
            handleDropboxFolderSelected(entries, path, true);
          }}
          dropbox={dropbox}
          isPinned={isPinned}
          onTogglePin={handleTogglePin}
          rulerEnabled={rulerEnabled}
          onToggleRuler={handleToggleRuler}
          controlBarHidden={!isPinned && !controlBarVisible}
          onMouseEnterBar={showControlBar}
          onMouseLeaveBar={startHideTimer}
          persistentAudio={persistentAudio}
          onClearAudio={() => setPersistentAudio(null)}
        />}

        <ContentViewer
          file={displayedFile}
          fontSize={fontSize}
          isEditing={isEditing}
          editContent={editContent}
          onEditChange={setEditContent}
          imagePathToBlobUrl={imagePathToBlobUrl}
          onImageRequest={handleImageRequest}
          onPrev={handlePrev}
          onNext={handleNext}
          pdfState={pdfState}
          onPdfStateChange={setPdfState}
          onPdfDocumentLoad={setPdfDocument}
          onPdfPageTextExtracted={setCurrentPdfPageText}
          syntaxHighlightEnabled={syntaxHighlightEnabled}
          wrapText={wrapText}
          onHeadingsExtracted={setMdHeadings}
          scrollToHeadingId={scrollToHeadingId}
          onScrollToHeadingDone={() => setScrollToHeadingId(null)}
          onTextLoaded={setCurrentTextContent}
        />


      </div>

      {/* Ruler reading aid */}
      {rulerEnabled && (
        <>
          <div className="reading-guide" style={{ top: rulerPos.y }} />
          <div className="custom-cursor" style={{ left: rulerPos.x, top: rulerPos.y }} />
        </>
      )}

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
