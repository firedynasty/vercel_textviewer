import React, { memo, useEffect, useMemo, useRef, useState } from 'react';
import useLiveFeed from '../hooks/useLiveFeed';
import { changedPaths, filterFeed } from '../utils/feedUtils';
import FeedMarkdown from './FeedMarkdown';
import './FeedViewer.css';

const FILTERS = [['all', 'Everything'], ['note', 'Notes'], ['image', 'Images'], ['video', 'Videos']];
const LABELS = { note: 'Note', image: 'Image', video: 'Video' };

function Note({ item, formatted }) {
  return formatted && item.text
    ? <FeedMarkdown text={item.text} plainText={!/\.(md|markdown)$/i.test(item.name)} />
    : <div className="feed-plain-text">{item.text || <span className="feed-muted">Empty note</span>}</div>;
}

function Media({ item, onExpand }) {
  const container = useRef(null);
  const video = useRef(null);
  const [nearby, setNearby] = useState(false);
  const [failed, setFailed] = useState(false);
  useEffect(() => { setFailed(false); }, [item.url]);
  useEffect(() => {
    if (!window.IntersectionObserver) { setNearby(true); return; }
    const load = new IntersectionObserver(entries => {
      if (entries.some(entry => entry.isIntersecting)) setNearby(true);
    }, { rootMargin: '500px' });
    const pause = new IntersectionObserver(entries => {
      if (!entries[0].isIntersecting) video.current?.pause();
    });
    load.observe(container.current);
    pause.observe(container.current);
    return () => { load.disconnect(); pause.disconnect(); };
  }, []);
  useEffect(() => {
    const pauseHidden = () => { if (document.hidden) video.current?.pause(); };
    document.addEventListener('visibilitychange', pauseHidden);
    return () => document.removeEventListener('visibilitychange', pauseHidden);
  }, []);
  return <div className="feed-media" ref={container}>
    {failed ? <div className="feed-media-error">Chrome could not display this {item.type}. <a href={item.url} target="_blank" rel="noreferrer">Open original file</a></div>
      : nearby && (item.type === 'image'
        ? <button className="feed-image-button" onClick={() => onExpand(item)} aria-label={`Enlarge ${item.name}`}><img src={item.url} alt={item.name} loading="lazy" onError={() => setFailed(true)} /></button>
        : <video ref={video} src={item.url} controls playsInline preload="metadata" aria-label={item.name} onError={() => setFailed(true)} />)}
  </div>;
}

const FeedCard = memo(function FeedCard({ item, onExpand, formatted }) {
  const [expanded, setExpanded] = useState(false);
  const [copied, setCopied] = useState(false);
  const copyTimer = useRef(null);
  useEffect(() => () => clearTimeout(copyTimer.current), []);
  const date = new Date(item.modified);
  const longNote = (item.text || '').length > 600 || (item.text || '').split('\n').length > 10;
  const tags = [...new Set((item.text || '').match(/#[\p{L}\p{N}_-]+/gu) || [])].slice(0, 8);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(item.text || '');
      setCopied(true);
      copyTimer.current = setTimeout(() => setCopied(false), 1600);
    } catch { setCopied(false); }
  };
  return <article className="feed-card" data-feed-path={item.path}>
    <header className="feed-card-heading">
      <span className={`feed-file-icon feed-file-icon--${item.type}`} aria-hidden="true">{item.type === 'note' ? 'Aa' : item.type === 'image' ? '▧' : '▶'}</span>
      <div><h2>{item.name}</h2><p title={item.path}>{item.path.includes('/') ? item.path.slice(0, item.path.lastIndexOf('/')) + ' · ' : ''}{LABELS[item.type]} <span aria-hidden="true">·</span> <time dateTime={date.toISOString()} title={date.toLocaleString()}>{date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}, {date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}</time></p></div>
    </header>
    {item.type === 'note'
      ? <><div className={`feed-note${longNote && !expanded ? ' feed-note--clipped' : ''}`}><Note item={item} formatted={formatted} /></div>
        {item.truncated && <p className="feed-file-warning">Preview limited to 256 KB. Open the original for the full note.</p>}
        {item.error && <p className="feed-file-warning">{item.error}</p>}
        <footer className="feed-card-footer"><div className="feed-tags">{tags.map(tag => <span key={tag}>{tag}</span>)}</div><div className="feed-card-actions">
          {longNote && <button onClick={() => setExpanded(value => !value)}>{expanded ? 'Collapse' : 'Read more'}</button>}
          <button onClick={() => onExpand(item)}>Expand</button><button onClick={copy}>{copied ? 'Copied' : 'Copy'}</button>
        </div></footer></>
      : <Media item={item} onExpand={onExpand} />}
  </article>;
});

function PreviewDialog({ item, onClose, formatted }) {
  const dialog = useRef(null);
  useEffect(() => {
    const previous = document.activeElement;
    const element = dialog.current;
    element.showModal();
    return () => { element.close(); previous?.focus(); };
  }, []);
  return <dialog ref={dialog} className="feed-dialog" onCancel={onClose} onClick={event => { if (event.target === dialog.current) onClose(); }} aria-label={item.name}>
    <div className="feed-dialog-content"><header><h2>{item.name}</h2><button onClick={onClose} autoFocus aria-label="Close preview">×</button></header>
      {item.type === 'image' ? <img src={item.url} alt={item.name} /> : <Note item={item} formatted={formatted} />}
      {item.url && <a href={item.url} target="_blank" rel="noreferrer">Open original file ↗</a>}
    </div>
  </dialog>;
}

export default function FeedViewer({ onOpenReader }) {
  const [recursive, setRecursive] = useState(false);
  const [items, setItems] = useState(null);
  const feed = useLiveFeed(recursive, items);
  const [pending, setPending] = useState(null);
  const [query, setQuery] = useState('');
  const [filter, setFilter] = useState('all');
  const [formattedNotes, setFormattedNotes] = useState(true);
  const [selected, setSelected] = useState(null);
  const [limit, setLimit] = useState(40);
  const [dark, setDark] = useState(() => localStorage.getItem('feed-dark') === 'true');
  const scrolling = useRef(null);
  const sentinel = useRef(null);
  const search = useRef(null);

  useEffect(() => {
    const focusSearch = event => {
      if (event.key === '/' && !selected && !['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName)) {
        event.preventDefault();
        search.current?.focus();
      }
    };
    window.addEventListener('keydown', focusSearch);
    return () => window.removeEventListener('keydown', focusSearch);
  }, [selected]);

  useEffect(() => { localStorage.setItem('feed-dark', String(dark)); }, [dark]);
  useEffect(() => {
    if (!feed.snapshot) { setItems(null); setPending(null); return; }
    const next = feed.snapshot.items;
    if (items === null) { setItems(next); return; }
    const changes = changedPaths(items, next);
    if (!changes.size) { setPending(null); return; }
    const playing = [...(scrolling.current?.querySelectorAll('video') || [])].some(video => !video.paused);
    if ((scrolling.current?.scrollTop || 0) < 60 && !selected && !playing) {
      setItems(next);
      setPending(null);
    } else {
      setPending({ items: next, count: changes.size });
    }
  }, [feed.snapshot, items, selected]);

  const filtered = useMemo(() => filterFeed(items || [], query, filter), [items, query, filter]);
  useEffect(() => { setLimit(40); scrolling.current?.scrollTo(0, 0); }, [query, filter]);
  useEffect(() => {
    if (!window.IntersectionObserver || !sentinel.current) return;
    const observer = new IntersectionObserver(entries => {
      if (entries[0].isIntersecting) setLimit(value => value + 40);
    }, { root: scrolling.current, rootMargin: '500px' });
    observer.observe(sentinel.current);
    return () => observer.disconnect();
  }, [filtered.length, limit]);

  const counts = useMemo(() => (items || []).reduce((result, item) => {
    result[item.type] = (result[item.type] || 0) + 1;
    return result;
  }, {}), [items]);
  const openPreview = React.useCallback(item => setSelected(item), []);
  const applyUpdates = () => {
    setItems(pending.items);
    setPending(null);
    scrolling.current?.scrollTo({ top: 0 });
  };

  return <div className={`feed-app${dark ? ' feed-dark' : ''}`}>
    <aside className="feed-sidebar">
      <div className="feed-brand"><span aria-hidden="true">▤</span><div>Folder feed<small>Your files, in the flow.</small></div></div>
      <div className="feed-source-label">YOUR FOLDER</div>
      <div className="feed-folder" title={feed.snapshot?.folder}>{feed.snapshot?.folder?.split('/').filter(Boolean).pop() || 'Choose a folder'}</div>
      {!feed.isServer && <button className="feed-open-folder" onClick={feed.chooseFolder}>{feed.hasFolder ? 'Change folder' : 'Open folder'} <span aria-hidden="true">↗</span></button>}
      <nav aria-label="File types">{FILTERS.map(([type, label]) => <button key={type} className={filter === type ? 'is-active' : ''} onClick={() => setFilter(type)} aria-pressed={filter === type}><span>{label}</span><span>{type === 'all' ? items?.length || 0 : counts[type] || 0}</span></button>)}</nav>
      <label className="feed-recursive"><input type="checkbox" checked={recursive} onChange={event => setRecursive(event.target.checked)} /> Include subfolders</label>
      <div className="feed-sidebar-bottom"><button onClick={onOpenReader}>Open file reader ↗</button><button onClick={() => setDark(value => !value)}>{dark ? 'Light appearance' : 'Dark appearance'}</button>{feed.isServer && !feed.stopped && <button onClick={feed.stop}>Stop local server</button>}</div>
    </aside>
    <main className="feed-main">
      <header className="feed-topbar"><div><span className={`feed-status-dot${!feed.hasFolder || feed.error || feed.stopped ? ' feed-status-dot--idle' : ''}`} />{feed.stopped ? 'Server stopped' : feed.error ? 'Connection needs attention' : feed.hasFolder ? 'Live folder' : 'Local files'}</div><span>Notes · Images · Videos</span></header>
      <div className="feed-scroll" ref={scrolling}>
        <div className="feed-column">
          <div className="feed-page-heading"><div><p>YOUR PERSONAL STREAM</p><h1>{FILTERS.find(([type]) => type === filter)[1]}</h1><div className="feed-subtitle">A little space for everything you save.</div></div><div className="feed-heading-actions"><button className={`txt-md-toggle-btn feed-format-toggle${formattedNotes ? ' active' : ''}`} onClick={() => setFormattedNotes(value => !value)} aria-pressed={formattedNotes} title="Toggle formatted view (bullets * lines, auto-link, paragraphs)">TXT&gt;MD</button>{feed.hasFolder && !feed.stopped && <button className="feed-refresh" onClick={feed.refresh} aria-label="Refresh folder" title="Refresh folder">↻</button>}</div></div>
          <label className="feed-search"><span aria-hidden="true">⌕</span><input ref={search} type="search" value={query} onChange={event => setQuery(event.target.value)} placeholder="Search filenames, notes, or #tags" aria-label="Search feed" /><kbd>/</kbd></label>
          {feed.error && <div className="feed-notice" role="alert">{feed.error} <button onClick={feed.refresh}>Retry</button></div>}
          {pending && <button className="feed-update" onClick={applyUpdates}>{pending.count} {pending.count === 1 ? 'change' : 'changes'} available · Show updates ↑</button>}
          {feed.stopped && <div className="feed-notice">The local server has stopped. Run the launcher again to reconnect.</div>}
          {!feed.hasFolder && <div className="feed-empty"><div aria-hidden="true">▤</div><h2>Your folder becomes a feed.</h2><p>Choose a folder of notes, images, and videos. Save files in your usual apps and see updates here.</p><button className="feed-primary" onClick={feed.chooseFolder}>Choose a folder</button><small>Files stay on your computer.</small></div>}
          {feed.hasFolder && items === null && feed.loading && <div className="feed-empty" role="status">Reading your folder…</div>}
          {items !== null && filtered.length === 0 && <div className="feed-empty"><h2>{query ? 'No matching files' : 'Nothing here yet'}</h2><p>{query ? 'Try another filename, phrase, or hashtag.' : 'Save a .txt, .md, image, or video in this folder, or include subfolders.'}</p></div>}
          <div className="feed-cards">{filtered.slice(0, limit).map(item => <FeedCard key={item.path} item={item} onExpand={openPreview} formatted={formattedNotes} />)}</div>
          {filtered.length > limit && <div ref={sentinel} className="feed-more"><button onClick={() => setLimit(value => value + 40)}>Load more files</button></div>}
          {filtered.length > 0 && filtered.length <= limit && <p className="feed-end">You're all caught up. New saves will appear here.</p>}
        </div>
      </div>
    </main>
    {selected && <PreviewDialog item={selected} onClose={() => setSelected(null)} formatted={formattedNotes} />}
  </div>;
}
