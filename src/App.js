import React, { lazy, Suspense, useState } from 'react';
import FeedViewer from './components/FeedViewer';
import { RETURN_KEY } from './hooks/useDropboxFeed';
import './App.css';

const TextViewer = lazy(() => import('./TextViewer'));

function App() {
  const [view, setView] = useState(() => {
    // A Dropbox sign-in returns to the bare origin, so the pending return marks where it came from.
    let returning = false;
    try { returning = sessionStorage.getItem(RETURN_KEY) !== null; } catch { /* private window */ }
    return window.__LOCAL_FEED__ || returning
      || new URLSearchParams(window.location.search).get('view') === 'feed' ? 'feed' : 'reader';
  });
  return (
    <div className="App">
      {view === 'feed'
        ? <FeedViewer onOpenReader={() => setView('reader')} />
        : <Suspense fallback={<p>Loading reader…</p>}><TextViewer onOpenFeed={() => setView('feed')} /></Suspense>}
    </div>
  );
}

export default App;
