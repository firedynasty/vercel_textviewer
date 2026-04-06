import React, { useState, useRef, useCallback, useEffect } from 'react';

function CursiveWriterModal({ textContent, fontSize: parentFontSize }) {
  const [isOpen, setIsOpen] = useState(false);
  const [text, setText] = useState('');
  const [speed, setSpeed] = useState(() => parseInt(localStorage.getItem('cw-speed') ?? '5'));
  const [size, setSize] = useState(() => parseInt(localStorage.getItem('cw-size') ?? '72'));
  const [scrollLevel, setScrollLevel] = useState(() => parseInt(localStorage.getItem('cw-scroll') ?? '3'));
  const [running, setRunning] = useState(false);
  const [scrolling, setScrolling] = useState(false);

  const outputRef = useRef(null);
  const outputTextRef = useRef(null);
  const timerRef = useRef(null);
  const scrollTimerRef = useRef(null);
  const runningRef = useRef(false);
  const animateRef = useRef(null);

  // Clean textContent (strip markdown/HTML)
  const cleanText = useCallback((raw) => {
    if (!raw) return '';
    return raw
      .replace(/```[\s\S]*?```/g, '')
      .replace(/`[^`]*`/g, '')
      .replace(/!\[[^\]]*\]\([^)]*\)/g, '')
      .replace(/\[[^\]]*\]\([^)]*\)/g, (m) => m.replace(/\[([^\]]*)\]\([^)]*\)/, '$1'))
      .replace(/#{1,6}\s*/g, '')
      .replace(/[*_~]{1,3}/g, '')
      .replace(/<[^>]+>/g, '')
      .trim();
  }, []);


  // Listen for text selection in the main viewer — grab highlighted text into modal
  useEffect(() => {
    const handleMouseUp = () => {
      if (!isOpen) return;
      const selection = window.getSelection();
      const selectedText = selection?.toString()?.trim();
      if (selectedText && selectedText.length > 2) {
        // Only grab if selection is from the main content area (not from modal itself)
        const anchorNode = selection.anchorNode;
        const modalEl = document.querySelector('.cw-modal');
        if (modalEl && modalEl.contains(anchorNode)) return;
        setText(selectedText);
      }
    };
    document.addEventListener('mouseup', handleMouseUp);
    return () => document.removeEventListener('mouseup', handleMouseUp);
  }, [isOpen]);

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      clearTimeout(timerRef.current);
      clearInterval(scrollTimerRef.current);
    };
  }, []);

  const startAutoScroll = useCallback((level) => {
    clearInterval(scrollTimerRef.current);
    scrollTimerRef.current = null;
    const lvl = level !== undefined ? level : scrollLevel;
    if (lvl === 0) return;
    const px = [20, 40, 70, 110, 160][lvl - 1];
    scrollTimerRef.current = setInterval(() => {
      if (outputRef.current) {
        outputRef.current.scrollBy({ top: px, behavior: 'smooth' });
      }
    }, 1000);
    setScrolling(true);
  }, [scrollLevel]);

  const stop = useCallback(() => {
    clearTimeout(timerRef.current);
    timerRef.current = null;
    runningRef.current = false;
    setRunning(false);
    clearInterval(scrollTimerRef.current);
    scrollTimerRef.current = null;
    setScrolling(false);
  }, []);

  const clearAll = useCallback(() => {
    stop();
    if (outputTextRef.current) {
      outputTextRef.current.innerHTML = '';
    }
    if (outputRef.current) {
      outputRef.current.scrollTop = 0;
    }
  }, [stop]);

  const animate = useCallback(() => {
    const trimmed = text.trim();
    if (!trimmed) return;

    stop();
    if (outputTextRef.current) outputTextRef.current.innerHTML = '';
    if (outputRef.current) outputRef.current.scrollTop = 0;

    const fadeMs = [700, 500, 350, 220, 120][speed - 1];
    const delayMs = [600, 420, 280, 170, 90][speed - 1];

    if (outputTextRef.current) {
      outputTextRef.current.style.fontSize = size + 'px';
    }

    const words = trimmed.split(/\s+/).filter(Boolean);
    const spans = words.map((word, i) => {
      const sp = document.createElement('span');
      sp.className = 'cw-word';
      sp.textContent = i < words.length - 1 ? word + ' ' : word;
      outputTextRef.current.appendChild(sp);
      return sp;
    });

    runningRef.current = true;
    setRunning(true);
    if (scrollLevel > 0) {
      startAutoScroll(scrollLevel);
    }

    let i = 0;
    function next() {
      if (!runningRef.current || i >= spans.length) {
        // Animation done — stop the write timer but keep scroll going
        clearTimeout(timerRef.current);
        timerRef.current = null;
        runningRef.current = false;
        setRunning(false);
        return;
      }
      spans[i].classList.add('on');
      const el = outputRef.current;
      if (el && scrollLevel === 0) {
        const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
        if (nearBottom) el.scrollTop = el.scrollHeight;
      }
      i++;
      timerRef.current = setTimeout(next, delayMs);
    }

    next();
  }, [text, speed, size, scrollLevel, stop, startAutoScroll]);

  animateRef.current = animate;

  const handlePaste = useCallback(async () => {
    try {
      const clipText = await navigator.clipboard.readText();
      if (clipText && clipText.trim()) {
        setText(clipText.trim());
        setTimeout(() => {
          animateRef.current();
        }, 500);
      }
    } catch (err) {
      // Clipboard access denied — silently ignore
    }
  }, []);

  const handlePlayStop = useCallback(() => {
    if (running) {
      stop();
    } else {
      animate();
    }
  }, [running, stop, animate]);

  const handleClose = useCallback(() => {
    stop();
    setIsOpen(false);
  }, [stop]);

  // Handle scroll slider: live-adjust speed if scroll is active, or seek position if idle
  const handleScrollChange = useCallback((e) => {
    const val = parseInt(e.target.value);
    setScrollLevel(val);
    localStorage.setItem('cw-scroll', val);
    const scrollActive = !!scrollTimerRef.current;
    if (scrollActive || running) {
      // Restart scroll interval at new speed immediately
      if (val > 0) {
        startAutoScroll(val);
      } else {
        clearInterval(scrollTimerRef.current);
        scrollTimerRef.current = null;
      }
    } else if (outputRef.current) {
      // Idle: use slider to seek to position in output
      const maxScroll = outputRef.current.scrollHeight - outputRef.current.clientHeight;
      if (maxScroll > 0) {
        outputRef.current.scrollTop = (val / 5) * maxScroll;
      }
    }
  }, [running, startAutoScroll]);

  // Close on Escape
  useEffect(() => {
    if (!isOpen) return;
    const handleKey = (e) => {
      if (e.key === 'Escape') handleClose();
    };
    window.addEventListener('keydown', handleKey);
    return () => window.removeEventListener('keydown', handleKey);
  }, [isOpen, handleClose]);

  return (
    <>
      <div className="cursive-trigger">
        <button
          className="cursive-trigger-btn"
          onClick={() => { setText(cleanText(textContent)); setIsOpen(true); }}
          title="Open cursive writing view"
        >
          Cursive
        </button>
      </div>

      {isOpen && (
        <div className="cw-overlay" onClick={handleClose}>
          <div className="cw-modal" onClick={(e) => e.stopPropagation()}>
            <button className="cw-close-btn" onClick={handleClose}>&times;</button>
            <h2 className="cw-title">Cursive Writing</h2>
            <p className="cw-subtitle">animate your words in ink</p>

            <div className="cw-card">
              <div className="cw-output-wrap">
                <div className="cw-output" ref={outputRef}>
                  <div className="cw-output-text" ref={outputTextRef} style={{ fontSize: `${size}px` }}></div>
                </div>
              </div>

              <div className="cw-controls">
                <div className="cw-field">
                  <label className="cw-label">Your text</label>
                  <textarea
                    className="cw-textarea"
                    rows="3"
                    value={text}
                    onChange={(e) => setText(e.target.value)}
                    placeholder="Text auto-fills from viewer. Highlight text in the viewer to load it here."
                  />
                </div>

                <div className="cw-side-controls">
                  <div className="cw-slider-group">
                    <div className="cw-slider-label">
                      <span>Speed</span><span>{speed}</span>
                    </div>
                    <input
                      type="range" min="1" max="5" value={speed}
                      onChange={(e) => { const v = parseInt(e.target.value); setSpeed(v); localStorage.setItem('cw-speed', v); }}
                    />
                  </div>
                  <div className="cw-slider-group">
                    <div className="cw-slider-label">
                      <span>Size</span><span>{size}</span>
                    </div>
                    <input
                      type="range" min="28" max="80" value={size}
                      onChange={(e) => {
                        const v = parseInt(e.target.value);
                        setSize(v);
                        localStorage.setItem('cw-size', v);
                        if (outputTextRef.current) outputTextRef.current.style.fontSize = v + 'px';
                      }}
                    />
                  </div>
                  <div className="cw-slider-group">
                    <div className="cw-slider-label">
                      <span>Scroll</span><span>{scrollLevel}</span>
                    </div>
                    <input
                      type="range" min="0" max="5" value={scrollLevel}
                      onChange={handleScrollChange}
                    />
                  </div>
                  <div className="cw-btn-row">
                    <button className="cw-btn cw-btn-primary" onClick={handlePlayStop}>
                      {running ? 'Stop' : 'Write'}
                    </button>
                    <button className="cw-btn" onClick={() => { if (scrolling) { clearInterval(scrollTimerRef.current); scrollTimerRef.current = null; setScrolling(false); } else { startAutoScroll(scrollLevel); } }} title="Toggle auto-scroll">{scrolling ? 'Stop Scroll' : 'Scroll'}</button>
                    <button className="cw-btn" onClick={handlePaste} title="Paste from clipboard and auto-write">Paste</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

export default CursiveWriterModal;
