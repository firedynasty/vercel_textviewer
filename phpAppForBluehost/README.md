# PHP Content Viewer (Bluehost)

A single-file PHP content viewer for Bluehost. Drop files into folders and browse them from any device — no build step, no Dropbox, no processing scripts.

## Setup

1. Upload `index.php` to a directory on your Bluehost server (e.g. `public_html/basketball/`)
2. Create subfolders alongside it for your content (e.g. `coaching_five_motion/`, `skills_shooting/`)
3. Drop your files into those folders via FTP or File Manager
4. Visit the URL in any browser — desktop or mobile

## Structure

```
index.php
coaching_five_motion/
  basketball_the_five_motion01.png
  basketball_the_five_motion02.png
  ...
skills_shooting/
  Screenshot 2026-04-08.png
  notes.txt
draw_plays/
  ...
```

Every folder at the same level as `index.php` becomes a tab in the sidebar automatically.

## Features

- **Landing page** — folder thumbnail grid (first image in folder used as preview)
- **Image gallery** — grid view + "view all stacked" mode per folder
- **Image modal** — click any image to enlarge; left/right arrows or swipe to navigate; Escape to close
- **Sidebar prev/next** — `«` / `»` buttons to step through files with counter
- **Sort** — Name or Modified (newest first)
- **Multi-folder tabs** — any directory alongside `index.php` appears as a tab
- **Font size** — `+` / `−` buttons in the header
- **Page down button** — sticky overlay button on the left edge of content
- **File support** — PNG, JPG, GIF, WEBP, SVG, TXT, MD, HTML, PDF, DOCX (via mammoth.js), RTF
- **Mobile friendly** — hamburger sidebar, swipe gestures on modal

## Local Testing

Requires PHP:

```bash
brew install php
php -S localhost:8000
```
