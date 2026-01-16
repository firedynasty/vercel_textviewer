import { useState, useCallback, useRef, useEffect } from 'react';

// Split text into sentences
function splitIntoSentences(text) {
  if (!text) return [];
  // Split on Chinese/English punctuation and newlines
  const sentences = text
    .split(/[。！？.!?\n]+/)
    .map(s => s.trim())
    .filter(s => s.length > 0);
  return sentences;
}

export function useTTS() {
  const [isPlaying, setIsPlaying] = useState(false);
  const [currentSentenceIndex, setCurrentSentenceIndex] = useState(0);
  const [sentences, setSentences] = useState([]);
  const [speed, setSpeed] = useState(1);
  const [language, setLanguage] = useState('en-US'); // 'en-US', 'zh-HK', 'zh-CN'
  const [sentenceCount, setSentenceCount] = useState(5);
  const [repeatMode, setRepeatMode] = useState('continue'); // 'repeat' or 'continue'

  const utteranceRef = useRef(null);
  const sentencesReadRef = useRef(0);
  const startIndexRef = useRef(0);

  // Load text and split into sentences
  const loadText = useCallback((text) => {
    const newSentences = splitIntoSentences(text);
    setSentences(newSentences);
    setCurrentSentenceIndex(0);
    sentencesReadRef.current = 0;
    startIndexRef.current = 0;
  }, []);

  // Stop speech
  const stop = useCallback(() => {
    window.speechSynthesis.cancel();
    setIsPlaying(false);
    if (utteranceRef.current) {
      utteranceRef.current.onend = null;
      utteranceRef.current.onerror = null;
      utteranceRef.current = null;
    }
  }, []);

  // Speak a single sentence
  const speakSentence = useCallback((index) => {
    if (index >= sentences.length || index < 0) {
      stop();
      return;
    }

    const sentence = sentences[index];
    if (!sentence) {
      stop();
      return;
    }

    const utterance = new SpeechSynthesisUtterance(sentence);
    utterance.rate = speed;
    utterance.lang = language;

    // Try to find a matching voice
    const voices = window.speechSynthesis.getVoices();
    const matchingVoice = voices.find(v => v.lang === language) ||
                          voices.find(v => v.lang.startsWith(language.split('-')[0]));
    if (matchingVoice) {
      utterance.voice = matchingVoice;
    }

    utterance.onend = () => {
      sentencesReadRef.current++;

      // Check if we've read enough sentences
      if (sentencesReadRef.current >= sentenceCount) {
        stop();
        return;
      }

      if (repeatMode === 'repeat') {
        // Repeat the same sentence
        setTimeout(() => {
          if (utteranceRef.current) {
            speakSentence(index);
          }
        }, 300);
      } else {
        // Continue to next sentence
        const nextIndex = index + 1;
        if (nextIndex < sentences.length) {
          setCurrentSentenceIndex(nextIndex);
          setTimeout(() => {
            if (utteranceRef.current !== null || isPlaying) {
              speakSentence(nextIndex);
            }
          }, 300);
        } else {
          stop();
        }
      }
    };

    utterance.onerror = (event) => {
      console.error('Speech synthesis error:', event);
      stop();
    };

    utteranceRef.current = utterance;
    setCurrentSentenceIndex(index);
    window.speechSynthesis.cancel();

    setTimeout(() => {
      window.speechSynthesis.speak(utterance);
    }, 100);
  }, [sentences, speed, language, sentenceCount, repeatMode, stop, isPlaying]);

  // Play from current position
  const play = useCallback(() => {
    if (sentences.length === 0) return;

    setIsPlaying(true);
    sentencesReadRef.current = 0;
    startIndexRef.current = currentSentenceIndex;
    speakSentence(currentSentenceIndex);
  }, [sentences.length, currentSentenceIndex, speakSentence]);

  // Navigate to previous sentence
  const prevSentence = useCallback(() => {
    stop();
    setCurrentSentenceIndex(prev => Math.max(0, prev - 1));
  }, [stop]);

  // Navigate to next sentence
  const nextSentence = useCallback(() => {
    stop();
    setCurrentSentenceIndex(prev => Math.min(sentences.length - 1, prev + 1));
  }, [stop, sentences.length]);

  // Find sentence containing selected text and play from there
  const playFromSelection = useCallback((selectedText) => {
    console.log('playFromSelection called with:', selectedText);
    console.log('sentences count:', sentences.length);

    if (!selectedText || sentences.length === 0) {
      console.log('Early return: no text or no sentences');
      return false;
    }

    // Clean up selection - remove extra whitespace, newlines
    const trimmedSelection = selectedText.trim().replace(/\s+/g, ' ');
    console.log('trimmed selection:', trimmedSelection);

    if (trimmedSelection.length < 2) {
      console.log('Selection too short');
      return false;
    }

    // Try exact substring match first
    let foundIndex = sentences.findIndex(sentence => {
      const cleanSentence = sentence.trim();
      return cleanSentence.includes(trimmedSelection) || trimmedSelection.includes(cleanSentence);
    });

    console.log('Exact match index:', foundIndex);

    // Try character-by-character match for Chinese text
    if (foundIndex === -1) {
      const selectionChars = trimmedSelection.replace(/\s/g, '');
      foundIndex = sentences.findIndex(sentence => {
        const sentenceChars = sentence.replace(/\s/g, '');
        return sentenceChars.includes(selectionChars) || selectionChars.includes(sentenceChars);
      });
      console.log('Character match index:', foundIndex);
    }

    // Try finding first few characters match
    if (foundIndex === -1 && trimmedSelection.length >= 3) {
      const firstChars = trimmedSelection.substring(0, Math.min(10, trimmedSelection.length)).replace(/\s/g, '');
      foundIndex = sentences.findIndex(sentence => {
        const sentenceChars = sentence.replace(/\s/g, '');
        return sentenceChars.includes(firstChars);
      });
      console.log('First chars match index:', foundIndex, 'looking for:', firstChars);
    }

    if (foundIndex !== -1) {
      console.log('Found sentence:', sentences[foundIndex]);
      stop();
      setCurrentSentenceIndex(foundIndex);
      setIsPlaying(true);
      sentencesReadRef.current = 0;
      startIndexRef.current = foundIndex;
      setTimeout(() => {
        speakSentence(foundIndex);
      }, 100);
      return true;
    }

    // Try partial match - find sentence with most character overlap
    let bestMatch = -1;
    let bestScore = 0;
    const selectionChars = [...trimmedSelection.replace(/\s/g, '')];

    sentences.forEach((sentence, idx) => {
      const sentenceChars = sentence.replace(/\s/g, '');
      let matchCount = 0;
      selectionChars.forEach(char => {
        if (sentenceChars.includes(char)) matchCount++;
      });
      const score = matchCount / selectionChars.length;
      if (score > bestScore && score > 0.5) {
        bestScore = score;
        bestMatch = idx;
      }
    });

    console.log('Best partial match:', bestMatch, 'score:', bestScore);

    if (bestMatch !== -1) {
      console.log('Using partial match sentence:', sentences[bestMatch]);
      stop();
      setCurrentSentenceIndex(bestMatch);
      setIsPlaying(true);
      sentencesReadRef.current = 0;
      startIndexRef.current = bestMatch;
      setTimeout(() => {
        speakSentence(bestMatch);
      }, 100);
      return true;
    }

    console.log('No match found');
    return false;
  }, [sentences, stop, speakSentence]);

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      window.speechSynthesis.cancel();
    };
  }, []);

  // Get current sentence text for display
  const currentSentenceText = sentences[currentSentenceIndex] || '';
  const sentenceIndicator = sentences.length > 0
    ? `${currentSentenceIndex + 1}/${sentences.length}: ${currentSentenceText.substring(0, 20)}${currentSentenceText.length > 20 ? '...' : ''}`
    : 'Ready to read...';

  return {
    // State
    isPlaying,
    currentSentenceIndex,
    sentences,
    speed,
    language,
    sentenceCount,
    repeatMode,
    sentenceIndicator,

    // Actions
    loadText,
    play,
    stop,
    prevSentence,
    nextSentence,
    playFromSelection,
    setSpeed,
    setLanguage,
    setSentenceCount,
    setRepeatMode,
    setCurrentSentenceIndex,
  };
}
