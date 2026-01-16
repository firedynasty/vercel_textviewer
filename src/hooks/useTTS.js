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
    setSpeed,
    setLanguage,
    setSentenceCount,
    setRepeatMode,
    setCurrentSentenceIndex,
  };
}
