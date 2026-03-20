import React, { useState, useEffect, useRef, useCallback } from 'react';

function TextToSpeechReader({ textContent, fontSize }) {
  const [sentences, setSentences] = useState([]);
  const [currentSentenceIndex, setCurrentSentenceIndex] = useState(-1);
  const [autoAdvance, setAutoAdvance] = useState(true);
  const [availableVoices, setAvailableVoices] = useState([]);

  const autoAdvanceRef = useRef(true);
  autoAdvanceRef.current = autoAdvance;
  const sentencesRef = useRef([]);
  sentencesRef.current = sentences;
  const speakSentenceRef = useRef(null);
  const readingAreaRef = useRef(null);

  // Load available voices
  useEffect(() => {
    const loadVoices = () => {
      setAvailableVoices(speechSynthesis.getVoices());
    };
    loadVoices();
    speechSynthesis.addEventListener('voiceschanged', loadVoices);
    return () => {
      speechSynthesis.removeEventListener('voiceschanged', loadVoices);
      speechSynthesis.cancel();
    };
  }, []);

  // Process text into sentences when content changes
  useEffect(() => {
    if (!textContent || !textContent.trim()) {
      setSentences([]);
      setCurrentSentenceIndex(-1);
      return;
    }

    // Strip markdown/HTML tags for clean sentence parsing
    let cleanText = textContent
      .replace(/```[\s\S]*?```/g, '') // remove code blocks
      .replace(/`[^`]*`/g, '')        // remove inline code
      .replace(/!\[[^\]]*\]\([^)]*\)/g, '') // remove images
      .replace(/\[[^\]]*\]\([^)]*\)/g, (match) => match.replace(/\[([^\]]*)\]\([^)]*\)/, '$1')) // links to text
      .replace(/#{1,6}\s*/g, '')       // remove markdown headers
      .replace(/[*_~]{1,3}/g, '')      // remove bold/italic/strikethrough
      .replace(/<[^>]+>/g, '')         // remove HTML tags
      .replace(/\n{2,}/g, '. ')        // paragraph breaks become sentence breaks
      .replace(/\n/g, '. ');           // line breaks become sentence breaks

    const allRawSentences = cleanText.split(/[.!?]+/).filter(s => s.trim().length > 0);

    // Consolidate short sentences (< 15 characters)
    const consolidated = [];
    for (let i = 0; i < allRawSentences.length; i++) {
      const current = allRawSentences[i].trim();
      if (!current) continue;

      if (current.length < 15 && consolidated.length > 0) {
        consolidated[consolidated.length - 1] += '. ' + current;
      } else if (current.length < 15 && i < allRawSentences.length - 1) {
        const next = allRawSentences[i + 1].trim();
        if (next) {
          consolidated.push(current + '. ' + next);
          i++;
        } else {
          consolidated.push(current);
        }
      } else {
        consolidated.push(current);
      }
    }

    setSentences(consolidated);
    setCurrentSentenceIndex(-1);
    speechSynthesis.cancel();
  }, [textContent]);

  const speakSentence = useCallback((sentenceText, index) => {
    if (!sentenceText) return;
    speechSynthesis.cancel();
    setCurrentSentenceIndex(index);

    // Scroll the active sentence into view
    setTimeout(() => {
      const activeEl = readingAreaRef.current?.querySelector(`[data-sentence-index="${index}"]`);
      if (activeEl) {
        activeEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    }, 50);

    const utterance = new SpeechSynthesisUtterance(sentenceText);
    utterance.lang = 'en-US';
    utterance.rate = 1;

    // Voice selection - prefer Enhanced/Premium, then Google, then quality named voices
    const voices = availableVoices;
    let voice = voices.find(v =>
      v.lang.includes('en-') && (v.name.includes('Enhanced') || v.name.includes('Premium'))
    );
    if (!voice) {
      voice = voices.find(v => v.lang.includes('en-') && v.name.includes('Google'));
    }
    if (!voice) {
      for (const name of ['Daniel', 'Samantha', 'Alex', 'Karen']) {
        voice = voices.find(v => v.lang.includes('en-') && v.name.includes(name));
        if (voice) break;
      }
    }
    if (!voice) {
      voice = voices.find(v => v.lang.includes('en-'));
    }
    if (voice) {
      utterance.voice = voice;
    }

    utterance.onend = () => {
      if (autoAdvanceRef.current) {
        const nextIndex = index + 1;
        if (nextIndex < sentencesRef.current.length) {
          setTimeout(() => {
            speakSentenceRef.current(sentencesRef.current[nextIndex], nextIndex);
          }, 300);
        }
      }
    };

    speechSynthesis.speak(utterance);
  }, [availableVoices]);

  speakSentenceRef.current = speakSentence;

  // Scroll main content to show text near current TTS sentence
  const scrollMainContentToCurrentSentence = (index) => {
    if (index < 0 || index >= sentences.length) return;
    const sentenceText = sentences[index];
    const normalize = (str) => str.replace(/\s+/g, ' ').toLowerCase().trim();

    // Extract key words (4+ chars) for matching
    const words = normalize(sentenceText).split(' ').filter(w => w.length >= 4);
    if (words.length === 0) return;

    // Build search phrases from most specific to least
    const searchPhrases = [];
    if (words.length >= 3) searchPhrases.push(words.slice(0, 5).join(' '));
    if (words.length >= 2) searchPhrases.push(words.slice(0, 3).join(' '));
    searchPhrases.push(words[0]);

    const contentEl = document.querySelector('.preview-markdown') || document.querySelector('.preview-text');
    if (!contentEl) return;

    for (const phrase of searchPhrases) {
      const walker = document.createTreeWalker(contentEl, NodeFilter.SHOW_TEXT, null, false);
      let node;
      while ((node = walker.nextNode())) {
        if (normalize(node.textContent).includes(phrase)) {
          const el = node.parentElement;
          if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
          return;
        }
      }
    }
  };

  const handleGrab = () => {
    const selection = window.getSelection();
    const selectedText = selection?.toString()?.trim();
    if (!selectedText || sentences.length === 0) return;

    // Normalize selected text for comparison
    const normalize = (str) => str.replace(/\s+/g, ' ').toLowerCase();
    const normalizedSelection = normalize(selectedText);

    // Find the best matching sentence
    let bestIndex = -1;
    let bestScore = 0;

    for (let i = 0; i < sentences.length; i++) {
      const normalizedSentence = normalize(sentences[i]);

      // Check if selection contains sentence or sentence contains selection
      if (normalizedSentence.includes(normalizedSelection) || normalizedSelection.includes(normalizedSentence)) {
        bestIndex = i;
        break;
      }

      // Partial match: check how many words overlap
      const selWords = normalizedSelection.split(' ');
      const sentWords = normalizedSentence.split(' ');
      let overlap = 0;
      for (const w of selWords) {
        if (w.length > 3 && sentWords.some(sw => sw.includes(w))) overlap++;
      }
      const score = overlap / selWords.length;
      if (score > bestScore) {
        bestScore = score;
        bestIndex = i;
      }
    }

    if (bestIndex >= 0) {
      speakSentence(sentences[bestIndex], bestIndex);
    }
  };


  if (sentences.length === 0) return null;

  return (
    <div className="tts-reader">
      {/* Controls row */}
      <div className="tts-controls">
        <button
          className="tts-grab-btn"
          onMouseEnter={handleGrab}
          title="Hover to read highlighted text"
        >
          Grab
        </button>

        {/* Auto-advance toggle - hover to toggle */}
        <div className={`tts-toggle-container ${autoAdvance ? 'active' : ''}`}>
          <span className="tts-toggle-label">Stop After Line</span>
          <span
            className="tts-toggle-switch"
            onMouseEnter={() => {
              if (autoAdvance) {
                // ON → OFF: stop reading
                speechSynthesis.cancel();
                setAutoAdvance(false);
              } else {
                // OFF → ON: resume from current highlighted sentence
                setAutoAdvance(true);
                if (currentSentenceIndex >= 0 && currentSentenceIndex < sentences.length) {
                  setTimeout(() => {
                    speakSentence(sentences[currentSentenceIndex], currentSentenceIndex);
                  }, 100);
                }
              }
            }}
            title="Hover to toggle auto-advance"
            style={{ cursor: 'pointer' }}
          >
            <span className={`tts-toggle-track ${autoAdvance ? 'active' : ''}`}>
              <span className={`tts-toggle-thumb ${autoAdvance ? 'on' : ''}`} />
            </span>
          </span>
          <span className={`tts-toggle-label ${autoAdvance ? 'active' : ''}`}>Auto Next Line</span>
        </div>

        <button
          className="tts-grab-btn"
          onMouseEnter={() => {
            if (currentSentenceIndex >= 0) {
              scrollMainContentToCurrentSentence(currentSentenceIndex);
            }
          }}
          title="Hover to scroll main content to current reading position"
        >
          ↺
        </button>
      </div>

      {/* Reading area - clickable sentences */}
      <div className="tts-reading-area" ref={readingAreaRef}>
        {sentences.map((sentence, index) => (
          <div
            key={index}
            data-sentence-index={index}
            className={`tts-sentence ${currentSentenceIndex === index ? 'active' : ''}`}
            onClick={() => speakSentence(sentence, index)}
            style={{ fontSize: `${fontSize}px` }}
          >
            {sentence}.
          </div>
        ))}
      </div>
    </div>
  );
}

export default TextToSpeechReader;
