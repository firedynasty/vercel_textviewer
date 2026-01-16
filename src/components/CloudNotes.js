import React, { useState, useEffect, useCallback } from 'react';

const NOTES_FILENAME = 'notes.txt';

function CloudNotes({ isOpen, onClose }) {
  const [content, setContent] = useState('');
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [status, setStatus] = useState('');

  // Load notes from API
  const loadNotes = useCallback(async () => {
    setLoading(true);
    setStatus('Loading...');
    try {
      const response = await fetch('/api/files');
      console.log('Load response status:', response.status);
      if (response.ok) {
        const data = await response.json();
        console.log('Loaded data:', data);
        setContent(data.files?.[NOTES_FILENAME] || '');
        setStatus('');
      } else {
        const errorText = await response.text();
        console.error('Load error:', response.status, errorText);
        setStatus(`Load failed: ${response.status}`);
      }
    } catch (err) {
      console.error('Load exception:', err);
      setStatus('Failed to load: ' + err.message);
    } finally {
      setLoading(false);
    }
  }, []);

  // Save notes to API
  const saveNotes = async () => {
    setSaving(true);
    setStatus('Saving...');

    try {
      const response = await fetch('/api/files', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
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

        <textarea
          className="cloud-notes-textarea"
          value={content}
          onChange={(e) => setContent(e.target.value)}
          placeholder="Write notes here..."
          disabled={loading}
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
      </div>
    </div>
  );
}

export default CloudNotes;
