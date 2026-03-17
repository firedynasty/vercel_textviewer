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

  const handlePlayFirst = () => {
    if (sentences.length > 0) {
      speakSentence(sentences[0], 0);
    }
  };

  const handleStop = () => {
    speechSynthesis.cancel();
    setCurrentSentenceIndex(-1);
  };

  if (sentences.length === 0) return null;

  return (
    <div className="tts-reader">
      {/* Controls row */}
      <div className="tts-controls">
        <button
          className="tts-play-btn"
          onClick={handlePlayFirst}
          title="Start reading from first sentence"
        >
          &#9654;
        </button>
        <button
          className="tts-stop-btn"
          onClick={handleStop}
          title="Stop reading"
        >
          &#9632;
        </button>

        {/* Auto-advance toggle */}
        <div className={`tts-toggle-container ${autoAdvance ? 'active' : ''}`}>
          <span className="tts-toggle-label">Stop After Line</span>
          <label className="tts-toggle-switch">
            <input
              type="checkbox"
              checked={autoAdvance}
              onChange={(e) => setAutoAdvance(e.target.checked)}
            />
            <span className="tts-toggle-track">
              <span className="tts-toggle-thumb" />
            </span>
          </label>
          <span className={`tts-toggle-label ${autoAdvance ? 'active' : ''}`}>Auto Next Line</span>
        </div>

        <span className="tts-sentence-count">{sentences.length} sentences</span>
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
