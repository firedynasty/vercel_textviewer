import React, { useState, useEffect, useCallback } from 'react';
import Sidebar from './components/Sidebar';
import ContentViewer from './components/ContentViewer';
import ControlBar from './components/ControlBar';
import CloudNotes from './components/CloudNotes';
import { processFiles, rtfToPlainText, isRtfFile } from './utils/fileUtils';
import { useTTS } from './hooks/useTTS';

function TextViewer() {
  const [files, setFiles] = useState([]);
  const [currentIndex, setCurrentIndex] = useState(0);
  const [fontSize, setFontSize] = useState(18);
  const [darkMode, setDarkMode] = useState(false);
  const [isEditing, setIsEditing] = useState(false);
  const [cloudNotesOpen, setCloudNotesOpen] = useState(false);

  const tts = useTTS();
  const [editContent, setEditContent] = useState('');
  const [imagePathToBlobUrl, setImagePathToBlobUrl] = useState({});

  // PDF state
  const [pdfState, setPdfState] = useState(null);

  // Reading aids state
  const [readingAidsEnabled, setReadingAidsEnabled] = useState(false);

  const currentFile = files[currentIndex] || null;

  const handleFilesLoaded = useCallback((fileList) => {
    const result = processFiles(fileList);

    if (result.error) {
      alert(result.error);
      return;
    }

    setFiles(result.files);
    setImagePathToBlobUrl(result.imagePathToBlobUrl || {});
    setCurrentIndex(0);
    setIsEditing(false);
    setEditContent('');
    setPdfState(null); // Reset PDF state when loading new files
  }, []);

  const handleFileSelect = useCallback((index) => {
    if (isEditing) {
      if (!window.confirm('You have unsaved changes. Discard them?')) {
        return;
      }
    }
    setCurrentIndex(index);
    setIsEditing(false);
    setEditContent('');
    setPdfState(null); // Reset PDF state when changing files
  }, [isEditing]);

  const handlePrev = useCallback(() => {
    if (files.length === 0) return;
    if (isEditing) {
      if (!window.confirm('You have unsaved changes. Discard them?')) {
        return;
      }
      setIsEditing(false);
    }
    setCurrentIndex((prev) => (prev > 0 ? prev - 1 : files.length - 1));
  }, [files.length, isEditing]);

  const handleNext = useCallback(() => {
    if (files.length === 0) return;
    if (isEditing) {
      if (!window.confirm('You have unsaved changes. Discard them?')) {
        return;
      }
      setIsEditing(false);
    }
    setCurrentIndex((prev) => (prev < files.length - 1 ? prev + 1 : 0));
  }, [files.length, isEditing]);

  const handleFontSizeChange = useCallback((delta) => {
    setFontSize((prev) => Math.max(10, Math.min(40, prev + delta)));
  }, []);

  const handleDarkModeToggle = useCallback(() => {
    setDarkMode((prev) => !prev);
  }, []);

  const handleEdit = useCallback(() => {
    if (!currentFile || (currentFile.type !== 'text' && currentFile.type !== 'markdown')) return;

    // Fetch current content
    fetch(currentFile.url)
      .then(res => res.text())
      .then(text => {
        setEditContent(text);
        setIsEditing(true);
      })
      .catch(err => {
        console.error('Error loading file for edit:', err);
        alert('Error loading file for editing');
      });
  }, [currentFile]);

  const handleSave = useCallback(() => {
    if (!currentFile || !isEditing) return;

    // Create a new blob with the edited content
    const blob = new Blob([editContent], { type: 'text/plain' });
    const newUrl = URL.createObjectURL(blob);

    // Update the file in the array
    setFiles(prevFiles => {
      const newFiles = [...prevFiles];
      newFiles[currentIndex] = {
        ...newFiles[currentIndex],
        url: newUrl
      };
      return newFiles;
    });

    // Download the file
    const a = document.createElement('a');
    a.href = newUrl;
    a.download = currentFile.originalName || `${currentFile.key}.txt`;
    a.click();

    setIsEditing(false);
    setEditContent('');
  }, [currentFile, currentIndex, editContent, isEditing]);

  const handleCancel = useCallback(() => {
    setIsEditing(false);
    setEditContent('');
  }, []);

  // Load text content for TTS when file changes
  useEffect(() => {
    if (currentFile && (currentFile.type === 'text' || currentFile.type === 'rtf' || currentFile.type === 'markdown')) {
      fetch(currentFile.url)
        .then(res => res.text())
        .then(text => {
          // Convert RTF to plain text if needed
          if (currentFile.originalName && isRtfFile(currentFile.originalName)) {
            text = rtfToPlainText(text);
          }
          tts.loadText(text);
        })
        .catch(err => {
          console.error('Error loading file for TTS:', err);
        });
    } else {
      tts.loadText('');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [currentFile]);

  // Keyboard navigation
  useEffect(() => {
    const handleKeyDown = (e) => {
      // Don't handle keys when editing or in input fields
      if (isEditing) return;
      if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return;

      if (e.key === 'ArrowLeft') {
        handlePrev();
      } else if (e.key === 'ArrowRight' || e.key === 'Enter') {
        handleNext();
      } else if (e.key === '[') {
        // TTS previous sentence
        tts.prevSentence();
      } else if (e.key === ']') {
        // TTS next sentence
        tts.nextSentence();
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [handlePrev, handleNext, isEditing, tts]);

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

  // Update TTS indicator when text is highlighted/selected in content area
  useEffect(() => {
    let timeoutId = null;

    const handleSelectionChange = () => {
      // Debounce to avoid too many updates
      if (timeoutId) clearTimeout(timeoutId);

      timeoutId = setTimeout(() => {
        const sel = window.getSelection();
        const selection = sel.toString().trim();

        // Only update if selection is in content area and has meaningful length
        if (selection && selection.length > 2) {
          const anchorNode = sel.anchorNode;
          if (anchorNode) {
            const contentArea = document.querySelector('.content-area, .preview-text, .preview-markdown');
            if (contentArea && contentArea.contains(anchorNode)) {
              console.log('Selection in content area:', selection.substring(0, 30));
              tts.updateFromSelection(selection);
            }
          }
        }
      }, 300);
    };

    document.addEventListener('selectionchange', handleSelectionChange);
    return () => {
      document.removeEventListener('selectionchange', handleSelectionChange);
      if (timeoutId) clearTimeout(timeoutId);
    };
  }, [tts]);

  return (
    <div className={`text-viewer ${darkMode ? 'dark-mode' : ''}`}>
      <Sidebar
        files={files}
        currentIndex={currentIndex}
        onFileSelect={handleFileSelect}
      />

      <div className="main-content">
        <ControlBar
          currentFile={currentFile}
          fontSize={fontSize}
          onFontSizeChange={handleFontSizeChange}
          darkMode={darkMode}
          onDarkModeToggle={handleDarkModeToggle}
          isEditing={isEditing}
          onEdit={handleEdit}
          onSave={handleSave}
          onCancel={handleCancel}
          onFilesLoaded={handleFilesLoaded}
          tts={tts}
          onOpenCloudNotes={() => setCloudNotesOpen(true)}
          pdfState={pdfState}
          onPdfStateChange={setPdfState}
          readingAidsEnabled={readingAidsEnabled}
          onToggleReadingAids={() => setReadingAidsEnabled(prev => !prev)}
        />

        <ContentViewer
          file={currentFile}
          fontSize={fontSize}
          isEditing={isEditing}
          editContent={editContent}
          onEditChange={setEditContent}
          imagePathToBlobUrl={imagePathToBlobUrl}
          onPrev={handlePrev}
          onNext={handleNext}
          onPlayFromSelection={tts.playFromSelection}
          pdfState={pdfState}
          onPdfStateChange={setPdfState}
        />
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
