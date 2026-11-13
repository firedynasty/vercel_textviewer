# Live folder feed

Read `.txt` / `.md` / `.docx` notes, PDF links, images, and videos together in Chrome. New saves,
edits, and deletions are checked every two seconds. The most recently modified
files appear first. While you are reading farther down or playing a video,
changes wait behind **Show updates** so the page stays still.

The viewer stays in this repository. Reading never changes your content folder:
no PHP, copied `index.php`, generated index, or cleanup command. **Save chat** and
**Save answer as note** explicitly create new Markdown files in the opened folder.
Python serves the built
React app and streams your media separately, including byte ranges for video
seeking. No extra Python packages or Chrome extension are needed.

## Build once

From the repository:

```sh
npm install                 # only if dependencies are not installed yet
npm run build:feed
```

This produces `dist/local-feed`, separate from any existing `build` output.
Run `npm run build:feed` again after updating the React source. The launcher
reads the build on each request, so you can refresh Chrome after rebuilding.
Rerun the Finder Quick Action after updating: it upgrades an older running folder
server automatically when the API version changes.
Python 3.9+ and desktop Chrome are required for the local launcher.

## Open any folder

```sh
npm run feed -- "/path/to/your notes"
```

Or call the launcher directly, from any directory:

```sh
python3 /path/to/vercel_textviewer/local_feed/server.py "/path/to/your notes"
```

It chooses an available localhost port, starts in the background, opens Chrome,
and returns to your shell. Launching the same folder again reuses that server;
different folders can have separate feeds. Closing Chrome leaves the server
running. Stop it using **Stop local server** in the sidebar or:

```sh
python3 local_feed/server.py "/path/to/your notes" --status
python3 local_feed/server.py "/path/to/your notes" --stop
```

Additional options: `--no-open` prints the URL, `--port 8000` requests a specific
port for a new server, and `--foreground` runs until Ctrl-C. Runtime state and
logs live in `~/.cache/textviewer-feed`; override with
`TEXTVIEWER_FEED_STATE_DIR` or `--state-dir`.

An optional shell function, similar to `termwatch()`:

```sh
feedwatch() {
  command python3 "$HOME/Documents/technical/github/vercel_textviewer/local_feed/server.py" "$@"
}
```

Then `feedwatch` opens the current folder, `feedwatch ~/Documents/notes` opens
another one, and `feedwatch --stop` stops the current folder's feed. This watches
a fixed folder; it does not follow subsequent terminal `cd` commands.

## Finder Quick Action

After building, run once:

```sh
python3 local_feed/install_quick_action.py
```

Right-click a folder → **Quick Actions → Open Folder Feed**. Selecting a file
opens its containing folder. Only the first selected item is used. With no
selection, the AppleScript uses the front Finder window. macOS may request
Finder automation access on first use.

The action runs the launcher directly: no port prompt and no Terminal window.
The installer records this checkout's absolute path and your Python executable.
It refuses to overwrite an existing action. If you move the repo, rename or
remove the old action in `~/Library/Services` and install again.

To generate a reviewable workflow elsewhere without installing it:

```sh
python3 local_feed/install_quick_action.py --output-dir dist/finder-preview
```

The workflow includes `Contents/action.applescript`, with the paths filled in,
which you can paste into an existing Automator **Run AppleScript** action.
The source template is [Open Folder Feed.applescript](Open%20Folder%20Feed.applescript).

## Feed controls

- Search filenames, subfolder paths, note contents, or hashtags; `/` focuses search.
- Filter everything, notes, PDFs, images, or videos. Enable **Include subfolders** when needed.
- **PDFs** lists file paths as links that open the original PDF in a new tab or
  window. PDFs load only when clicked; search matches their names and paths.
- Expand long notes, copy their text, and click images to enlarge.
- **TXT>MD** starts on: notes use Markdown typography, bullets, paragraphs, and
  clickable links. Turn it off for the original plain text; expanded previews
  follow the same setting. Formatting does not change your files or copied text.
- Videos play on click and pause when scrolled away or when Chrome hides the tab.
- Media loads near the viewport; the feed adds more cards as you scroll.
- Use **Open file reader** to return to the original viewer and its folder/Dropbox controls.

## Context, chat, and saving knowledge

1. To chat about one note, click **Ask about this** on its card, or **Expand**
   the note and choose **Ask about this note**. The panel opens with only that
   note checked and the cursor in the question box; anything collected earlier
   stays in the list, unchecked, so nothing is lost.
2. To build a wider context, use **Add note** on each card you want, or highlight
   a passage in a card or expanded preview and click **Add selection**. To append
   many notes at once, use **Add all notes in view** in Context & chat: it adds
   every note matching the current filter and search (so search a `#tag` first to
   add just that set), skips notes already collected, and stops before the
   60,000-character budget rather than overflowing it. Saved chats and worksheets
   are left out.
3. **Context & chat** lists the collected passages with their filenames and
   character counts. Edit passages, uncheck any to exclude them, **Only this** to
   isolate one, **Check all** to re-enable every passage, or **Remove** one.
   **Clear context** empties the list while keeping the conversation and topic,
   and **Undo clear** puts the passages back. **New chat**, under *Save in this
   folder*, clears everything instead. **Copy context** copies just the checked
   passages for pasting into ChatGPT, Claude, or another app.
4. Choose **Ollama** or **OpenAI**, enter a question, and click **Ask**. Replies
   stream beside your notes. Enter sends; Shift+Enter adds a line. **Stop answer**
   cancels a request and keeps your question available for retrying.
5. Give the conversation a topic, add your own notes, and use **Save chat**. The
   Markdown file goes directly into the folder you opened and appears in the
   live feed. **Save answer as note** saves one response and its source filenames.
6. Saved conversation cards have **Continue chat**, which restores the selected
   passages, their inclusion settings, conversation, provider, and personal notes.

Saves always create new files. If `Topic-chat.md` exists, the next save becomes
`Topic-chat-2.md`; existing files and symlinks are never replaced by the launcher.
Every question also keeps a snapshot of the source passages it used. The readable
Markdown contains the context and conversation, plus embedded session metadata
for reopening in the viewer. Keep that metadata when editing a saved chat in
another editor. The card's Copy button copies the readable conversation without
the embedded metadata. Conversations are saved only when you click Save; the page warns
before you leave with unsaved changes. Preview limits still apply when collecting
a whole note; reopen saved chats using Continue chat to load the complete file.

Chat uses your existing **Activity Island** server, normally
`http://127.0.0.1:8787`. Its API keys stay there. The Python launcher forwards only
the checked passages and conversation; this mode does not include Claude activity
or Scout's projects folder. Activity Island must be running for chat; Ollama must
also be running when selected. Collection, copying, and saving work without the
chat server. Choosing OpenAI sends the selected context and conversation to
OpenAI. Optional web search uses additional API requests.

### One-time Activity Island update

The context-chat extension lives here in `activity_context.cjs`. Prepare its
reviewable patch for your Activity Island checkout:

```sh
python3 local_feed/prepare_activity_update.py /path/to/claude_hooks_server
git -C /path/to/claude_hooks_server apply --check /absolute/path/to/vercel_textviewer/dist/activity-island-update/context-chat.patch
git -C /path/to/claude_hooks_server apply /absolute/path/to/vercel_textviewer/dist/activity-island-update/context-chat.patch
```

This adds `folder-chat.cjs` and a context branch to `/ask`, leaving the existing
island and Scout chat behavior intact. Restart Activity Island once in the same
terminal/environment that supplies its API key. Then click **Reconnect** in the
viewer. `/providers` advertises `contextChat: 1` when the new code is running;
the viewer reports a clear restart message if an older server is still running.

For another local port, launch the viewer with `--chat-url http://127.0.0.1:PORT`
or set `TEXTVIEWER_CHAT_URL`. Only loopback HTTP addresses are accepted.
The context limit is 60,000 characters including source filenames, with up to
100 conversation messages / 240,000 message characters. Over-limit requests are
rejected with an explanation rather than silently truncated. Saved notes can be
up to 2 MiB. The chat extension's tests mock providers and make no paid requests:

```sh
node --test local_feed/test_activity_context.cjs
```

## Practice worksheets (prototype)

Open **Practice worksheets** in the sidebar, or collect notes with **Add note** /
**Add selection** and choose **Practise these notes** in Context & chat.

- **Try fractions example** opens six problems without an AI connection.
- Enter a topic, choose 1–12 problems and an AI provider, then **Generate practice**.
  Only checked passages are used. **Include current chat** also sends the current
  conversation; uncheck it for passages/topic only. Sources can be Brilliant
  study notes, saved chats, a transcript, or just a topic. No YouTube URL is needed.
- Work on paper, type an answer, and **Check answer**. Equivalent exact values
  work; fractions must be in lowest terms. Mixed numbers and terminating decimals
  are accepted. **Show worked steps** reveals the calculation.
- **Save worksheet** creates a Markdown file in the opened folder containing the
  problems, answer key, source/context snapshot, and attempts. Its card offers
  **Continue practice** to restore progress. Subsequent saves create new versions.
  Closing the practice window keeps the draft while the page remains open;
  leaving/reloading with unsaved changes prompts you to save first.

Generation uses the same Activity Island connection as chat. The Python task at
`POST /api/practice/generate` asks AI to choose integer/fraction operands and
operators, validates the response, and computes every answer and worked solution
with Python's exact `Fraction` arithmetic. The browser checks answers exactly too.
AI-written answer keys are ignored. Generation returns a draft; **Save worksheet**
is the step that writes to disk. Provider/format errors keep the previous draft.

The first version covers addition, subtraction, multiplication, and division of
integers and fractions. It does not yet generate algebra, geometry, or word
problems. This is a bounded request/validate/save task, without a background agent
queue or automatic retries. The example, studying, and saving also work in
browser-only mode; AI generation requires the local launcher. Rebuild and rerun
the Finder Quick Action to upgrade the running server to API version 3.

Worksheet checks (providers are mocked; no paid AI requests):

```sh
python3 -m unittest discover -s local_feed -p 'test_*.py' -v
CI=true npm test -- --watchAll=false --runInBand
node local_feed/browser_practice_check.cjs
```

One file is one card; companion files are not automatically grouped. Markdown
headings, lists, links, code, and tables are rendered safely. Markdown HTML stays
plain text, and embedded image references are shown as labels; images in the
folder have their own cards. Text notes show at most 256 KiB and are interpreted
as UTF-8. Word (`.docx`) documents appear under **Notes**, with extracted text for
search, hashtags, copy, and previews. DOCX previews show at most 256K characters;
Word layout and embedded images are not displayed. The original file is available
from the expanded preview in both launcher and browser-only modes. Damaged or
encrypted documents show a warning without interrupting the other files.

Supported image extensions: JPG, JPEG, PNG, GIF, WebP, AVIF, BMP, SVG. Video:
MP4, WebM, MOV, M4V, OGV. Actual playback depends on Chrome's codec support;
files that cannot display offer **Open original file**. This does not transcode
video. Hidden files/directories, `node_modules`, Python caches, and symlinks are
excluded. A large cloud-backed folder may download notes when their content is
read; media is requested only near the viewport.

The server binds to `127.0.0.1` and uses a random access path. It rejects
cross-origin requests, traversal, and content outside the selected folder.
The access URL is for your local computer; keep it private.

## Hosted / browser-only use

Open the app with `?view=feed`, or click **Feed** in the original reader. In
desktop Chrome, **Open folder** grants read access to the selected directory.
The feed checks directory handles for new files and updates every two seconds;
it needs no local server. Reopening the page requires selecting the folder again.
Files are read in the browser and are not uploaded. The Finder launcher uses
the local server so it can open a folder directly from a command or Quick Action.
Context collection and saving are also available in browser-only mode. Saving
requests write permission for the chosen folder. Activity Island chat requires
the local Finder/Terminal launcher; hosted pages do not connect to localhost.

## Checks

```sh
CI=true npm test -- --watchAll=false --runInBand
python3 -m unittest discover -s local_feed -p 'test_*.py' -v
npm run build:feed
```

The Python tests start temporary localhost servers. Use a Node version compatible
with your installed dependencies if an existing native `canvas` installation
causes the Jest environment to fail to load.
