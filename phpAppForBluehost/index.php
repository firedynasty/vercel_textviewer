<?php
$contentDir = '.';

$currentFile   = isset($_GET['file'])   ? $_GET['file']   : null;
$currentFolder = isset($_GET['folder']) ? $_GET['folder'] : null;
$sortBy        = isset($_GET['sort'])   ? $_GET['sort']   : 'name';

// --- Helpers ---

function scanContent($dir, $sortBy) {
    $items = [];
    if (!is_dir($dir)) return $items;
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            $items[] = ['type'=>'folder','name'=>$entry,'path'=>$entry,'mtime'=>filemtime($path)];
        } else {
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            $items[] = ['type'=>'file','name'=>$entry,'path'=>$entry,'ext'=>$ext,'mtime'=>filemtime($path)];
        }
    }
    usort($items, function($a, $b) use ($sortBy) {
        if ($a['type'] !== $b['type']) return $a['type'] === 'folder' ? -1 : 1;
        if ($sortBy === 'modified') return $b['mtime'] - $a['mtime'];
        return strnatcasecmp($a['name'], $b['name']);
    });
    return $items;
}

function scanSubfolder($dir, $folder, $sortBy) {
    $path = $dir . '/' . $folder;
    $files = [];
    if (!is_dir($path)) return $files;
    foreach (scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $fullPath = $path . '/' . $entry;
        if (is_file($fullPath)) {
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            $files[] = ['name'=>$entry,'path'=>$folder.'/'.$entry,'ext'=>$ext,'mtime'=>filemtime($fullPath)];
        }
    }
    usort($files, function($a, $b) use ($sortBy) {
        if ($sortBy === 'modified') return $b['mtime'] - $a['mtime'];
        return strnatcasecmp($a['name'], $b['name']);
    });
    return $files;
}

// Get first image found in a folder (for thumbnail)
function getFolderThumb($dir, $folder) {
    $path = $dir . '/' . $folder;
    if (!is_dir($path)) return null;
    $imageExts = ['png','jpg','jpeg','gif','webp','bmp'];
    $entries = scandir($path);
    sort($entries);
    foreach ($entries as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, $imageExts)) {
            return $path . '/' . $f;
        }
    }
    return null;
}

// Build URLs preserving sort
function sortUrl($sort) {
    $params = $_GET;
    $params['sort'] = $sort;
    return '?' . http_build_query($params);
}

function itemUrl($params) {
    global $sortBy;
    $params['sort'] = $sortBy;
    return '?' . http_build_query($params);
}

// --- Data ---
$items = scanContent($contentDir, $sortBy);

$folderFiles = [];
if ($currentFolder) {
    $folderFiles = scanSubfolder($contentDir, $currentFolder, $sortBy);
}

// Determine display type
$displayContent = null;
$displayType    = null;
if ($currentFile) {
    $filePath = $contentDir . '/' . $currentFile;
    if (file_exists($filePath)) {
        $ext = strtolower(pathinfo($currentFile, PATHINFO_EXTENSION));
        $imageExts = ['png','jpg','jpeg','gif','webp','bmp','svg'];
        $textExts  = ['txt','csv','json','log'];
        $videoExts = ['mp4','webm','ogg','mov','avi','mkv','m4v'];
        $audioExts = ['mp3','m4a'];
        if (in_array($ext, $imageExts))             { $displayType = 'image'; $displayContent = $filePath; }
        elseif (in_array($ext, $audioExts))         { $displayType = 'audio'; $displayContent = $filePath; }
        elseif (in_array($ext, $videoExts))         { $displayType = 'video'; $displayContent = $filePath; }
        elseif ($ext === 'html' || $ext === 'htm')  { $displayType = 'html';  $displayContent = $filePath; }
        elseif ($ext === 'pdf')                     { $displayType = 'pdf';   $displayContent = $filePath; }
        elseif ($ext === 'docx')                    { $displayType = 'docx';  $displayContent = $filePath; }
        elseif ($ext === 'rtf')                     { $displayType = 'rtf';   $displayContent = $filePath; }
        elseif ($ext === 'md')                        { $displayType = 'markdown'; $displayContent = file_get_contents($filePath); }
        elseif (in_array($ext, $textExts))          { $displayType = 'text';  $displayContent = file_get_contents($filePath); }
    }
}

// Prev / next
$prevFile = null;
$nextFile = null;
$fileList = $currentFolder
    ? $folderFiles
    : array_values(array_filter($items, function($i) { return $i['type'] === 'file'; }));

if ($currentFile && count($fileList) > 1) {
    foreach ($fileList as $idx => $f) {
        if ($f['path'] === $currentFile) {
            if ($idx > 0)                    $prevFile = $fileList[$idx - 1];
            if ($idx < count($fileList) - 1) $nextFile = $fileList[$idx + 1];
            break;
        }
    }
}

// Image list for modal (URL-encoded src)
$imageList    = [];
$imageExtsAll = ['png','jpg','jpeg','gif','webp','bmp','svg'];
foreach ($fileList as $f) {
    $fext = $f['ext'] ?? '';
    if (in_array($fext, $imageExtsAll)) {
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $f['path'])));
        $imageList[] = [
            'name' => $f['name'],
            'src'  => $contentDir . '/' . $encodedPath,
            'url'  => $currentFolder
                ? itemUrl(['folder'=>$currentFolder,'file'=>$f['path']])
                : itemUrl(['file'=>$f['path']])
        ];
    }
}

// Audio list for audio modal
$audioList = [];
$audioExtsAll = ['mp3','m4a'];
$audioMimeMap = ['mp3'=>'audio/mpeg','m4a'=>'audio/mp4'];
foreach ($fileList as $f) {
    $fext = $f['ext'] ?? '';
    if (in_array($fext, $audioExtsAll)) {
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $f['path'])));
        $audioList[] = [
            'name' => $f['name'],
            'src'  => $contentDir . '/' . $encodedPath,
            'mime' => isset($audioMimeMap[$fext]) ? $audioMimeMap[$fext] : 'audio/mpeg',
            'url'  => $currentFolder
                ? itemUrl(['folder'=>$currentFolder,'file'=>$f['path']])
                : itemUrl(['file'=>$f['path']])
        ];
    }
}

// Current audio index (for auto-opening modal)
$currentAudioIdx = -1;
if ($displayType === 'audio' && !empty($audioList)) {
    $encodedCurrentAudio = $contentDir . '/' . implode('/', array_map('rawurlencode', explode('/', $currentFile)));
    foreach ($audioList as $ai => $aEntry) {
        if ($aEntry['src'] === $encodedCurrentAudio) { $currentAudioIdx = $ai; break; }
    }
}

// Folder thumbnails for landing page
$folderCards = [];
foreach ($items as $item) {
    if ($item['type'] === 'folder') {
        $thumb = getFolderThumb($contentDir, $item['path']);
        $encodedThumb = $thumb
            ? $contentDir . '/' . implode('/', array_map('rawurlencode', explode('/', $item['path'] . '/' . basename($thumb))))
            : null;
        $folderCards[] = ['name'=>$item['name'],'path'=>$item['path'],'thumb'=>$encodedThumb];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Content Viewer</title>
<script src="https://cdn.jsdelivr.net/npm/mammoth@1.6.0/mammoth.browser.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<link id="hljs-light" rel="stylesheet" href="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.11.1/build/styles/github.min.css">
<link id="hljs-dark" rel="stylesheet" href="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.11.1/build/styles/github-dark.min.css" disabled>
<script src="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.11.1/build/highlight.min.js"></script>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    display: flex;
    height: 100vh;
    overflow: hidden;
}

/* Sidebar */
.sidebar {
    width: 220px;
    background: #1a1a2e;
    color: #eee;
    height: 100vh;
    overflow-y: auto;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
}
.sidebar-header {
    padding: 12px 15px;
    font-size: 14px;
    font-weight: bold;
    border-bottom: 1px solid #333;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.sidebar-header a {
    color: #7ec8e3;
    text-decoration: none;
    font-size: 12px;
}

/* Sort bar */
.sort-bar {
    display: flex;
    border-bottom: 1px solid #333;
}
.sort-bar a {
    flex: 1;
    text-align: center;
    padding: 6px 0;
    font-size: 11px;
    text-decoration: none;
    color: #888;
}
.sort-bar a:hover { background: #16213e; color: #ccc; }
.sort-bar a.active { color: #7ec8e3; background: #16213e; }

/* Sidebar prev/next nav */
.sidebar-nav {
    display: flex;
    border-bottom: 1px solid #333;
}
.sidebar-nav-btn {
    flex: 1;
    background: none;
    border: none;
    color: #7ec8e3;
    font-size: 16px;
    padding: 8px 0;
    cursor: pointer;
    text-decoration: none;
    text-align: center;
}
.sidebar-nav-btn:hover { background: #16213e; }
.sidebar-nav-btn:disabled { color: #444; cursor: default; }
.sidebar-nav-btn:disabled:hover { background: none; }
.sidebar-nav-label {
    flex: 2;
    text-align: center;
    font-size: 11px;
    color: #888;
    align-self: center;
}

/* Sidebar list */
.sidebar-list { flex: 1; overflow-y: auto; padding: 5px 0; }
.sidebar-item {
    display: block;
    padding: 8px 15px;
    color: #ccc;
    text-decoration: none;
    font-size: 13px;
    border-left: 3px solid transparent;
    word-break: break-word;
}
.sidebar-item:hover { background: #16213e; color: #fff; }
.sidebar-item.active { background: #16213e; border-left-color: #7ec8e3; color: #7ec8e3; }
.sidebar-item.folder { font-weight: bold; color: #e8c547; }
.sidebar-item.folder::before { content: "\1F4C1 "; }
.sidebar-item.back { color: #7ec8e3; font-style: italic; }
.sidebar-item.back::before { content: "\2190 "; }
.file-ext { font-size: 10px; color: #888; margin-left: 4px; }
.file-date { font-size: 10px; color: #666; display: block; margin-top: 2px; }

/* Mobile toggle */
.sidebar-toggle {
    display: none;
    position: fixed;
    top: 10px; left: 10px;
    z-index: 1000;
    background: #1a1a2e;
    color: #fff;
    border: none;
    padding: 8px 12px;
    font-size: 18px;
    border-radius: 4px;
    cursor: pointer;
}

/* Main */
.main {
    flex: 1;
    overflow-y: auto;
    background: #f0f0f0;
    display: flex;
    flex-direction: column;
}
.main-header {
    padding: 10px 15px;
    background: #fff;
    border-bottom: 1px solid #ddd;
    font-size: 13px;
    color: #666;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}
.main-header .download-link { font-size: 12px; color: #7ec8e3; text-decoration: none; }
.content-area { flex: 1; padding: 20px; overflow-y: auto; }

/* Content types */
.content-area img { max-width: 100%; height: auto; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.text-content {
    background: #fff; padding: 20px; border-radius: 4px;
    white-space: pre-wrap; font-family: 'Courier New', monospace;
    font-size: 14px; line-height: 1.6; box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.markdown-content {
    background: #fff; padding: 20px 30px; border-radius: 4px;
    font-size: 15px; line-height: 1.7; box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    max-width: 900px; color: #222;
}
.markdown-content h1, .markdown-content h2, .markdown-content h3,
.markdown-content h4, .markdown-content h5, .markdown-content h6 {
    margin-top: 1.4em; margin-bottom: 0.6em; line-height: 1.3;
}
.markdown-content h1 { font-size: 1.8em; border-bottom: 1px solid #ddd; padding-bottom: 0.3em; }
.markdown-content h2 { font-size: 1.5em; border-bottom: 1px solid #eee; padding-bottom: 0.2em; }
.markdown-content h3 { font-size: 1.25em; }
.markdown-content p { margin: 0.8em 0; }
.markdown-content ul, .markdown-content ol { margin: 0.8em 0; padding-left: 2em; }
.markdown-content li { margin: 0.3em 0; }
.markdown-content blockquote {
    border-left: 4px solid #7ec8e3; margin: 1em 0; padding: 0.5em 1em;
    background: #f8f9fa; color: #555;
}
.markdown-content code {
    background: #f0f0f0; padding: 2px 6px; border-radius: 3px;
    font-family: 'Courier New', monospace; font-size: 0.9em;
}
.markdown-content pre {
    margin: 1em 0; border-radius: 6px; overflow-x: auto;
}
.markdown-content pre code {
    background: none; padding: 0; display: block;
}
.markdown-content table {
    border-collapse: collapse; margin: 1em 0; width: 100%;
}
.markdown-content th, .markdown-content td {
    border: 1px solid #ddd; padding: 8px 12px; text-align: left;
}
.markdown-content th { background: #f5f5f5; font-weight: 600; }
.markdown-content img { max-width: 100%; height: auto; border-radius: 4px; }
.markdown-content a { color: #7ec8e3; }
.markdown-content hr { border: none; border-top: 1px solid #ddd; margin: 1.5em 0; }
.docx-content {
    background: #fff; padding: 30px; border-radius: 4px;
    font-size: 15px; line-height: 1.7; box-shadow: 0 2px 8px rgba(0,0,0,0.1); max-width: 800px;
}
.docx-content img { max-width: 100%; }
.content-area iframe { width: 100%; height: 80vh; border: none; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.content-area video { max-width: 100%; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }

/* Folder thumbnail grid (landing page) */
.folder-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 16px;
    padding: 4px;
}
.folder-card {
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    text-decoration: none;
    color: #333;
    transition: transform 0.15s, box-shadow 0.15s;
    display: flex;
    flex-direction: column;
}
.folder-card:hover { transform: translateY(-3px); box-shadow: 0 6px 16px rgba(0,0,0,0.15); }
.folder-card .thumb {
    width: 100%;
    aspect-ratio: 4/3;
    object-fit: cover;
    background: #e8e8e8;
    display: block;
}
.folder-card .no-thumb {
    width: 100%;
    aspect-ratio: 4/3;
    background: #1a1a2e;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
}
.folder-card .label {
    padding: 10px 12px;
    font-size: 13px;
    font-weight: 500;
    word-break: break-word;
    border-top: 1px solid #eee;
}

/* Gallery grid (inside folder) */
.gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 15px; }
.gallery-item { text-align: center; }
.gallery-item img { width: 100%; cursor: pointer; border-radius: 4px; transition: transform 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.gallery-item img:hover { transform: scale(1.02); }
.gallery-item .caption { font-size: 12px; color: #666; margin-top: 5px; }

/* Stacked images */
.stacked-images { display: flex; flex-direction: column; gap: 10px; align-items: center; }
.stacked-images img { max-width: 100%; }

/* Image modal */
.image-modal {
    display: none;
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.92);
    z-index: 2000;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}
.image-modal.open { display: flex; }
.modal-img { max-width: 90vw; max-height: 80vh; object-fit: contain; border-radius: 4px; user-select: none; }
.modal-close {
    position: absolute; top: 15px; right: 20px;
    background: none; border: none; color: #fff; font-size: 28px; cursor: pointer; z-index: 2001;
}
.modal-arrow {
    position: absolute; top: 50%; transform: translateY(-50%);
    background: rgba(255,255,255,0.15); border: none; color: #fff;
    font-size: 32px; padding: 15px 18px; cursor: pointer; border-radius: 4px; z-index: 2001;
}
.modal-arrow:hover { background: rgba(255,255,255,0.3); }
.modal-arrow.left { left: 15px; }
.modal-arrow.right { right: 15px; }
.modal-caption { color: #ccc; font-size: 13px; margin-top: 12px; }
.modal-counter { color: #888; font-size: 11px; margin-top: 4px; }

/* Audio modal */
.audio-modal {
    display: none;
    position: fixed; bottom: 0; left: 220px; right: 0;
    background: #1a1a2e;
    z-index: 1500;
    flex-direction: column;
    align-items: center;
    padding: 20px 20px 25px;
    box-shadow: 0 -4px 20px rgba(0,0,0,0.4);
    border-top: 2px solid #7ec8e3;
}
.audio-modal.open { display: flex; }
.audio-modal-header {
    display: flex;
    width: 100%;
    max-width: 600px;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}
.audio-modal-title {
    color: #eee;
    font-size: 14px;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
    margin-right: 10px;
}
.audio-modal-close {
    background: none; border: none; color: #888; font-size: 22px;
    cursor: pointer; padding: 0 4px;
}
.audio-modal-close:hover { color: #fff; }
.audio-modal-controls {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    max-width: 600px;
}
.audio-modal-controls audio {
    flex: 1;
    height: 40px;
}
.audio-nav-btn {
    background: rgba(255,255,255,0.1); border: none; color: #7ec8e3;
    font-size: 20px; width: 36px; height: 36px; border-radius: 50%;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.audio-nav-btn:hover { background: rgba(255,255,255,0.2); }
.audio-nav-btn:disabled { color: #444; cursor: default; }
.audio-nav-btn:disabled:hover { background: rgba(255,255,255,0.1); }
.audio-toggle-btn {
    width: 32px; height: 32px; font-size: 16px; font-weight: 700;
    border: none; border-radius: 8px; cursor: pointer;
    background: rgb(224,224,224); color: rgb(51,51,51);
    display: flex; align-items: center; justify-content: center;
}
.audio-toggle-btn.playing { background: #7ec8e3; color: #1a1a2e; }

/* Dark mode */
body.dark .main { background: #1e1e1e; }
body.dark .main-header { background: #2a2a2a; border-bottom-color: #444; color: #ccc; }
body.dark .content-area { color: #ddd; }
body.dark .text-content { background: #2a2a2a; color: #ddd; box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
body.dark .markdown-content { background: #2a2a2a; color: #ddd; box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
body.dark .markdown-content h1 { border-bottom-color: #444; }
body.dark .markdown-content h2 { border-bottom-color: #444; }
body.dark .markdown-content code { background: #383838; }
body.dark .markdown-content blockquote { background: #333; color: #aaa; border-left-color: #7ec8e3; }
body.dark .markdown-content th { background: #333; }
body.dark .markdown-content th, body.dark .markdown-content td { border-color: #555; }
body.dark .markdown-content a { color: #7ec8e3; }
body.dark .markdown-content hr { border-top-color: #555; }
body.dark .docx-content { background: #2a2a2a; color: #ddd; box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
body.dark .folder-card { background: #2a2a2a; color: #ddd; }
body.dark .folder-card .label { border-top-color: #444; }
body.dark .gallery-item .caption { color: #aaa; }
body.dark .content-area img { box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
body.dark #darkModeBtn { background: #555; color: #ffdd57; }

/* Mobile */
@media (max-width: 768px) {
    .sidebar { position: fixed; left: -250px; z-index: 999; width: 250px; transition: left 0.3s; }
    .sidebar.open { left: 0; }
    .sidebar-toggle { display: block; }
    .main { width: 100%; }
    .folder-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }
    .gallery { grid-template-columns: 1fr 1fr; }
    .content-area { padding: 10px; padding-top: 50px; }
    .modal-arrow { padding: 10px 14px; font-size: 24px; }
    .modal-arrow.left { left: 5px; }
    .modal-arrow.right { right: 5px; }
    .audio-modal { left: 0; }
}
</style>
</head>
<body>

<button class="sidebar-toggle" onclick="toggleSidebar()">&#9776;</button>

<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <?php if ($currentFolder): ?>
            <span><?= htmlspecialchars($currentFolder) ?></span>
            <a href="<?= itemUrl([]) ?>">Back</a>
        <?php else: ?>
            <span>Basketball</span>
            <span style="font-size:11px;color:#888"><?= count($items) ?> items</span>
        <?php endif; ?>
    </div>


    <div class="sort-bar">
        <a href="<?= sortUrl('name') ?>" class="<?= $sortBy === 'name' ? 'active' : '' ?>">Name</a>
        <a href="<?= sortUrl('modified') ?>" class="<?= $sortBy === 'modified' ? 'active' : '' ?>">Modified</a>
    </div>

    <?php if ($currentFile): ?>
    <div class="sidebar-nav">
        <?php if ($prevFile): ?>
            <a class="sidebar-nav-btn" href="<?= $currentFolder ? itemUrl(['folder'=>$currentFolder,'file'=>$prevFile['path']]) : itemUrl(['file'=>$prevFile['path']]) ?>">&laquo;</a>
        <?php else: ?>
            <button class="sidebar-nav-btn" disabled>&laquo;</button>
        <?php endif; ?>
        <span class="sidebar-nav-label">
            <?php
            $curIdx = 0;
            foreach ($fileList as $i => $f) { if ($f['path'] === $currentFile) { $curIdx = $i + 1; break; } }
            echo $curIdx . ' / ' . count($fileList);
            ?>
        </span>
        <?php if ($nextFile): ?>
            <a class="sidebar-nav-btn" href="<?= $currentFolder ? itemUrl(['folder'=>$currentFolder,'file'=>$nextFile['path']]) : itemUrl(['file'=>$nextFile['path']]) ?>">&raquo;</a>
        <?php else: ?>
            <button class="sidebar-nav-btn" disabled>&raquo;</button>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="sidebar-list">
        <?php if ($currentFolder): ?>
            <a class="sidebar-item back" href="<?= itemUrl([]) ?>">All folders</a>
            <a class="sidebar-item <?= (!$currentFile && isset($_GET['view']) ? 'active' : '') ?>"
               href="<?= itemUrl(['folder'=>$currentFolder,'view'=>'all']) ?>">
                View all images
            </a>
            <?php foreach ($folderFiles as $f): ?>
                <a class="sidebar-item <?= ($currentFile === $f['path'] ? 'active' : '') ?>"
                   href="<?= itemUrl(['folder'=>$currentFolder,'file'=>$f['path']]) ?>">
                    <?= htmlspecialchars($f['name']) ?>
                    <span class="file-ext"><?= $f['ext'] ?></span>
                    <?php if ($sortBy === 'modified'): ?>
                        <span class="file-date"><?= date('M j, Y g:ia', $f['mtime']) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <?php foreach ($items as $item): ?>
                <?php if ($item['type'] === 'folder'): ?>
                    <a class="sidebar-item folder" href="<?= itemUrl(['folder'=>$item['path']]) ?>">
                        <?= htmlspecialchars($item['name']) ?>
                        <?php if ($sortBy === 'modified'): ?>
                            <span class="file-date"><?= date('M j, Y g:ia', $item['mtime']) ?></span>
                        <?php endif; ?>
                    </a>
                <?php else: ?>
                    <a class="sidebar-item <?= ($currentFile === $item['path'] ? 'active' : '') ?>"
                       href="<?= itemUrl(['file'=>$item['path']]) ?>">
                        <?= htmlspecialchars($item['name']) ?>
                        <span class="file-ext"><?= $item['ext'] ?? '' ?></span>
                        <?php if ($sortBy === 'modified'): ?>
                            <span class="file-date"><?= date('M j, Y g:ia', $item['mtime']) ?></span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</nav>

<div class="main">
    <?php if ($currentFile || $currentFolder): ?>
    <div class="main-header">
        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;margin-right:10px"><?= htmlspecialchars($currentFile ?? $currentFolder) ?></span>
        <div style="display:flex;align-items:center;gap:6px;flex-shrink:0">
            <button title="Decrease font size" onclick="adjustFontSize(-1)" style="width:32px;height:32px;font-size:18px;font-weight:700;border:none;border-radius:8px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51)">-</button>
            <button title="Increase font size" onclick="adjustFontSize(1)" style="width:32px;height:32px;font-size:18px;font-weight:700;border:none;border-radius:8px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51)">+</button>
            <button id="darkModeBtn" title="Toggle dark/light mode" onclick="toggleDarkMode()" style="width:32px;height:32px;font-size:16px;font-weight:700;border:none;border-radius:8px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51)">&#9789;</button>
            <?php if (!empty($audioList)): ?>
                <button class="audio-toggle-btn" id="audioToggleBtn" title="Toggle audio player" onclick="toggleAudioModal()">&#9835;</button>
            <?php endif; ?>
            <?php if ($currentFile): ?>
                <a class="download-link" href="<?= $contentDir . '/' . htmlspecialchars($currentFile) ?>" download>Download</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="content-area" id="contentArea">
        <button title="Page down" onclick="contentPageDown()" style="position:sticky;top:50%;left:6px;z-index:10;width:48px;height:48px;background:rgba(0,0,0,0.08);border-radius:50%;border:1.5px solid rgb(0,0,0);opacity:0.15;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:0.2s;margin-bottom:-48px;float:left;"><svg width="48" height="48" viewBox="0 0 64 64"><path d="M8 20 L32 44 L56 20" stroke="rgba(0,0,0,0.7)" stroke-width="8" fill="none" stroke-linecap="round" stroke-linejoin="round"></path></svg></button>

        <?php if ($currentFolder && !$currentFile && isset($_GET['view']) && $_GET['view'] === 'all'): ?>
            <!-- Stacked view -->
            <div class="stacked-images">
                <?php
                $imageExts = ['png','jpg','jpeg','gif','webp','bmp','svg'];
                $sIdx = 0;
                foreach ($folderFiles as $f):
                    if (in_array($f['ext'], $imageExts)):
                        $enc = implode('/', array_map('rawurlencode', explode('/', $f['path'])));
                ?>
                    <img src="<?= $contentDir . '/' . htmlspecialchars($enc) ?>"
                         alt="<?= htmlspecialchars($f['name']) ?>"
                         style="cursor:pointer" onclick="openModalAt(<?= $sIdx ?>)" title="Click to enlarge">
                <?php $sIdx++; endif; endforeach; ?>
            </div>

        <?php elseif ($currentFolder && !$currentFile): ?>
            <!-- Gallery grid inside folder -->
            <div class="gallery">
                <?php
                $imageExts = ['png','jpg','jpeg','gif','webp','bmp','svg'];
                $imgIdx = 0;
                foreach ($folderFiles as $f):
                    if (in_array($f['ext'], $imageExts)):
                        $enc = implode('/', array_map('rawurlencode', explode('/', $f['path'])));
                ?>
                    <div class="gallery-item">
                        <img src="<?= $contentDir . '/' . htmlspecialchars($enc) ?>"
                             alt="<?= htmlspecialchars($f['name']) ?>"
                             onclick="openModalAt(<?= $imgIdx ?>)" title="Click to enlarge">
                        <div class="caption"><?= htmlspecialchars($f['name']) ?></div>
                    </div>
                <?php $imgIdx++;
                    elseif (in_array($f['ext'], ['mp4','webm','ogg','mov','avi','mkv','m4v'])):
                        $encVid = implode('/', array_map('rawurlencode', explode('/', $f['path'])));
                ?>
                    <div class="gallery-item">
                        <a href="<?= itemUrl(['folder'=>$currentFolder,'file'=>$f['path']]) ?>">
                            <video style="width:100%;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,0.1)" muted preload="metadata">
                                <source src="<?= $contentDir . '/' . htmlspecialchars($encVid) ?>" type="video/mp4">
                            </video>
                        </a>
                        <div class="caption"><?= htmlspecialchars($f['name']) ?></div>
                    </div>
                <?php
                    elseif (in_array($f['ext'], ['mp3','m4a'])):
                ?>
                    <div class="gallery-item">
                        <a href="<?= itemUrl(['folder'=>$currentFolder,'file'=>$f['path']]) ?>"
                           style="display:block;padding:20px;background:#1a1a2e;border-radius:4px;text-decoration:none;color:#eee;box-shadow:0 2px 8px rgba(0,0,0,0.08);text-align:center">
                            <div style="font-size:28px;margin-bottom:8px">&#9835;</div>
                            <?= htmlspecialchars($f['name']) ?>
                        </a>
                        <div class="caption"><?= htmlspecialchars($f['name']) ?></div>
                    </div>
                <?php
                    elseif (in_array($f['ext'], ['txt','md','html','htm','docx','rtf','pdf'])):
                ?>
                    <div class="gallery-item">
                        <a href="<?= itemUrl(['folder'=>$currentFolder,'file'=>$f['path']]) ?>"
                           style="display:block;padding:20px;background:#fff;border-radius:4px;text-decoration:none;color:#333;box-shadow:0 2px 8px rgba(0,0,0,0.08)">
                            <?= htmlspecialchars($f['name']) ?>
                        </a>
                    </div>
                <?php endif; endforeach; ?>
            </div>

        <?php elseif ($displayType === 'image'):
            $encodedCurrentPath = $contentDir . '/' . implode('/', array_map('rawurlencode', explode('/', $currentFile)));
            $currentImgIdx = 0;
            foreach ($imageList as $ii => $imgEntry) {
                if ($imgEntry['src'] === $encodedCurrentPath) { $currentImgIdx = $ii; break; }
            }
        ?>
            <img src="<?= htmlspecialchars($encodedCurrentPath) ?>"
                 alt="<?= htmlspecialchars($currentFile) ?>"
                 style="cursor:pointer" onclick="openModalAt(<?= $currentImgIdx ?>)" title="Click to enlarge">

        <?php elseif ($displayType === 'video'):
            $encodedVideoPath = $contentDir . '/' . implode('/', array_map('rawurlencode', explode('/', $currentFile)));
            $videoMime = [
                'mp4'=>'video/mp4','webm'=>'video/webm','ogg'=>'video/ogg',
                'mov'=>'video/mp4','m4v'=>'video/mp4','avi'=>'video/x-msvideo','mkv'=>'video/x-matroska'
            ];
            $ext = strtolower(pathinfo($currentFile, PATHINFO_EXTENSION));
            $mime = isset($videoMime[$ext]) ? $videoMime[$ext] : 'video/mp4';
        ?>
            <video controls autoplay style="max-height:80vh">
                <source src="<?= htmlspecialchars($encodedVideoPath) ?>" type="<?= $mime ?>">
                Your browser does not support this video format.
                <a href="<?= htmlspecialchars($encodedVideoPath) ?>" download>Download video</a>
            </video>

        <?php elseif ($displayType === 'markdown'): ?>
            <div class="markdown-content" id="markdown-render"></div>
            <script>
            (function() {
                var raw = <?= json_encode($displayContent, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
                marked.setOptions({ breaks: true, gfm: true });
                document.getElementById('markdown-render').innerHTML = marked.parse(raw);
                document.querySelectorAll('#markdown-render pre code').forEach(function(block) {
                    hljs.highlightElement(block);
                });
            })();
            </script>

        <?php elseif ($displayType === 'text'): ?>
            <div class="text-content"><?= htmlspecialchars($displayContent) ?></div>

        <?php elseif ($displayType === 'html'): ?>
            <iframe src="<?= htmlspecialchars($displayContent) ?>"></iframe>

        <?php elseif ($displayType === 'pdf'): ?>
            <iframe src="<?= htmlspecialchars($displayContent) ?>" style="height:90vh"></iframe>

        <?php elseif ($displayType === 'docx'): ?>
            <div class="docx-content" id="docx-render">Loading document...</div>
            <script>
            (function() {
                fetch('<?= htmlspecialchars($displayContent) ?>')
                    .then(function(r) { return r.arrayBuffer(); })
                    .then(function(buf) { return mammoth.convertToHtml({ arrayBuffer: buf }); })
                    .then(function(result) { document.getElementById('docx-render').innerHTML = result.value; })
                    .catch(function(err) {
                        document.getElementById('docx-render').innerHTML =
                            '<p style="color:red">Could not render DOCX: ' + err.message + '</p>' +
                            '<p><a href="<?= $contentDir . '/' . htmlspecialchars($currentFile) ?>" download>Download instead</a></p>';
                    });
            })();
            </script>

        <?php elseif ($displayType === 'rtf'): ?>
            <div class="text-content"><?= htmlspecialchars(file_get_contents($displayContent)) ?></div>
            <p style="margin-top:10px">
                <a href="<?= $contentDir . '/' . htmlspecialchars($currentFile) ?>" download
                   style="font-size:13px;color:#7ec8e3">Download original RTF</a>
            </p>

        <?php else: ?>
            <!-- Landing page: folder thumbnail grid -->
            <?php if (!empty($folderCards)): ?>
                <div class="folder-grid">
                    <?php foreach ($folderCards as $card): ?>
                        <a class="folder-card" href="<?= itemUrl(['folder'=>$card['path']]) ?>">
                            <?php if ($card['thumb']): ?>
                                <img class="thumb" src="<?= htmlspecialchars($card['thumb']) ?>" alt="">
                            <?php else: ?>
                                <div class="no-thumb">&#128193;</div>
                            <?php endif; ?>
                            <div class="label"><?= htmlspecialchars($card['name']) ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="color:#999;text-align:center;padding:60px 20px">
                    <p style="font-size:18px;margin-bottom:8px">No folders yet</p>
                    <p style="font-size:13px">Add subfolders to <code><?= htmlspecialchars($contentDir) ?>/</code> and they'll appear here.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</div>

<!-- Image Modal -->
<div class="image-modal" id="imageModal">
    <button class="modal-close" onclick="closeModal()">&times;</button>
    <button class="modal-arrow left" onclick="modalNav(-1)">&#8249;</button>
    <img class="modal-img" id="modalImg" src="" alt="">
    <button class="modal-arrow right" onclick="modalNav(1)">&#8250;</button>
    <div class="modal-caption" id="modalCaption"></div>
    <div class="modal-counter" id="modalCounter"></div>
</div>

<!-- Audio Modal -->
<div class="audio-modal" id="audioModal">
    <div class="audio-modal-header">
        <div class="audio-modal-title" id="audioTitle">No audio</div>
        <button class="audio-modal-close" onclick="toggleAudioModal()">&times;</button>
    </div>
    <div class="audio-modal-controls">
        <button class="audio-nav-btn" id="audioPrevBtn" onclick="audioNav(-1)">&#8249;</button>
        <audio controls id="audioPlayer"></audio>
        <button class="audio-nav-btn" id="audioNextBtn" onclick="audioNav(1)">&#8250;</button>
    </div>
</div>

<script>
var imageList = <?= json_encode($imageList, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?: '[]' ?>;
var modalIndex = 0;
var modal = document.getElementById('imageModal');
var modalImg = document.getElementById('modalImg');
var modalCaption = document.getElementById('modalCaption');
var modalCounter = document.getElementById('modalCounter');

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
}

function contentPageDown() {
    var el = document.getElementById('contentArea');
    el.scrollBy({ top: el.clientHeight * 0.9, behavior: 'smooth' });
}

var fontSize = 14;
function adjustFontSize(dir) {
    fontSize = Math.min(32, Math.max(8, fontSize + dir * 2));
    document.getElementById('contentArea').style.fontSize = fontSize + 'px';
}

function setHljsTheme(isDark) {
    document.getElementById('hljs-light').disabled = isDark;
    document.getElementById('hljs-dark').disabled = !isDark;
}
function toggleDarkMode() {
    var isDark = document.body.classList.toggle('dark');
    var btn = document.getElementById('darkModeBtn');
    btn.innerHTML = isDark ? '&#9788;' : '&#9789;';
    setHljsTheme(isDark);
    try { localStorage.setItem('darkMode', isDark ? '1' : '0'); } catch(e) {}
}
(function() {
    try {
        if (localStorage.getItem('darkMode') === '1') {
            document.body.classList.add('dark');
            var btn = document.getElementById('darkModeBtn');
            if (btn) btn.innerHTML = '&#9788;';
            setHljsTheme(true);
        }
    } catch(e) {}
})();

function openModalAt(idx) {
    if (!imageList.length) return;
    modalIndex = (typeof idx === 'number') ? idx : 0;
    showModalImage();
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    modal.classList.remove('open');
    document.body.style.overflow = '';
}

function modalNav(dir) {
    modalIndex += dir;
    if (modalIndex < 0) modalIndex = imageList.length - 1;
    if (modalIndex >= imageList.length) modalIndex = 0;
    showModalImage();
}

function showModalImage() {
    if (!imageList.length) return;
    var img = imageList[modalIndex];
    modalImg.src = img.src;
    modalCaption.textContent = img.name;
    modalCounter.textContent = (modalIndex + 1) + ' / ' + imageList.length;
}

document.addEventListener('keydown', function(e) {
    if (modal.classList.contains('open')) {
        if (e.key === 'Escape') closeModal();
        else if (e.key === 'ArrowLeft') modalNav(-1);
        else if (e.key === 'ArrowRight') modalNav(1);
    }
});

(function() {
    var startX = 0, startY = 0;
    modal.addEventListener('touchstart', function(e) {
        startX = e.changedTouches[0].screenX;
        startY = e.changedTouches[0].screenY;
    }, { passive: true });
    modal.addEventListener('touchend', function(e) {
        var dx = e.changedTouches[0].screenX - startX;
        var dy = e.changedTouches[0].screenY - startY;
        if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 50) {
            modalNav(dx < 0 ? 1 : -1);
        }
    }, { passive: true });
})();

modal.addEventListener('click', function(e) {
    if (e.target === modal) closeModal();
});

document.querySelectorAll('.sidebar-item').forEach(function(item) {
    item.addEventListener('click', function() {
        if (window.innerWidth <= 768) {
            document.getElementById('sidebar').classList.remove('open');
        }
    });
});

// --- Audio Modal ---
var audioListData = <?= json_encode($audioList, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?: '[]' ?>;
var audioIndex = 0;
var audioModal = document.getElementById('audioModal');
var audioPlayer = document.getElementById('audioPlayer');
var audioTitle = document.getElementById('audioTitle');
var audioToggleBtn = document.getElementById('audioToggleBtn');

function toggleAudioModal() {
    if (audioModal.classList.contains('open')) {
        audioModal.classList.remove('open');
        audioPlayer.pause();
        if (audioToggleBtn) audioToggleBtn.classList.remove('playing');
    } else {
        if (!audioListData.length) return;
        audioModal.classList.add('open');
        showAudioTrack();
        if (audioToggleBtn) audioToggleBtn.classList.add('playing');
    }
}

function audioNav(dir) {
    audioIndex += dir;
    if (audioIndex < 0) audioIndex = audioListData.length - 1;
    if (audioIndex >= audioListData.length) audioIndex = 0;
    showAudioTrack();
    audioPlayer.play();
}

function showAudioTrack() {
    if (!audioListData.length) return;
    var track = audioListData[audioIndex];
    audioPlayer.src = track.src;
    audioPlayer.type = track.mime;
    audioTitle.textContent = track.name;
    document.getElementById('audioPrevBtn').disabled = audioListData.length <= 1;
    document.getElementById('audioNextBtn').disabled = audioListData.length <= 1;
}

// Auto-open audio modal if an audio file was clicked
<?php if ($currentAudioIdx >= 0): ?>
(function() {
    audioIndex = <?= $currentAudioIdx ?>;
    audioModal.classList.add('open');
    showAudioTrack();
    audioPlayer.play();
    if (audioToggleBtn) audioToggleBtn.classList.add('playing');
})();
<?php endif; ?>

// Make sidebar audio file clicks open modal instead of navigating
document.querySelectorAll('.sidebar-item').forEach(function(item) {
    var href = item.getAttribute('href');
    if (!href) return;
    var isAudio = false;
    for (var i = 0; i < audioListData.length; i++) {
        if (href === audioListData[i].url) { isAudio = true; item.dataset.audioIdx = i; break; }
    }
    if (isAudio) {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            audioIndex = parseInt(this.dataset.audioIdx);
            if (!audioModal.classList.contains('open')) {
                audioModal.classList.add('open');
                if (audioToggleBtn) audioToggleBtn.classList.add('playing');
            }
            showAudioTrack();
            audioPlayer.play();
        });
    }
});
</script>
</body>
</html>
