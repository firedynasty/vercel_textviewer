import { useState, useRef, useCallback } from 'react';

/* global Recorder, lamejs */

export function useAudioRecorder() {
  const [isRecording, setIsRecording] = useState(false);
  const [isProcessing, setIsProcessing] = useState(false);

  const gumStreamRef = useRef(null);
  const recorderRef = useRef(null);
  const audioContextRef = useRef(null);

  const startRecording = useCallback(async () => {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });

      const AudioContextClass = window.AudioContext || window.webkitAudioContext;
      audioContextRef.current = new AudioContextClass();
      gumStreamRef.current = stream;

      const input = audioContextRef.current.createMediaStreamSource(stream);
      recorderRef.current = new Recorder(input, { numChannels: 1 });
      recorderRef.current.record();

      setIsRecording(true);
    } catch (err) {
      console.error('Error accessing microphone:', err);
      alert('Microphone access denied. Please allow microphone access to record.');
    }
  }, []);

  const convertToMp3 = useCallback((wavBlob) => {
    return new Promise((resolve) => {
      const reader = new FileReader();
      reader.onload = function(e) {
        const wavData = new DataView(e.target.result);
        const sampleRate = wavData.getUint32(24, true);
        const numChannels = wavData.getUint16(22, true);
        const samples = new Int16Array(e.target.result, 44);

        const mp3encoder = new lamejs.Mp3Encoder(numChannels, sampleRate, 128);
        const mp3Data = [];
        const sampleBlockSize = 1152;

        for (let i = 0; i < samples.length; i += sampleBlockSize) {
          const sampleChunk = samples.subarray(i, i + sampleBlockSize);
          const mp3buf = mp3encoder.encodeBuffer(sampleChunk);
          if (mp3buf.length > 0) {
            mp3Data.push(mp3buf);
          }
        }

        const finalBuf = mp3encoder.flush();
        if (finalBuf.length > 0) {
          mp3Data.push(finalBuf);
        }

        const mp3Blob = new Blob(mp3Data, { type: 'audio/mp3' });
        resolve(mp3Blob);
      };
      reader.readAsArrayBuffer(wavBlob);
    });
  }, []);

  const downloadMp3 = useCallback((blob) => {
    // Format: Screenshot 2026-01-28 at 8.44.00 AM.mp3 (matches macOS screenshot naming)
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    let hours = now.getHours();
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');
    const isPM = hours >= 12;
    hours = hours % 12 || 12; // Convert to 12-hour format

    const filename = `Screenshot ${year}-${month}-${day} at ${hours}.${minutes}.${seconds} ${isPM ? 'PM' : 'AM'}.mp3`;
    const url = URL.createObjectURL(blob);

    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    URL.revokeObjectURL(url);
  }, []);

  const stopRecording = useCallback(async () => {
    if (!recorderRef.current || !gumStreamRef.current) return;

    setIsRecording(false);
    setIsProcessing(true);

    recorderRef.current.stop();
    gumStreamRef.current.getAudioTracks()[0].stop();

    recorderRef.current.exportWAV(async (wavBlob) => {
      const mp3Blob = await convertToMp3(wavBlob);
      downloadMp3(mp3Blob);
      setIsProcessing(false);
    });
  }, [convertToMp3, downloadMp3]);

  return {
    isRecording,
    isProcessing,
    startRecording,
    stopRecording
  };
}
