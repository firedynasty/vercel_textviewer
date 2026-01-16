import React, { useState, useEffect, useCallback } from 'react';

const NOTES_FILENAME = 'notes.txt';

function CloudNotes({ isOpen, onClose }) {
  const [content, setContent] = useState('');
  const [accessCode, setAccessCode] = useState('');
  const [isUnlocked, setIsUnlocked] = useState(false);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [status, setStatus] = useState('');

  // Load notes from API
  const loadNotes = useCallback(async () => {
    setLoading(true);
    try {
      const response = await fetch('/api/files');
      if (response.ok) {
        const data = await response.json();
        setContent(data.files?.[NOTES_FILENAME] || '');
      }
    } catch (err) {
      setStatus('Failed to load');
    } finally {
      setLoading(false);
    }
  }, []);

  // Save notes to API
  const saveNotes = async () => {
    if (!isUnlocked || !accessCode) {
      setStatus('Unlock first');
      return;
    }

    setSaving(true);
    setStatus('Saving...');

    try {
      const response = await fetch('/api/files', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          filename: NOTES_FILENAME,
          content: content,
          accessCode: accessCode
        })
      });

      if (response.ok) {
        setStatus('Saved ✓');
      } else {
        setStatus('Save failed');
      }
    } catch (err) {
      setStatus('Save failed');
    } finally {
      setSaving(false);
    }
  };

  // Unlock with access code
  const handleUnlock = async () => {
    if (!accessCode) return;
    try {
      const response = await fetch('/api/auth', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ accessCode })
      });
      if (response.ok) {
        setIsUnlocked(true);
        setStatus('Unlocked');
      } else {
        setStatus('Invalid code');
      }
    } catch (err) {
      setStatus('Auth failed');
    }
  };

  // Load notes when modal opens
  useEffect(() => {
    if (isOpen) {
      loadNotes();
      setStatus('');
    }
  }, [isOpen, loadNotes]);

  if (!isOpen) return null;

  return (
    <div className="cloud-notes-overlay" onClick={onClose}>
      <div className="cloud-notes-modal" onClick={(e) => e.stopPropagation()}>
        <div className="cloud-notes-header">
          <h3>☁️ Cloud Notes</h3>
          {status && <span className="cloud-notes-status">{status}</span>}
          <button className="cloud-notes-close" onClick={onClose}>×</button>
        </div>

        <div className="cloud-notes-unlock">
          <input
            type="password"
            placeholder="Access code"
            value={accessCode}
            onChange={(e) => setAccessCode(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && handleUnlock()}
          />
          <button onClick={handleUnlock} disabled={isUnlocked}>
            {isUnlocked ? '🔓' : '🔒'}
          </button>
        </div>

        <textarea
          className="cloud-notes-textarea"
          value={content}
          onChange={(e) => setContent(e.target.value)}
          placeholder={isUnlocked ? "Write notes here..." : "Unlock to edit"}
          disabled={!isUnlocked || loading}
        />

        <div className="cloud-notes-footer">
          <button
            className="cloud-notes-save"
            onClick={saveNotes}
            disabled={!isUnlocked || saving}
          >
            Save
          </button>
        </div>
      </div>
    </div>
  );
}

export default CloudNotes;
