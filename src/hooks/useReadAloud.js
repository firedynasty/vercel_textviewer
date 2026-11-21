import { useCallback, useEffect, useRef, useState } from 'react';

// Speak text with the browser voice, the same way Page Turner (vercel_speech) does:
// prefer an Enhanced/Premium English voice, speak one sentence at a time, and nudge
// Chrome every 10s so it doesn't drop long runs.

const MAX_PIECE = 220;

// Sentences, with any very long sentence cut further at commas/semicolons, then spaces.
export function speechPieces(text) {
  const flat = (text || '').replace(/\s+/g, ' ').trim();
  if (!flat) return [];
  const sentences = flat.match(/[^.!?]+(?:[.!?]+["')\]]*|$)\s*/g) || [flat];
  const pieces = [];
  for (const sentence of sentences) {
    let rest = sentence.trim();
    while (rest.length > MAX_PIECE) {
      const window = rest.slice(0, MAX_PIECE);
      let cut = Math.max(window.lastIndexOf(', '), window.lastIndexOf('; '), window.lastIndexOf(': '));
      if (cut < MAX_PIECE / 3) cut = window.lastIndexOf(' ');
      if (cut < MAX_PIECE / 3) cut = MAX_PIECE - 1;
      pieces.push(rest.slice(0, cut + 1).trim());
      rest = rest.slice(cut + 1).trim();
    }
    if (rest) pieces.push(rest);
  }
  return pieces;
}

export function bestVoice(voices) {
  return voices.find(v => /^en/i.test(v.lang) && /(Enhanced|Premium)/i.test(v.name))
    || voices.find(v => /^en/i.test(v.lang) && /Google/i.test(v.name))
    || voices.find(v => /^en/i.test(v.lang))
    || voices[0] || null;
}

export default function useReadAloud() {
  const [speaking, setSpeaking] = useState(false);
  const generation = useRef(0);
  const synth = typeof window !== 'undefined' ? window.speechSynthesis : undefined;

  const stop = useCallback(() => {
    generation.current += 1;
    try { synth?.cancel(); } catch { /* unsupported */ }
    setSpeaking(false);
  }, [synth]);

  const speak = useCallback(text => {
    const pieces = speechPieces(text);
    if (!synth || !pieces.length) return false;
    generation.current += 1;
    const run = generation.current;
    try { synth.cancel(); } catch { /* unsupported */ }
    const voice = bestVoice(synth.getVoices() || []);
    const say = index => {
      if (run !== generation.current) return;
      if (index >= pieces.length) { setSpeaking(false); return; }
      const utterance = new window.SpeechSynthesisUtterance(pieces[index]);
      if (voice) { utterance.voice = voice; utterance.lang = voice.lang; }
      utterance.onend = () => say(index + 1);
      utterance.onerror = event => {
        if (event && (event.error === 'canceled' || event.error === 'interrupted')) return;
        say(index + 1);
      };
      synth.speak(utterance);
    };
    setSpeaking(true);
    // A beat of delay keeps Chrome from dropping an utterance queued right after cancel().
    setTimeout(() => say(0), 30);
    return true;
  }, [synth]);

  // Chrome stops long speech runs unless it is nudged.
  useEffect(() => {
    if (!speaking || !synth || !/Chrome/.test(navigator.userAgent)) return undefined;
    const id = setInterval(() => { if (synth.speaking && !synth.paused) { synth.pause(); synth.resume(); } }, 10000);
    return () => clearInterval(id);
  }, [speaking, synth]);

  // Voices arrive asynchronously in Chrome; asking once warms the list before the first right-click.
  useEffect(() => { try { synth?.getVoices(); } catch { /* unsupported */ } }, [synth]);
  useEffect(() => () => { generation.current += 1; try { synth?.cancel(); } catch { /* unsupported */ } }, [synth]);

  return { speaking, speak, stop, supported: Boolean(synth) };
}
