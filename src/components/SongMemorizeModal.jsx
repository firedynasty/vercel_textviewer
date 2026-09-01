import React, { useState, useEffect } from 'react';

// ── Parser ────────────────────────────────────────────────────────────────────
export function parseSongTxt(text) {
  const SECTION_RE = /^\[([^\]]+)\]$/;
  const sections = [];
  let current = null;
  for (const raw of text.split(/\r?\n/)) {
    const trimmed = raw.trim();
    const m = trimmed.match(SECTION_RE);
    if (m) {
      current = { id: m[1], lines: [] };
      sections.push(current);
    } else if (current && trimmed) {
      current.lines.push(trimmed);
    }
  }
  return sections;
}

function sectionLabel(id) {
  if (id === 'C') return 'Chorus';
  if (id === 'T') return 'Tag';
  if (id === 'B') return 'Bridge';
  if (/^V(\d+)$/.test(id)) return `Verse ${id.slice(1)}`;
  return id;
}

// ── TTS ───────────────────────────────────────────────────────────────────────
function speakText(text) {
  if (!text || !('speechSynthesis' in window)) return;
  window.speechSynthesis.cancel();
  const utt = new SpeechSynthesisUtterance(text);
  utt.lang = 'en-US';
  utt.rate = 0.9;
  const voices = window.speechSynthesis.getVoices();
  const voice =
    voices.find(v => v.lang.startsWith('en') && (v.name.includes('Enhanced') || v.name.includes('Premium'))) ||
    voices.find(v => v.lang.startsWith('en-US')) ||
    voices.find(v => v.lang.startsWith('en'));
  if (voice) utt.voice = voice;
  window.speechSynthesis.speak(utt);
}

// ── Modal ─────────────────────────────────────────────────────────────────────
export default function SongMemorizeModal({
  open, onClose,
  sections = [],
  songTitle = 'Song',
  darkMode,
}) {
  const [selectedId, setSelectedId] = useState(null);
  const [activeLine, setActiveLine] = useState(null);

  const bg        = darkMode ? '#1e2235' : '#ffffff';
  const textColor = darkMode ? '#e0e0e0' : '#1a1a1a';
  const border    = darkMode ? '#3a3f5c' : '#e5e7eb';
  const subText   = darkMode ? '#9ca3af' : '#6b7280';
  const verseBg   = darkMode ? '#252840' : '#f9fafb';
  const closeBg   = darkMode ? '#444'    : '#e5e7eb';
  const accent    = darkMode ? '#60a5fa' : '#2563eb';
  const hoverBg   = darkMode ? '#2d3148' : '#f3f4f6';
  const activeBg  = darkMode ? '#1e3a5f' : '#dbeafe';

  const selectedSection = sections.find(s => s.id === selectedId) || null;
  const selectedIdx = sections.findIndex(s => s.id === selectedId);

  const goToPrev = () => {
    if (selectedIdx > 0) { setSelectedId(sections[selectedIdx - 1].id); }
  };
  const goToNext = () => {
    if (selectedIdx < sections.length - 1) { setSelectedId(sections[selectedIdx + 1].id); }
  };

  useEffect(() => {
    if (open) { setSelectedId(null); setActiveLine(null); }
  }, [open]);

  useEffect(() => { setActiveLine(null); }, [selectedId]);

  useEffect(() => {
    if (!open) return;
    const handler = (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        if (selectedId) setSelectedId(null); else onClose();
        return;
      }
      if (selectedSection) {
        if (e.key === 'ArrowLeft') { e.preventDefault(); if (selectedIdx > 0) setSelectedId(sections[selectedIdx - 1].id); return; }
        if (e.key === 'ArrowRight') { e.preventDefault(); if (selectedIdx < sections.length - 1) setSelectedId(sections[selectedIdx + 1].id); return; }
        if (/^[1-9]$/.test(e.key)) {
          const idx = parseInt(e.key) - 1;
          if (selectedSection.lines[idx] !== undefined) {
            e.preventDefault();
            setActiveLine(idx);
            speakText(selectedSection.lines[idx]);
          }
        }
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [open, selectedId, selectedSection, onClose, selectedIdx, sections]);

  if (!open) return null;

  return (
    <div
      onClick={onClose}
      style={{
        position: 'fixed', inset: 0, zIndex: 10200,
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        background: 'rgba(0,0,0,0.6)', padding: '1rem',
      }}
      role="dialog"
      aria-modal="true"
    >
      <div
        onClick={e => e.stopPropagation()}
        style={{
          background: bg, color: textColor,
          borderRadius: 12, border: `1px solid ${border}`,
          width: '100%', maxWidth: 500,
          maxHeight: '80vh', display: 'flex', flexDirection: 'column',
          boxShadow: '0 24px 64px rgba(0,0,0,0.4)',
        }}
      >
        {/* Header */}
        <div style={{
          display: 'flex', justifyContent: 'space-between', alignItems: 'center',
          padding: '12px 18px', borderBottom: `1px solid ${border}`, flexShrink: 0, gap: 8,
        }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, minWidth: 0 }}>
            {selectedId && (
              <button
                onClick={goToPrev}
                disabled={selectedIdx <= 0}
                style={{
                  padding: '3px 8px', fontSize: '0.82rem', fontWeight: 700,
                  border: `1px solid ${border}`, borderRadius: 6,
                  background: hoverBg, color: selectedIdx <= 0 ? subText : textColor,
                  cursor: selectedIdx <= 0 ? 'default' : 'pointer', flexShrink: 0,
                  opacity: selectedIdx <= 0 ? 0.4 : 1,
                }}
                aria-label="Previous section"
              >←</button>
            )}
            <h2 style={{
              margin: 0, fontSize: '0.98rem', fontWeight: 700,
              whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis',
            }}>
              {selectedId ? sectionLabel(selectedId) : songTitle}
            </h2>
            {selectedId && (
              <button
                onClick={goToNext}
                disabled={selectedIdx >= sections.length - 1}
                style={{
                  padding: '3px 8px', fontSize: '0.82rem', fontWeight: 700,
                  border: `1px solid ${border}`, borderRadius: 6,
                  background: hoverBg, color: selectedIdx >= sections.length - 1 ? subText : textColor,
                  cursor: selectedIdx >= sections.length - 1 ? 'default' : 'pointer', flexShrink: 0,
                  opacity: selectedIdx >= sections.length - 1 ? 0.4 : 1,
                }}
                aria-label="Next section"
              >→</button>
            )}
          </div>
          <button
            onClick={onClose}
            style={{
              width: 28, height: 28, border: 'none', borderRadius: 6,
              cursor: 'pointer', background: closeBg, color: textColor,
              fontWeight: 700, fontSize: 14, flexShrink: 0,
            }}
            aria-label="Close"
          >✕</button>
        </div>

        {/* Body */}
        <div style={{ overflowY: 'auto', flex: 1, padding: '12px 18px' }}>
          {!selectedId ? (
            /* Section picker */
            <>
              <p style={{
                margin: '0 0 10px', fontSize: '0.72rem', color: subText,
                textTransform: 'uppercase', letterSpacing: '0.06em', fontWeight: 600,
              }}>
                Choose a section to memorize
              </p>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                {sections.length === 0 ? (
                  <p style={{ color: subText, fontSize: '0.88rem' }}>
                    No sections found. Make sure the file uses [C], [V1], [V2]… markers.
                  </p>
                ) : sections.map(sec => (
                  <button
                    key={sec.id}
                    onClick={() => setSelectedId(sec.id)}
                    style={{
                      display: 'flex', alignItems: 'flex-start', gap: 12,
                      padding: '10px 14px', borderRadius: 8, cursor: 'pointer',
                      border: `1px solid ${border}`,
                      background: verseBg, color: textColor,
                      textAlign: 'left', width: '100%',
                    }}
                    onMouseEnter={e => { e.currentTarget.style.background = hoverBg; }}
                    onMouseLeave={e => { e.currentTarget.style.background = verseBg; }}
                  >
                    <span style={{
                      fontWeight: 700, color: accent, fontSize: '0.82rem',
                      minWidth: 54, flexShrink: 0, paddingTop: 2,
                    }}>
                      [{sec.id}]
                    </span>
                    <span style={{ lineHeight: 1.5, fontSize: '0.88rem', textAlign: 'left' }}>
                      <strong style={{ display: 'block', marginBottom: 2 }}>
                        {sectionLabel(sec.id)}
                      </strong>
                      <span style={{ color: subText, fontSize: '0.82rem' }}>
                        {sec.lines[0]?.slice(0, 60)}{sec.lines[0]?.length > 60 ? '…' : ''}
                      </span>
                    </span>
                  </button>
                ))}
              </div>
            </>
          ) : (
            /* Lines view */
            <>
              <p style={{
                margin: '0 0 10px', fontSize: '0.72rem', color: subText,
                textTransform: 'uppercase', letterSpacing: '0.06em', fontWeight: 600,
              }}>
                Click or press 1–{Math.min(selectedSection?.lines.length ?? 0, 9)} to hear a line
              </p>
              {selectedSection?.lines.slice(0, 9).map((line, i) => (
                <div
                  key={i}
                  onClick={() => { setActiveLine(i); speakText(line); }}
                  style={{
                    display: 'flex', alignItems: 'flex-start', gap: 10,
                    padding: '9px 12px', borderRadius: 7, cursor: 'pointer',
                    marginBottom: 4,
                    background: activeLine === i ? activeBg : 'transparent',
                    border: activeLine === i ? `1px solid ${accent}40` : '1px solid transparent',
                  }}
                  onMouseEnter={e => { if (activeLine !== i) e.currentTarget.style.background = hoverBg; }}
                  onMouseLeave={e => { e.currentTarget.style.background = activeLine === i ? activeBg : 'transparent'; }}
                >
                  <span style={{
                    fontWeight: 700, color: accent, minWidth: 18,
                    fontSize: '0.92rem', lineHeight: 1.7, flexShrink: 0,
                  }}>
                    {i + 1}
                  </span>
                  <span style={{ lineHeight: 1.65, fontSize: '0.97rem' }}>{line}</span>
                </div>
              ))}
            </>
          )}
        </div>
      </div>
    </div>
  );
}
