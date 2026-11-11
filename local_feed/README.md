# Live folder feed

Read `.txt` / `.md` / `.docx` notes, PDF links, images, and videos together in Chrome. New saves,
edits, and deletions are checked every two seconds. The most recently modified
files appear first. While you are reading farther down or playing a video,
changes wait behind **Show updates** so the page stays still.

The viewer stays in this repository. Your content folder is read only: no PHP,
copied `index.php`, generated index, or cleanup command. Python serves the built
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

## Checks

```sh
CI=true npm test -- --watchAll=false --runInBand
python3 -m unittest discover -s local_feed -p 'test_*.py' -v
npm run build:feed
```

The Python tests start temporary localhost servers. Use a Node version compatible
with your installed dependencies if an existing native `canvas` installation
causes the Jest environment to fail to load.
