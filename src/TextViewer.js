import React, { useState, useEffect, useCallback, useRef } from 'react';
import Sidebar from './components/Sidebar';
import ContentViewer from './components/ContentViewer';
import ControlBar from './components/ControlBar';
import CloudNotes from './components/CloudNotes';
import { processFiles } from './utils/fileUtils';

function TextViewer() {
  const [files, setFiles] = useState([]);
  const [currentIndex, setCurrentIndex] = useState(0);
  const [displayedFileIndex, setDisplayedFileIndex] = useState(0); // Tracks which non-audio file to display
  const [fontSize, setFontSize] = useState(18);
  const [darkMode, setDarkMode] = useState(false);
  const [isEditing, setIsEditing] = useState(false);
  const [cloudNotesOpen, setCloudNotesOpen] = useState(false);

  const [editContent, setEditContent] = useState('');
  const [imagePathToBlobUrl, setImagePathToBlobUrl] = useState({});

  // Audio state
  const [currentAudioIndex, setCurrentAudioIndex] = useState(null);
  const [isAudioPlaying, setIsAudioPlaying] = useState(false);
  const audioRef = useRef(null);

  // PDF state
  const [pdfState, setPdfState] = useState(null);
  const [pdfDocument, setPdfDocument] = useState(null);

  // Reading aids state
  const [readingAidsEnabled, setReadingAidsEnabled] = useState(false);

  // Syntax highlighting state
  const [syntaxHighlightEnabled, setSyntaxHighlightEnabled] = useState(false);

  // Sidebar visibility state
  const [showSidebar, setShowSidebar] = useState(true);

  const currentFile = files[currentIndex] || null;
  const displayedFile = files[displayedFileIndex] || null;

  const handleFilesLoaded = useCallback((fileList) => {
    const result = processFiles(fileList);

    if (result.error) {
      alert(result.error);
      return;
    }

    // Find first displayable (non-audio, non-divider) file
    const firstDisplayableIndex = result.files.findIndex(
      f => f.type !== 'audio' && f.type !== 'divider'
    );

    setFiles(result.files);
    setImagePathToBlobUrl(result.imagePathToBlobUrl || {});
    setCurrentIndex(0);
    setDisplayedFileIndex(firstDisplayableIndex >= 0 ? firstDisplayableIndex : 0);
    setCurrentAudioIndex(null);
    setIsAudioPlaying(false);
    setIsEditing(false);
    setEditContent('');
    setPdfState(null); // Reset PDF state when loading new files
  }, []);

  const handleFileSelect = useCallback((index) => {
    const selectedFile = files[index];

    // If selecting an audio file, don't interrupt editing or reset PDF
    if (selectedFile && selectedFile.type === 'audio') {
      setCurrentIndex(index);
      return;
    }

    if (isEditing) {
      if (!window.confirm('You have unsaved changes. Discard them?')) {
        return;
      }
    }
    setCurrentIndex(index);
    setIsEditing(false);
    setEditContent('');
    setPdfState(null); // Reset PDF state when changing files
  }, [isEditing, files]);

  const handlePrev = useCallback(() => {
    if (files.length === 0) return;
    const nextIndex = currentIndex > 0 ? currentIndex - 1 : files.length - 1;
    const nextFile = files[nextIndex];

    // Only prompt about editing if navigating to a non-audio file
    if (isEditing && nextFile && nextFile.type !== 'audio') {
      if (!window.confirm('You have unsaved changes. Discard them?')) {
        return;
      }
      setIsEditing(false);
    }
    setCurrentIndex(nextIndex);
  }, [files, currentIndex, isEditing]);

  const handleNext = useCallback(() => {
    if (files.length === 0) return;
    const nextIndex = currentIndex < files.length - 1 ? currentIndex + 1 : 0;
    const nextFile = files[nextIndex];

    // Only prompt about editing if navigating to a non-audio file
    if (isEditing && nextFile && nextFile.type !== 'audio') {
      if (!window.confirm('You have unsaved changes. Discard them?')) {
        return;
      }
      setIsEditing(false);
    }
    setCurrentIndex(nextIndex);
  }, [files, currentIndex, isEditing]);

  const handleFontSizeChange = useCallback((delta) => {
    setFontSize((prev) => Math.max(10, Math.min(40, prev + delta)));
  }, []);

  const handleDarkModeToggle = useCallback(() => {
    setDarkMode((prev) => !prev);
  }, []);

  const handleEdit = useCallback(() => {
    if (!displayedFile || (displayedFile.type !== 'text' && displayedFile.type !== 'markdown')) return;

    // Fetch current content
    fetch(displayedFile.url)
      .then(res => res.text())
      .then(text => {
        setEditContent(text);
        setIsEditing(true);
      })
      .catch(err => {
        console.error('Error loading file for edit:', err);
        alert('Error loading file for editing');
      });
  }, [displayedFile]);

  const handleSave = useCallback(() => {
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

    // Download the file
    const a = document.createElement('a');
    a.href = newUrl;
    a.download = displayedFile.originalName || `${displayedFile.key}.txt`;
    a.click();

    setIsEditing(false);
    setEditContent('');
  }, [displayedFile, displayedFileIndex, editContent, isEditing]);

  const handleCancel = useCallback(() => {
    setIsEditing(false);
    setEditContent('');
  }, []);

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

  // Audio file selection handler
  const handleAudioSelect = useCallback((index) => {
    const audioFile = files[index];
    if (!audioFile || audioFile.type !== 'audio') return;

    setCurrentAudioIndex(index);

    // If audio element exists, load and play the new audio
    if (audioRef.current) {
      audioRef.current.src = audioFile.url;
      audioRef.current.load();
      audioRef.current.play().then(() => {
        setIsAudioPlaying(true);
      }).catch(err => {
        console.error('Error playing audio:', err);
      });
    }
  }, [files]);

  // Audio play/pause toggle
  const handleAudioPlayPause = useCallback(() => {
    if (!audioRef.current) return;

    if (isAudioPlaying) {
      audioRef.current.pause();
      setIsAudioPlaying(false);
    } else {
      audioRef.current.play().then(() => {
        setIsAudioPlaying(true);
      }).catch(err => {
        console.error('Error playing audio:', err);
      });
    }
  }, [isAudioPlaying]);

  // Audio stop
  const handleAudioStop = useCallback(() => {
    if (!audioRef.current) return;

    audioRef.current.pause();
    audioRef.current.currentTime = 0;
    setIsAudioPlaying(false);
  }, []);

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
      } else if (e.key === 'Enter') {
        // If audio is loaded in navbar, toggle play/pause
        if (currentAudioIndex !== null) {
          handleAudioPlayPause();
        } else {
          handleNext();
        }
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [handlePrev, handleNext, isEditing, currentAudioIndex, handleAudioPlayPause]);

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

  // Reading guide mouse tracking
  useEffect(() => {
    if (!readingAidsEnabled) return;

    const handleMouseMove = (e) => {
      const guide = document.getElementById('reading-guide');
      if (guide) {
        guide.style.top = e.clientY + 'px';
      }
    };

    window.addEventListener('mousemove', handleMouseMove);
    return () => window.removeEventListener('mousemove', handleMouseMove);
  }, [readingAidsEnabled]);

  // Handle audio ended event
  useEffect(() => {
    const audio = audioRef.current;
    if (!audio) return;

    const handleEnded = () => {
      setIsAudioPlaying(false);
    };

    audio.addEventListener('ended', handleEnded);
    return () => audio.removeEventListener('ended', handleEnded);
  }, []);

  // Handle navigation to different file types
  useEffect(() => {
    if (!currentFile) return;

    if (currentFile.type === 'audio') {
      // Load audio into player
      setCurrentAudioIndex(currentIndex);
      if (audioRef.current) {
        // Stop any currently playing audio before loading new
        audioRef.current.pause();
        audioRef.current.currentTime = 0;
        audioRef.current.src = currentFile.url;
        audioRef.current.load();
        audioRef.current.play().then(() => {
          setIsAudioPlaying(true);
        }).catch(err => {
          console.error('Error playing audio:', err);
          setIsAudioPlaying(false);
        });
      }
      // Don't update displayedFileIndex - keep showing previous content
    } else if (currentFile.type !== 'divider') {
      // Non-audio, non-divider file - update displayed content
      setDisplayedFileIndex(currentIndex);
    }
  }, [currentIndex, currentFile]);

  const currentAudioFile = currentAudioIndex !== null ? files[currentAudioIndex] : null;

  return (
    <div className={`text-viewer ${darkMode ? 'dark-mode' : ''}`}>
      <Sidebar
        files={files}
        currentIndex={currentIndex}
        onFileSelect={handleFileSelect}
        onAudioSelect={handleAudioSelect}
        currentAudioIndex={currentAudioIndex}
        isOpen={showSidebar}
        onClose={() => setShowSidebar(false)}
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
          onEdit={handleEdit}
          onSave={handleSave}
          onCancel={handleCancel}
          onFilesLoaded={handleFilesLoaded}
          onOpenCloudNotes={() => setCloudNotesOpen(true)}
          pdfState={pdfState}
          onPdfStateChange={setPdfState}
          onCopyPageText={handleCopyPageText}
          readingAidsEnabled={readingAidsEnabled}
          onToggleReadingAids={() => setReadingAidsEnabled(prev => !prev)}
          syntaxHighlightEnabled={syntaxHighlightEnabled}
          onToggleSyntaxHighlight={() => setSyntaxHighlightEnabled(prev => !prev)}
          audioFile={currentAudioFile}
          isAudioPlaying={isAudioPlaying}
          onAudioPlayPause={handleAudioPlayPause}
          onAudioStop={handleAudioStop}
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
        />

        {/* Hidden audio element for playing audio files */}
        <audio ref={audioRef} style={{ display: 'none' }} />
      </div>

      <CloudNotes
        isOpen={cloudNotesOpen}
        onClose={() => setCloudNotesOpen(false)}
      />

      {/* Reading Guide Ruler */}
      {readingAidsEnabled && (
        <div
          id="reading-guide"
          className="reading-guide"
          style={{ display: 'block' }}
        />
      )}
    </div>
  );
}

export default TextViewer;
