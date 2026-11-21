# Local feed: load note text per chunk

Status: not started. Do this only when a folder becomes too big for the current model.

## Where things stand (2026-11-20)

- `local_feed/server.py` `Feed.snapshot()` walks the whole folder and returns every
  file with each note's full text (up to `NOTE_LIMIT` = 256 KiB per note).
- The browser polls `/api/feed` every 2 s. Since the fingerprint change, it sends
  `?since=<fingerprint>` and an unchanged folder answers `{unchanged: true}`, so the
  full payload goes over the wire only on first load and after a save, rename or delete.
- The feed UI shows 15 files per chunk (`CHUNK` in `src/components/FeedViewer.js`), but
  search, sort, tags and "N changes available" all run in the browser over every
  note's text.
- Measured: `~/Documents/notes/spirituality` has 2,466 files and 426 text notes, about
  2 MB of text per full snapshot. That is fine today.

## When to do this

Any one of these:
- A single snapshot passes about 20 MB, or several thousand text notes.
- First load or the reload after each save takes noticeably long (more than about 1 s).
- Chrome memory for the feed tab grows into hundreds of MB.

## What to build

1. **List-only snapshot.** `/api/feed` returns path, name, type, size, modified, url,
   and the `chat`/`worksheet` flags, but no `text`. The server still caches each
   note's text keyed by `mtime:size` so it can work out those flags and run searches.
   Keep the fingerprint / `since` behavior.
2. **Text per chunk.** New `GET /api/notes?paths=a.md,b.md` (or POST with a JSON list),
   capped at about `CHUNK * 2` paths, returns `{path: {text, truncated, version}}`.
   The browser fetches text for the visible chunk, prefetches the next chunk, and keeps
   an LRU cache keyed by `path + version`.
3. **Server-side search.** `GET /api/search?q=...&type=...` returns the ordered list of
   matching paths. It must match `filterFeed` in `src/utils/feedUtils.js`: filename,
   content and `#tag` matching. The browser keeps sorting and chunking that list.
   Debounce typing (about 250 ms).
4. **Changes.** Diff list-only snapshots by `path + modified + size`. A changed note in
   the visible chunk gets its text fetched again. Others refetch lazily.
5. **Things that need all the text today. Each one needs a server path or a per-chunk
   fallback:**
   - Sidebar tag and type counts (`counts` in `FeedViewer.js`).
   - Context & chat **Add all notes in view** (`collectable`): fetch the text of the
     filtered paths in batches, within the 60,000-character budget.
   - Practice worksheets built from many notes.
   - **Expand all** / **Collapse all**: unaffected, since they act only on the visible
     chunk.
   - **Scroll all** mode (chunks off): fetch more text as the user scrolls, 40 at a time
     as now.
6. **Dropbox and folder-picker modes** (`useLiveFeed.js` `scanDropbox`, `scanDirectory`)
   read files in the browser and do not use the server. Leave them on the current
   full-read model unless they also become slow.

## Tests to add

- `local_feed/test_server.py`: the list-only snapshot has no `text`; `/api/notes`
  enforces the path cap and the guards used by `content_path` (`..`, hidden files,
  symlinks, wrong extension); `/api/search` returns the same paths as `filterFeed` for
  filename, content and tag queries.
- `src/components/FeedViewer.test.js`: changing chunks fetches only that chunk's text;
  a search hits `/api/search`; editing a visible note refetches its text.
