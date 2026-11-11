import React, { lazy, Suspense, useState } from 'react';
import FeedViewer from './components/FeedViewer';
import './App.css';

const TextViewer = lazy(() => import('./TextViewer'));

function App() {
  const [view, setView] = useState(() =>
    window.__LOCAL_FEED__ || new URLSearchParams(window.location.search).get('view') === 'feed'
      ? 'feed' : 'reader'
  );
  return (
    <div className="App">
      {view === 'feed'
        ? <FeedViewer onOpenReader={() => setView('reader')} />
        : <Suspense fallback={<p>Loading reader…</p>}><TextViewer onOpenFeed={() => setView('feed')} /></Suspense>}
    </div>
  );
}

export default App;
