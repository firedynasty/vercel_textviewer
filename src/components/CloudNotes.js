import React, { useState, useCallback } from 'react';

const NOTES_FILENAME = 'notes.txt';

function CloudNotes({ isOpen, onClose }) {
  const [content, setContent] = useState('');
  const [accessCode, setAccessCode] = useState('');
  const [isUnlocked, setIsUnlocked] = useState(false);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [status, setStatus] = useState('');

  // Unlock and load notes
  const handleUnlock = async () => {
    if (!accessCode) {
      setStatus('Enter access code');
      return;
    }

    setLoading(true);
    setStatus('Verifying...');

    try {
      const response = await fetch('/api/files', {
        method: 'GET',
        headers: {
          'x-access-code': accessCode
        }
      });

      if (response.ok) {
        const data = await response.json();
        setContent(data.files?.[NOTES_FILENAME] || '');
        setIsUnlocked(true);
        setStatus('');
      } else if (response.status === 401) {
        setStatus('Invalid access code');
        setIsUnlocked(false);
      } else {
        setStatus('Failed to load');
      }
    } catch (err) {
      setStatus('Error: ' + err.message);
    } finally {
      setLoading(false);
    }
  };

  // Save notes to API
  const saveNotes = async () => {
    if (!isUnlocked) return;

    setSaving(true);
    setStatus('Saving...');

    try {
      const response = await fetch('/api/files', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'x-access-code': accessCode
        },
        body: JSON.stringify({
          filename: NOTES_FILENAME,
          content: content
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

  // Reset state when modal closes
  const handleClose = useCallback(() => {
    setIsUnlocked(false);
    setContent('');
    setAccessCode('');
    setStatus('');
    onClose();
  }, [onClose]);

  if (!isOpen) return null;

  return (
    <div className="cloud-notes-overlay" onClick={handleClose}>
      <div className="cloud-notes-modal" onClick={(e) => e.stopPropagation()}>
        <div className="cloud-notes-header">
          <h3>☁️ Cloud Notes</h3>
          {status && <span className="cloud-notes-status">{status}</span>}
          <button className="cloud-notes-close" onClick={handleClose}>×</button>
        </div>

        {!isUnlocked ? (
          <div className="cloud-notes-unlock">
            <input
              type="password"
              placeholder="Enter access code"
              value={accessCode}
              onChange={(e) => setAccessCode(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && handleUnlock()}
              disabled={loading}
            />
            <button onClick={handleUnlock} disabled={loading}>
              {loading ? '...' : '🔓 Unlock'}
            </button>
          </div>
        ) : (
          <>
            <textarea
              className="cloud-notes-textarea"
              value={content}
              onChange={(e) => setContent(e.target.value)}
              placeholder="Write notes here..."
            />

            <div className="cloud-notes-footer">
              <button
                className="cloud-notes-save"
                onClick={saveNotes}
                disabled={saving}
              >
                Save
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

export default CloudNotes;
