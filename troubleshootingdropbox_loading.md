# Troubleshooting: Dropbox Image Loading

## Problem

Markdown files loaded from Dropbox that reference images (e.g. `![Screenshot](path/to/image.png)`) were not displaying images in the viewer.

Two separate root causes were identified and fixed.

---

## Root Cause 1 — ISO-8859-1 error with macOS screenshot filenames

### Symptom

```
Failed to read the 'headers' property from 'RequestInit':
String contains non ISO-8859-1 code point.
```

### Cause

macOS screenshot filenames use a **narrow no-break space (U+202F)** between the time and AM/PM, e.g.:

```
Screenshot 2026-05-09 at 5.51.33 PM.png
                              ^^^^^
                              U+202F (not a regular space)
```

When this path was passed as the `Dropbox-API-Arg` HTTP header value, the browser rejected it because HTTP headers must be ISO-8859-1 (max code point U+00FF), and U+202F exceeds that limit.

### Fix — use Dropbox file `id` instead of path

Dropbox returns a unique `id` for every file (e.g. `id:AbCdEfGh123`). This ID is always pure ASCII and safe as an HTTP header value.

**`src/hooks/useDropbox.js`** — both `listFolder` and `listFolderRecursive` now include `id`:

```js
const entries = (data.entries || []).map((entry) => ({
  name: entry.name,
  path: entry.path_lower || entry.path_display,
  id: entry.id || null,       // ← added
  isFolder: entry['.tag'] === 'folder',
}));
```

**`src/utils/fileUtils.js`** — `processDropboxFolder` stores `dropboxId` on each gallery item:

```js
galleryItems.push({
  ...
  dropboxId: entry.id || null,   // ← added
});
```

**`src/TextViewer.js`** — `ensureFileDownloaded` and `handleImageRequest` both prefer the `id` over the path:

```js
const downloadRef = file.dropboxId || file.dropboxPath;
const blob = await dropbox.downloadFile(downloadRef);
```

---

## Root Cause 2 — Image lookup failed for local Drop Folder

### Symptom

Images dropped via the **Drop Folder** button rendered as broken in markdown even though the files were present in the loaded set.

### Cause

`imagePathToBlobUrl` stored relative paths (e.g. `images/photo.jpg`) but markdown referenced absolute local macOS paths (e.g. `/Users/stanleytan/.../images/photo.jpg`). The lookup by path never matched.

### Fix — store filename-only keys

**`src/utils/fileUtils.js`** — `processFiles` now also stores the filename alone:

```js
imagePathToBlobUrl[file.name] = blobUrl;
imagePathToBlobUrl[file.name.replace(/ /g, '%20')] = blobUrl;
```

This means the lookup in `handleImageRequest` can fall back to matching just the base filename, regardless of the absolute path in the markdown reference.

---

## Root Cause 3 — Race condition / inline rendering complexity

### Symptom

Even after path fixes, images sometimes appeared blank on first load.

### Fix — modal on-demand loading

Inline image rendering was replaced with a **click-to-view modal**:

- Markdown `![alt](path)` is preprocessed into a `<button class="md-image-btn">🖼 filename</button>`
- Clicking the button triggers `handleImageRequest` (3-tier lookup: blob cache → files list → `listFolder` fallback)
- A modal overlay displays the fetched image
- **Escape key closes the modal** (window-level `keydown` listener attached only while the modal is open)

This avoids all race conditions since the download only happens when the user explicitly requests the image.

---

## Files Changed

| File | Change |
|------|--------|
| `src/hooks/useDropbox.js` | Include `id` field in `listFolder` / `listFolderRecursive` entries |
| `src/utils/fileUtils.js` | Store `dropboxId` on gallery items; store filename-only blob URL keys |
| `src/TextViewer.js` | Use `dropboxId` for downloads; `handleImageRequest` 3-tier lookup |
| `src/components/ContentViewer.js` | Preprocess markdown images → buttons; image modal UI; Escape-to-close |
| `src/App.css` | Styles for `.md-image-btn` and image modal overlay |
