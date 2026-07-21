<?php
$contentDir = '.';

// --- Range-request media proxy (enables seeking in PHP dev server) ---
if (isset($_GET['stream'])) {
    $mediaExts = ['mp4','webm','ogg','mov','avi','mkv','m4v','mp3','m4a','wav','flac','aac'];
    $streamPath = $contentDir . '/' . $_GET['stream'];
    $ext = strtolower(pathinfo($streamPath, PATHINFO_EXTENSION));
    if (!in_array($ext, $mediaExts) || !file_exists($streamPath)) {
        http_response_code(404);
        exit;
    }
    $mimeMap = [
        'mp4'=>'video/mp4','webm'=>'video/webm','ogg'=>'video/ogg',
        'mov'=>'video/mp4','m4v'=>'video/mp4','avi'=>'video/x-msvideo','mkv'=>'video/x-matroska',
        'mp3'=>'audio/mpeg','m4a'=>'audio/mp4','wav'=>'audio/wav','flac'=>'audio/flac','aac'=>'audio/aac'
    ];
    $mime = isset($mimeMap[$ext]) ? $mimeMap[$ext] : 'video/mp4';
    $size = filesize($streamPath);
    $start = 0;
    $end = $size - 1;

    header("Content-Type: $mime");
    header("Accept-Ranges: bytes");

    if (isset($_SERVER['HTTP_RANGE'])) {
        preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m);
        $start = intval($m[1]);
        if (!empty($m[2])) $end = intval($m[2]);
        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes $start-$end/$size");
    }

    header("Content-Length: " . ($end - $start + 1));
    $fp = fopen($streamPath, 'rb');
    fseek($fp, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = min(8192, $remaining);
        echo fread($fp, $chunk);
        $remaining -= $chunk;
        flush();
    }
    fclose($fp);
    exit;
}

// --- Create new file endpoint ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['newfile'])) {
    header('Content-Type: application/json');
    $folder = isset($_GET['folder']) ? $_GET['folder'] : '';
    $input = json_decode(file_get_contents('php://input'), true);
    $fileName = isset($input['name']) ? basename($input['name']) : '';
    $content = isset($input['content']) ? $input['content'] : '';
    if (!$fileName) {
        echo json_encode(['ok' => false, 'error' => 'No filename provided']);
        exit;
    }
    // Auto-add .txt if no extension
    if (strpos($fileName, '.') === false) {
        $fileName .= '.txt';
    }
    $allowedExts = ['txt','csv','json','log','md'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts)) {
        echo json_encode(['ok' => false, 'error' => 'File type not allowed (txt, csv, json, log, md)']);
        exit;
    }
    $targetDir = $folder ? $contentDir . '/' . $folder : $contentDir;
    $realContent = realpath($contentDir);
    $realTarget = realpath($targetDir);
    if ($realTarget === false || strpos($realTarget, $realContent) !== 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid folder path']);
        exit;
    }
    $targetPath = $targetDir . '/' . $fileName;
    if (file_exists($targetPath)) {
        echo json_encode(['ok' => false, 'error' => 'File already exists']);
        exit;
    }
    $bytes = file_put_contents($targetPath, $content);
    if ($bytes === false) {
        echo json_encode(['ok' => false, 'error' => 'Write failed']);
        exit;
    }
    echo json_encode(['ok' => true, 'path' => ($folder ? $folder . '/' : '') . $fileName, 'bytes' => $bytes]);
    exit;
}

// --- Save file endpoint (edit & save back) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['save'])) {
    header('Content-Type: application/json');
    $savePath = $contentDir . '/' . $_GET['save'];
    $allowedExts = ['txt','csv','json','log','md'];
    $saveExt = strtolower(pathinfo($savePath, PATHINFO_EXTENSION));
    if (!in_array($saveExt, $allowedExts)) {
        echo json_encode(['ok' => false, 'error' => 'File type not editable']);
        exit;
    }
    $realContent = realpath($contentDir);
    $realSave = realpath(dirname($savePath));
    if ($realSave === false || strpos($realSave, $realContent) !== 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid path']);
        exit;
    }
    if (!file_exists($savePath)) {
        echo json_encode(['ok' => false, 'error' => 'File not found']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['content'])) {
        echo json_encode(['ok' => false, 'error' => 'No content provided']);
        exit;
    }
    $bytes = file_put_contents($savePath, $input['content']);
    if ($bytes === false) {
        echo json_encode(['ok' => false, 'error' => 'Write failed']);
        exit;
    }
    echo json_encode(['ok' => true, 'bytes' => $bytes]);
    exit;
}

// --- Rename file endpoint ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['rename'])) {
    header('Content-Type: application/json');
    $oldRelPath = $_GET['rename'];
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['newName']) || trim($input['newName']) === '') {
        echo json_encode(['ok' => false, 'error' => 'No new name provided']);
        exit;
    }
    $newName = basename(trim($input['newName']));
    $oldFullPath = $contentDir . '/' . $oldRelPath;
    $realContent = realpath($contentDir);
    $realOldDir = realpath(dirname($oldFullPath));
    if ($realOldDir === false || strpos($realOldDir, $realContent) !== 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid path']);
        exit;
    }
    if (!file_exists($oldFullPath)) {
        echo json_encode(['ok' => false, 'error' => 'File not found']);
        exit;
    }
    $newFullPath = dirname($oldFullPath) . '/' . $newName;
    if (file_exists($newFullPath)) {
        echo json_encode(['ok' => false, 'error' => 'A file with that name already exists']);
        exit;
    }
    if (!rename($oldFullPath, $newFullPath)) {
        echo json_encode(['ok' => false, 'error' => 'Rename failed']);
        exit;
    }
    // Build new relative path
    $oldDir = dirname($oldRelPath);
    $newRelPath = ($oldDir && $oldDir !== '.') ? $oldDir . '/' . $newName : $newName;
    echo json_encode(['ok' => true, 'newPath' => $newRelPath, 'newName' => $newName]);
    exit;
}

// --- Search endpoint (recursive file/folder search) ---
if (isset($_GET['search']) && $_GET['search'] !== '') {
    header('Content-Type: application/json');
    $query = strtolower(trim($_GET['search']));
    $maxResults = 50;
    $results = [];

    function searchRecursive($dir, $prefix, $query, &$results, $maxResults) {
        if (count($results) >= $maxResults) return;
        if (!is_dir($dir)) return;
        $entries = scandir($dir);
        if ($entries === false) return;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (count($results) >= $maxResults) return;
            $fullPath = $dir . '/' . $entry;
            $relPath = $prefix ? $prefix . '/' . $entry : $entry;
            if (stripos($entry, $query) !== false) {
                if (is_dir($fullPath)) {
                    $results[] = ['type'=>'folder','name'=>$entry,'path'=>$relPath,'parent'=>$prefix];
                } else {
                    $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                    $results[] = ['type'=>'file','name'=>$entry,'path'=>$relPath,'ext'=>$ext,'parent'=>$prefix];
                }
            }
            if (is_dir($fullPath)) {
                searchRecursive($fullPath, $relPath, $query, $results, $maxResults);
            }
        }
    }

    searchRecursive($contentDir, '', $query, $results, $maxResults);
    echo json_encode($results);
    exit;
}

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
            if ($ext === 'php') continue;
            $items[] = ['type'=>'file','name'=>$entry,'path'=>$entry,'ext'=>$ext,'mtime'=>filemtime($path)];
        }
    }
    usort($items, function($a, $b) use ($sortBy) {
        if ($a['type'] !== $b['type']) return $a['type'] === 'folder' ? -1 : 1;
        if ($sortBy === 'modified') return $b['mtime'] - $a['mtime'];
        if ($sortBy === 'type') {
            $priority = ['txt'=>0,'md'=>1,'docx'=>2];
            $ea = isset($a['ext']) ? strtolower($a['ext']) : '';
            $eb = isset($b['ext']) ? strtolower($b['ext']) : '';
            $pa = isset($priority[$ea]) ? $priority[$ea] : 99;
            $pb = isset($priority[$eb]) ? $priority[$eb] : 99;
            if ($pa !== $pb) return $pa - $pb;
            return strnatcasecmp($a['name'], $b['name']);
        }
        return strnatcasecmp($a['name'], $b['name']);
    });
    return $items;
}

function scanSubfolder($dir, $folder, $sortBy) {
    $path = $dir . '/' . $folder;
    $items = [];
    if (!is_dir($path)) return $items;
    foreach (scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $fullPath = $path . '/' . $entry;
        if (is_dir($fullPath)) {
            $items[] = ['type'=>'folder','name'=>$entry,'path'=>$folder.'/'.$entry,'mtime'=>filemtime($fullPath)];
        } elseif (is_file($fullPath)) {
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if ($ext === 'php') continue;
            $items[] = ['type'=>'file','name'=>$entry,'path'=>$folder.'/'.$entry,'ext'=>$ext,'mtime'=>filemtime($fullPath)];
        }
    }
    usort($items, function($a, $b) use ($sortBy) {
        if ($a['type'] !== $b['type']) return $a['type'] === 'folder' ? -1 : 1;
        if ($sortBy === 'modified') return $b['mtime'] - $a['mtime'];
        if ($sortBy === 'type') {
            $priority = ['txt'=>0,'md'=>1,'docx'=>2];
            $ea = isset($a['ext']) ? strtolower($a['ext']) : '';
            $eb = isset($b['ext']) ? strtolower($b['ext']) : '';
            $pa = isset($priority[$ea]) ? $priority[$ea] : 99;
            $pb = isset($priority[$eb]) ? $priority[$eb] : 99;
            if ($pa !== $pb) return $pa - $pb;
            return strnatcasecmp($a['name'], $b['name']);
        }
        return strnatcasecmp($a['name'], $b['name']);
    });
    return $items;
}

function getParentFolder($folder) {
    $parent = dirname($folder);
    return ($parent === '.' || $parent === '') ? null : $parent;
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
    global $sortBy, $p2File;
    $params['sort'] = $sortBy;
    if (!empty($p2File) && !isset($params['p2'])) $params['p2'] = $p2File;
    return '?' . http_build_query($params);
}

// --- Data ---
$items = scanContent($contentDir, $sortBy);

$folderItems = [];
$folderFiles = [];
$folderSubfolders = [];
if ($currentFolder) {
    $folderItems = scanSubfolder($contentDir, $currentFolder, $sortBy);
    $folderFiles = array_values(array_filter($folderItems, function($i) { return $i['type'] === 'file'; }));
    $folderSubfolders = array_values(array_filter($folderItems, function($i) { return $i['type'] === 'folder'; }));
}
$parentFolder = $currentFolder ? getParentFolder($currentFolder) : null;

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

// Right pane (dual-pane mode)
$p2File = isset($_GET['p2']) ? $_GET['p2'] : null;
$p2DisplayContent = null;
$p2DisplayType    = null;
if ($p2File) {
    $p2FilePath = $contentDir . '/' . $p2File;
    if (file_exists($p2FilePath)) {
        $p2Ext    = strtolower(pathinfo($p2File, PATHINFO_EXTENSION));
        $p2TextExts = ['txt','csv','json','log'];
        if ($p2Ext === 'md')                    { $p2DisplayType = 'markdown'; $p2DisplayContent = file_get_contents($p2FilePath); }
        elseif (in_array($p2Ext, $p2TextExts))  { $p2DisplayType = 'text';     $p2DisplayContent = file_get_contents($p2FilePath); }
    }
}
$p2CloseParams = $_GET; unset($p2CloseParams['p2']);
$p2CloseUrl = $p2CloseParams ? '?' . http_build_query($p2CloseParams) : '?';

// File list (files-only, used for prev/next arrows, image modal, audio list)
$fileList = $currentFolder
    ? $folderFiles
    : array_values(array_filter($items, function($i) { return $i['type'] === 'file'; }));

// Prev / next (files only — arrows never land on folders)
$prevFile = null;
$nextFile = null;
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
            'src'  => '?stream=' . ($currentFolder ? $currentFolder . '/' : '') . $f['path'],
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
    foreach ($audioList as $ai => $aEntry) {
        if ($aEntry['name'] === basename($currentFile)) { $currentAudioIdx = $ai; break; }
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

/* Search bar */
.search-bar {
    padding: 6px 8px;
    border-bottom: 1px solid #333;
}
.search-bar input {
    width: 100%;
    padding: 6px 8px;
    border: 1px solid #444;
    border-radius: 6px;
    background: #16213e;
    color: #eee;
    font-size: 12px;
    outline: none;
    box-sizing: border-box;
}
.search-bar input::placeholder { color: #666; }
.search-bar input:focus { border-color: #7ec8e3; }
.search-result-parent { font-size: 10px; color: #666; display: block; margin-top: 2px; }

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
.sidebar-item.p2-active { background: #16213e; border-left-color: #ea580c; color: #ea580c; }
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
    overflow: hidden;
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

/* Dual-pane layout */
.panes-container { flex: 1; display: flex; overflow: hidden; }
.pane-divider { width: 3px; background: #ccc; flex-shrink: 0; }
body.dark .pane-divider { background: #444; }
.pane-right {
    flex: 1; overflow-y: auto; padding: 20px;
    background: #fafafa; position: relative;
    border-left: 1px solid #ddd;
}
body.dark .pane-right { background: #1e1e1e; border-left-color: #444; }
.pane-right-bar {
    padding: 5px 10px; background: #e8f0fe; border-bottom: 1px solid #c5d8f6;
    font-size: 12px; color: #555; display: flex; justify-content: space-between;
    align-items: center; margin: -20px -20px 14px -20px; flex-shrink: 0;
}
body.dark .pane-right-bar { background: #253545; border-bottom-color: #3a4a5a; color: #aaa; }
.pr-close { color: #999; text-decoration: none; font-size: 18px; line-height: 1; padding: 0 4px; }
.pr-close:hover { color: #c00; }
body.dark #splitBtn { background: #555; color: #ffdd57; }
#splitBtn.split-active { background: #ea580c !important; color: #fff !important; }

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
.folder-card.kb-focus {
    outline: 3px solid #667eea;
    outline-offset: 2px;
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(102,126,234,0.5);
}
body.dark .folder-card.kb-focus {
    outline-color: #7ec8e3;
    box-shadow: 0 6px 20px rgba(126,200,227,0.4);
}
.gallery-item.kb-focus {
    outline: 3px solid #667eea;
    outline-offset: 3px;
    border-radius: 4px;
}
body.dark .gallery-item.kb-focus {
    outline-color: #7ec8e3;
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
/* Audio time-jump modal */
#audioJumpModal {
    display: none;
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    z-index: 3000;
    align-items: center; justify-content: center;
    background: rgba(0,0,0,0.55);
}
#audioJumpModal.open { display: flex; }
#audioJumpModal .ajm-box {
    background: #1a1a2e; border: 1px solid #7ec8e3;
    border-radius: 10px; padding: 20px 24px;
    display: flex; flex-direction: column; align-items: center; gap: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.6); min-width: 200px;
}
#audioJumpModal .ajm-label {
    color: #7ec8e3; font-size: 13px; font-weight: 600;
}
#audioJumpInput {
    width: 110px; padding: 8px 10px; border: 1px solid #555;
    border-radius: 6px; background: #0e0e1f; color: #fff;
    font-size: 20px; text-align: center; outline: none;
    letter-spacing: 2px;
}
#audioJumpInput:focus { border-color: #7ec8e3; }
#audioJumpModal .ajm-hint {
    color: #666; font-size: 11px;
}
/* Shortcuts modal */
#shortcutsModal {
    display: none;
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    z-index: 3000;
    align-items: flex-start; justify-content: center;
    background: rgba(0,0,0,0.6);
    overflow-y: auto; padding: 40px 16px;
}
#shortcutsModal.open { display: flex; }
#shortcutsModal .sc-box {
    background: #fff; border-radius: 12px; padding: 28px 32px;
    max-width: 560px; width: 100%; position: relative;
    box-shadow: 0 12px 40px rgba(0,0,0,0.35);
    max-height: 80vh; overflow-y: auto;
}
body.dark #shortcutsModal .sc-box { background: #1e1e2e; color: #ddd; }
#shortcutsModal .sc-title {
    font-size: 16px; font-weight: 700; margin-bottom: 16px;
    color: #667eea; display: flex; justify-content: space-between; align-items: center;
}
#shortcutsModal .sc-close {
    background: none; border: none; cursor: pointer;
    font-size: 22px; line-height: 1; color: #999; padding: 0 4px;
}
#shortcutsModal .sc-close:hover { color: #c00; }
#shortcutsModal .sc-body h2,
#shortcutsModal .sc-body h3 { color: #667eea; margin: 16px 0 6px; font-size: 14px; }
#shortcutsModal .sc-body h2 { font-size: 15px; }
#shortcutsModal .sc-body ul { margin: 0 0 10px 18px; padding: 0; }
#shortcutsModal .sc-body li { margin: 4px 0; font-size: 13px; line-height: 1.6; }
#shortcutsModal .sc-body code { background: rgba(102,126,234,0.12); border-radius: 4px; padding: 1px 5px; font-size: 12px; }
#shortcutsModal .sc-body p { font-size: 13px; margin: 6px 0; }
#shortcutsModal .sc-body hr { border: none; border-top: 1px solid #eee; margin: 12px 0; }
body.dark #shortcutsModal .sc-body hr { border-top-color: #333; }
body.dark #shortcutsModal .sc-body code { background: rgba(102,126,234,0.2); }
/* Serve panel */
#servePanel {
    position: fixed; top: 0; right: -340px; width: 320px; height: 100%;
    background: #fff; z-index: 2000;
    box-shadow: -4px 0 24px rgba(0,0,0,0.18);
    transition: right 0.25s ease; overflow-y: auto; padding: 28px 24px;
    box-sizing: border-box;
}
#servePanel.open { right: 0; }
body.dark #servePanel { background: #1e1e2e; color: #ddd; }
#servePanel .sv-title {
    font-size: 15px; font-weight: 700; margin-bottom: 18px;
    color: rgb(52,168,83); display: flex; justify-content: space-between; align-items: center;
}
#servePanel .sv-close {
    background: none; border: none; cursor: pointer;
    font-size: 22px; line-height: 1; color: #999; padding: 0 4px;
}
#servePanel .sv-close:hover { color: #c00; }
#servePanel .sv-row { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
#servePanel .sv-row label { font-size: 12px; font-weight: 700; color: #667eea; min-width: 50px; }
#servePanel .sv-row input {
    flex: 1; font-size: 13px; padding: 5px 8px; border: 1px solid #ccc;
    border-radius: 6px; background: #f7f7f7; color: #222; font-family: monospace;
}
body.dark #servePanel .sv-row input { background: #2a2a3e; border-color: #555; color: #ddd; }
#servePanel .sv-cmd {
    margin-top: 16px; background: #f0f4ff; border-radius: 8px;
    padding: 12px 14px; font-family: monospace; font-size: 12px;
    word-break: break-all; color: #222; border: 1px solid #d0d8f0; line-height: 1.6;
}
body.dark #servePanel .sv-cmd { background: #252535; border-color: #444; color: #adf; }
#servePanel .sv-copy {
    margin-top: 14px; width: 100%; height: 34px; font-size: 13px; font-weight: 700;
    border: none; border-radius: 8px; cursor: pointer;
    background: rgb(52,168,83); color: #fff;
}
#servePanel .sv-section {
    margin-top: 22px; padding-top: 16px;
    border-top: 1px solid #e0e0e0; font-size: 13px; font-weight: 700;
    color: rgb(52,168,83); margin-bottom: 14px;
}
body.dark #servePanel .sv-section { border-top-color: #444; }
#servePanel .sv-toggle { display: flex; gap: 6px; margin-bottom: 12px; }
#servePanel .sv-toggle button {
    flex: 1; height: 28px; font-size: 12px; font-weight: 700;
    border: 1px solid #ccc; border-radius: 6px; cursor: pointer;
    background: #f7f7f7; color: #555;
}
body.dark #servePanel .sv-toggle button { background: #2a2a3e; border-color: #555; color: #aaa; }
#servePanel .sv-toggle button.sv-active {
    background: rgb(52,168,83); border-color: rgb(52,168,83); color: #fff;
}
#servePanel .sv-info {
    margin-top: 16px; background: #f7f9ff; border-radius: 8px;
    padding: 11px 13px; font-size: 11px; color: #555; line-height: 1.6;
    border: 1px solid #e0e8f8;
}
body.dark #servePanel .sv-info { background: #1a1a2e; border-color: #333; color: #999; }
#servePanel .sv-info b { color: #667eea; }
#servePanel .sv-info .sv-info-safe { color: rgb(52,168,83); font-weight: 700; }
#servePanel .sv-info hr { border: none; border-top: 1px solid #e0e8f8; margin: 8px 0; }
body.dark #servePanel .sv-info hr { border-top-color: #333; }
.audio-toggle-btn {
    width: 32px; height: 32px; font-size: 16px; font-weight: 700;
    border: none; border-radius: 8px; cursor: pointer;
    background: rgb(224,224,224); color: rgb(51,51,51);
    display: flex; align-items: center; justify-content: center;
}
.audio-toggle-btn.playing { background: #7ec8e3; color: #1a1a2e; }

/* TTS selection tooltip */
#ttsTooltip {
    display: none;
    position: fixed;
    z-index: 4000;
    background: #1a1a2e;
    border: 1px solid #7ec8e3;
    border-radius: 8px;
    padding: 4px 6px;
    gap: 4px;
    align-items: center;
    box-shadow: 0 4px 16px rgba(0,0,0,0.5);
    white-space: nowrap;
}
#ttsTooltip.open { display: flex; }
.tts-tip-btn {
    border: none; border-radius: 6px; cursor: pointer;
    padding: 4px 8px; font-size: 12px; font-weight: 700;
    background: #2a2a4a; color: #eee;
    transition: background 0.12s;
}
.tts-tip-btn:hover { background: #7ec8e3; color: #1a1a2e; }
.tts-tip-gear {
    border: none; border-radius: 6px; cursor: pointer;
    padding: 4px 6px; font-size: 12px;
    background: none; color: #666;
}
.tts-tip-gear:hover { color: #7ec8e3; }
/* TTS settings panel */
#ttsBtnSettings {
    display: none;
    position: fixed;
    z-index: 4100;
    background: #1a1a2e;
    border: 1px solid #7ec8e3;
    border-radius: 10px;
    padding: 12px 14px;
    box-shadow: 0 8px 28px rgba(0,0,0,0.6);
    min-width: 180px;
}
#ttsBtnSettings.open { display: block; }
#ttsBtnSettings .tbs-title {
    color: #7ec8e3; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em;
    margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;
}
#ttsBtnSettings .tbs-close {
    background: none; border: none; color: #666; font-size: 16px; cursor: pointer; padding: 0;
}
#ttsBtnSettings .tbs-close:hover { color: #fff; }
#ttsBtnSettings .tbs-row {
    display: flex; align-items: center; gap: 8px;
    padding: 5px 0; cursor: pointer; user-select: none;
}
#ttsBtnSettings .tbs-row input { cursor: pointer; accent-color: #7ec8e3; }
#ttsBtnSettings .tbs-row label { color: #ddd; font-size: 13px; cursor: pointer; }

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
body.dark .content-area > button[title="Page down"] { border-color: rgba(255,255,255,0.5); background: rgba(255,255,255,0.1); }
body.dark .content-area > button[title="Page down"] svg path { stroke: rgba(255,255,255,0.5); }
body.dark #copyBtn { background: #555; color: #ffdd57; }

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
    .yt-modal { left: 0; }
}

/* YouTube modal */
.yt-modal {
    display: none;
    position: fixed; top: 0; left: 220px; right: 0;
    background: #1a1a2e;
    z-index: 1400;
    flex-direction: column;
    align-items: center;
    padding: 12px 16px 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.4);
    border-bottom: 2px solid #ff0000;
}
.yt-modal.open { display: flex; }
.yt-modal-header {
    display: flex;
    width: 100%;
    max-width: 700px;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.yt-modal-title {
    color: #eee; font-size: 13px; font-weight: 600;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    flex: 1; margin-right: 10px;
}
.yt-modal-close {
    background: none; border: none; color: #888; font-size: 22px;
    cursor: pointer; padding: 0 4px;
}
.yt-modal-close:hover { color: #fff; }
.yt-track-list {
    display: flex; gap: 6px; margin-bottom: 8px; flex-wrap: wrap;
    justify-content: center; max-width: 700px; width: 100%;
}
.yt-track-btn {
    padding: 4px 10px; border-radius: 6px; border: 1px solid #555;
    background: #333; color: #ccc; cursor: pointer; font-size: 11px;
    white-space: nowrap;
}
.yt-track-btn.active { background: #c00; color: #fff; border-color: #f00; }
.yt-iframe-wrap {
    width: 100%; max-width: 700px;
}
.yt-iframe-wrap iframe {
    width: 100%; height: 200px; border: none; border-radius: 6px;
}

/* YouTube Embed (paste-any-URL) footer modal */
.yt-embed-modal {
    display: none;
    position: fixed; bottom: 0; left: 220px; right: 0;
    height: 70vh;
    background: #0f0f0f;
    z-index: 1490;
    flex-direction: column;
    align-items: center;
    padding: 10px 16px 14px;
    box-shadow: 0 -4px 20px rgba(0,0,0,0.5);
    border-top: 2px solid #ff0000;
}
.yt-embed-modal.open { display: flex; }
@media (max-width: 768px) { .yt-embed-modal { left: 0; } }
.yt-embed-bar {
    display: flex; width: 100%; max-width: 960px;
    gap: 6px; align-items: center; margin-bottom: 8px;
    flex-shrink: 0;
}
.yt-embed-input {
    flex: 1; padding: 6px 10px; border-radius: 6px;
    border: 1px solid #444; background: #222; color: #eee;
    font-size: 12px; outline: none;
}
.yt-embed-input:focus { border-color: #ff0000; }
.yt-embed-load-btn {
    padding: 6px 12px; border-radius: 6px; border: none;
    background: #ff0000; color: #fff; font-size: 12px;
    font-weight: 700; cursor: pointer; white-space: nowrap;
}
.yt-embed-load-btn:hover { background: #cc0000; }
.yt-embed-close {
    background: none; border: none; color: #888;
    font-size: 22px; cursor: pointer; padding: 0 4px; line-height: 1;
}
.yt-embed-close:hover { color: #fff; }
.yt-embed-player-wrap {
    width: 100%; max-width: 960px;
    flex: 1; min-height: 0;
}
#ytEmbedPlayerEl, .yt-embed-player-wrap iframe {
    width: 100%; height: 100%; border: none; border-radius: 6px;
    display: block;
}
</style>
</head>
<body>

<button class="sidebar-toggle" onclick="toggleSidebar()">&#9776;</button>

<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <?php if ($currentFolder): ?>
            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars(basename($currentFolder)) ?></span>
            <?php if ($parentFolder !== null): ?>
                <a href="<?= itemUrl(['folder'=>$parentFolder]) ?>">Back</a>
            <?php else: ?>
                <a href="<?= itemUrl([]) ?>">Back</a>
            <?php endif; ?>
        <?php else: ?>
            <span>Content</span>
            <span style="font-size:11px;color:#888"><?= count($items) ?> items</span>
        <?php endif; ?>
    </div>


    <div class="sort-bar">
        <a href="<?= sortUrl('name') ?>" class="<?= $sortBy === 'name' ? 'active' : '' ?>">Name</a>
        <a href="<?= sortUrl('modified') ?>" class="<?= $sortBy === 'modified' ? 'active' : '' ?>">Modified</a>
        <a href="<?= sortUrl('type') ?>" class="<?= $sortBy === 'type' ? 'active' : '' ?>">by txt</a>
    </div>

    <div class="search-bar">
        <input type="text" id="searchInput" placeholder="Search files &amp; folders..." autocomplete="off">
    </div>

    <div id="searchResults" class="sidebar-list" style="display:none"></div>

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

    <div class="sidebar-list" id="sidebarList">
        <?php if ($currentFolder): ?>
            <?php if ($parentFolder !== null): ?>
                <a class="sidebar-item back" href="<?= itemUrl(['folder'=>$parentFolder]) ?>"><?= htmlspecialchars(basename($parentFolder)) ?></a>
            <?php else: ?>
                <a class="sidebar-item back" href="<?= itemUrl([]) ?>">All folders</a>
            <?php endif; ?>
            <?php if (!empty($folderFiles)): ?>
            <a class="sidebar-item <?= (!$currentFile && isset($_GET['view']) ? 'active' : '') ?>"
               href="<?= itemUrl(['folder'=>$currentFolder,'view'=>'all']) ?>">
                View all images
            </a>
            <?php endif; ?>
            <?php foreach ($folderSubfolders as $sf): ?>
                <a class="sidebar-item folder" href="<?= itemUrl(['folder'=>$sf['path']]) ?>">
                    <?= htmlspecialchars($sf['name']) ?>
                    <?php if ($sortBy === 'modified'): ?>
                        <span class="file-date"><?= date('M j, Y g:ia', $sf['mtime']) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
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
    <div class="main-header">
        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;margin-right:10px">
            <?php if ($currentFolder && !$currentFile):
                // Breadcrumb navigation for folder path
                $parts = explode('/', $currentFolder);
                $crumbPath = '';
                echo '<a href="' . itemUrl([]) . '" style="color:#7ec8e3;text-decoration:none">Home</a>';
                foreach ($parts as $pi => $part):
                    $crumbPath .= ($pi > 0 ? '/' : '') . $part;
                    echo ' / ';
                    if ($pi === count($parts) - 1):
                        echo htmlspecialchars($part);
                    else:
                        echo '<a href="' . itemUrl(['folder'=>$crumbPath]) . '" style="color:#7ec8e3;text-decoration:none">' . htmlspecialchars($part) . '</a>';
                    endif;
                endforeach;
            elseif ($currentFile):
                echo htmlspecialchars($currentFile);
            else:
                echo '<a href="' . itemUrl([]) . '" style="color:#7ec8e3;text-decoration:none">Home</a>';
            endif; ?>
        </span>
        <?php if ($currentFile): ?>
            <button id="renameBtn" title="Rename file" onclick="renameFile()" style="width:auto;height:28px;font-size:12px;padding:0 8px;border:none;border-radius:6px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51);flex-shrink:0;margin-right:4px">Rename</button>
        <?php endif; ?>
        <div style="display:flex;align-items:center;gap:6px;flex-shrink:0">
            <button title="New file (from clipboard)" onclick="createNewFile()" style="width:32px;height:32px;font-size:16px;font-weight:700;border:none;border-radius:8px;cursor:pointer;background:#10b981;color:#fff">+</button>
            <button title="Decrease font size" onclick="adjustFontSize(-1)" style="width:32px;height:32px;font-size:18px;font-weight:700;border:none;border-radius:8px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51)">-</button>
            <button title="Increase font size" onclick="adjustFontSize(1)" style="width:32px;height:32px;font-size:18px;font-weight:700;border:none;border-radius:8px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51)">+</button>
            <button id="marginBtn" title="Toggle reading margins" onclick="toggleMargins()" style="width:32px;height:32px;font-size:14px;font-weight:700;border:none;border-radius:8px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51)">&#8614;</button>
            <button id="darkModeBtn" title="Toggle dark/light mode" onclick="toggleDarkMode()" style="width:32px;height:32px;font-size:16px;font-weight:700;border:none;border-radius:8px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51)">&#9789;</button>
            <button id="splitBtn" title="Toggle dual-pane (left=media, right=text/md)" onclick="toggleSplit()" style="height:28px;font-size:11px;font-weight:700;padding:0 8px;border:none;border-radius:6px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51)">P2</button>
            <button id="shortcutsBtn" title="Keyboard shortcuts" onclick="openShortcuts()" style="height:28px;font-size:11px;font-weight:700;padding:0 10px;border:none;border-radius:6px;cursor:pointer;background:rgb(102,126,234);color:#fff">Shortcuts</button>
            <button id="servePanelBtn" title="PHP serve command" onclick="openServePanel()" style="height:28px;font-size:11px;font-weight:700;padding:0 10px;border:none;border-radius:6px;cursor:pointer;background:rgb(52,168,83);color:#fff">Serve</button>
            <?php if ($displayType === 'text' || $displayType === 'markdown'): ?>
                <button id="copyBtn" title="Copy content" onclick="copyContent()" style="width:32px;height:32px;font-size:16px;font-weight:700;border:none;border-radius:8px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51)">&#128203;</button>
                <button id="editBtn" class="edit-btn" title="Edit and save back to local file" onclick="toggleEdit()" style="width:32px;height:32px;font-size:14px;font-weight:700;border:none;border-radius:8px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51)">&#9998;</button>
            <?php endif; ?>
            <?php if ($displayType === 'text' || $displayType === 'markdown'): ?>
                <button id="txtMdBtn" onclick="toggleLeftTxtMd()" title="Toggle markdown/text view" style="height:28px;font-size:11px;font-weight:700;padding:0 8px;border:none;border-radius:6px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51)"><?= $displayType === 'markdown' ? 'MD&gt;TXT' : 'TXT&gt;MD' ?></button>
            <?php endif; ?>
            <?php if (!empty($audioList)): ?>
                <button class="audio-toggle-btn" id="audioToggleBtn" title="Toggle audio player" onclick="toggleAudioModal()">&#9835;</button>
            <?php endif; ?>
            <button title="YouTube music" onclick="toggleYtModal()" style="width:32px;height:32px;border:none;border-radius:8px;cursor:pointer;background:rgb(224,224,224);display:flex;align-items:center;justify-content:center;padding:0"><svg width="20" height="14" viewBox="0 0 68 48"><path d="M66.5 7.7s-.7-4.7-2.7-6.8C61-1.7 58-1.7 56.6-1.9 47.3-2.6 34-2.6 34-2.6s-13.3 0-22.6.7C10-1.7 7-1.7 4.2.9 2.2 3 1.5 7.7 1.5 7.7S.8 13.2.8 18.8v5.2c0 5.5.7 11.1.7 11.1s.7 4.7 2.7 6.8c2.8 2.6 6.4 2.5 8 2.8 5.8.5 24.8.7 24.8.7s13.3 0 22.6-.7c1.4-.2 4.4-.2 7.2-2.8 2-2.1 2.7-6.8 2.7-6.8s.7-5.5.7-11.1v-5.2c0-5.6-.7-11.1-.7-11.1z" fill="red"/><path d="M27 33V13l18.2 10L27 33z" fill="white"/></svg></button>
            <button id="ytEmbedToggleBtn" title="YouTube embed — paste any URL" onclick="toggleYtEmbedModal()" style="height:28px;font-size:11px;font-weight:700;padding:0 8px;border:none;border-radius:6px;cursor:pointer;background:#ff0000;color:#fff">YT</button>
            <?php if ($currentFile): ?>
                <a class="download-link" href="<?= $contentDir . '/' . htmlspecialchars($currentFile) ?>" download>Download</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="panes-container" id="panesContainer">
    <div class="content-area" id="contentArea">
        <button title="Page down" onclick="contentPageDown()" style="position:sticky;top:6px;left:6px;z-index:10;width:48px;height:48px;background:rgba(0,0,0,0.08);border-radius:50%;border:1.5px solid rgb(0,0,0);opacity:0.15;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:0.2s;margin-bottom:-48px;float:left;"><svg width="48" height="48" viewBox="0 0 64 64"><path d="M8 20 L32 44 L56 20" stroke="rgba(0,0,0,0.7)" stroke-width="8" fill="none" stroke-linecap="round" stroke-linejoin="round"></path></svg></button>
        <button title="Page down" onclick="contentPageDown()" style="position:sticky;top:50%;left:6px;z-index:10;width:48px;height:48px;background:rgba(0,0,0,0.12);border-radius:50%;border:1.5px solid rgb(0,0,0);opacity:0.2;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:0.2s;margin-bottom:-48px;float:left;transform:scale(1.1);"><svg width="48" height="48" viewBox="0 0 64 64"><path d="M8 20 L32 44 L56 20" stroke="rgba(0,0,0,0.7)" stroke-width="8" fill="none" stroke-linecap="round" stroke-linejoin="round"></path></svg></button>
        <button title="Page down" onclick="contentPageDown()" style="position:sticky;top:90%;left:6px;z-index:10;width:48px;height:48px;background:rgba(0,0,0,0.08);border-radius:50%;border:1.5px solid rgb(0,0,0);opacity:0.15;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:0.2s;margin-bottom:-48px;float:left;"><svg width="48" height="48" viewBox="0 0 64 64"><path d="M8 20 L32 44 L56 20" stroke="rgba(0,0,0,0.7)" stroke-width="8" fill="none" stroke-linecap="round" stroke-linejoin="round"></path></svg></button>

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
                    <a href="<?= itemUrl(['folder'=>$currentFolder,'file'=>$f['path']]) ?>">
                        <img src="<?= $contentDir . '/' . htmlspecialchars($enc) ?>"
                             alt="<?= htmlspecialchars($f['name']) ?>">
                    </a>
                <?php $sIdx++; endif; endforeach; ?>
            </div>

        <?php elseif ($currentFolder && !$currentFile): ?>
            <!-- Subfolder cards -->
            <?php if (!empty($folderSubfolders)): ?>
            <div class="folder-grid" style="margin-bottom:20px">
                <?php foreach ($folderSubfolders as $sf):
                    $sfThumb = getFolderThumb($contentDir, $sf['path']);
                    $sfEncodedThumb = $sfThumb
                        ? $contentDir . '/' . implode('/', array_map('rawurlencode', explode('/', $sf['path'] . '/' . basename($sfThumb))))
                        : null;
                ?>
                    <a class="folder-card" href="<?= itemUrl(['folder'=>$sf['path']]) ?>">
                        <?php if ($sfEncodedThumb): ?>
                            <img class="thumb" src="<?= htmlspecialchars($sfEncodedThumb) ?>" alt="">
                        <?php else: ?>
                            <div class="no-thumb">&#128193;</div>
                        <?php endif; ?>
                        <div class="label"><?= htmlspecialchars($sf['name']) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

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
                        <a href="<?= itemUrl(['folder'=>$currentFolder,'file'=>$f['path']]) ?>">
                            <img src="<?= $contentDir . '/' . htmlspecialchars($enc) ?>"
                                 alt="<?= htmlspecialchars($f['name']) ?>">
                        </a>
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
                    elseif (in_array($f['ext'], ['txt','csv','json','log','md','html','htm','docx','rtf','pdf'])):
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
                 style="max-width:100%">

        <?php elseif ($displayType === 'video'):
            $encodedVideoPath = $contentDir . '/' . implode('/', array_map('rawurlencode', explode('/', $currentFile)));
            $videoMime = [
                'mp4'=>'video/mp4','webm'=>'video/webm','ogg'=>'video/ogg',
                'mov'=>'video/mp4','m4v'=>'video/mp4','avi'=>'video/x-msvideo','mkv'=>'video/x-matroska'
            ];
            $ext = strtolower(pathinfo($currentFile, PATHINFO_EXTENSION));
            $mime = isset($videoMime[$ext]) ? $videoMime[$ext] : 'video/mp4';
        ?>
            <video id="mainVideo" controls autoplay loop muted style="max-height:80vh">
                <source src="?stream=<?= htmlspecialchars($currentFile) ?>" type="<?= $mime ?>">
                Your browser does not support this video format.
                <a href="<?= htmlspecialchars($encodedVideoPath) ?>" download>Download video</a>
            </video>
            <div style="display:flex;gap:8px;margin-top:8px;justify-content:center;align-items:center;">
                <button id="loopBtn" onclick="toggleLoop()" style="padding:6px 14px;border-radius:6px;border:1px solid #555;background:#555;color:#ccc;cursor:pointer;font-size:13px;">Loop ON</button>
                <button id="speedBtn" onclick="toggleSpeed()" style="padding:6px 14px;border-radius:6px;border:1px solid #555;background:#333;color:#ccc;cursor:pointer;font-size:13px;">0.5x</button>
                <span style="border-left:1px solid #555;height:20px;margin:0 4px;"></span>
                <input id="jumpTimeInput" type="text" placeholder="1:15" style="width:60px;padding:4px 6px;border:1px solid #555;border-radius:6px;background:#222;color:#fff;font-size:12px;text-align:center;">
                <button id="addTimeBtn" style="padding:6px 14px;border-radius:6px;border:1px solid #555;background:#333;color:#ccc;cursor:pointer;font-size:13px;">Add</button>
            </div>
            <div id="savedTimesRow" style="display:flex;gap:6px;margin-top:6px;justify-content:center;flex-wrap:wrap;"></div>
            <script>
            (function() {
                var video = document.getElementById('mainVideo');
                video.playbackRate = 0.5;
                window.toggleLoop = function() {
                    video.loop = !video.loop;
                    var btn = document.getElementById('loopBtn');
                    btn.textContent = video.loop ? 'Loop ON' : 'Loop';
                    btn.style.background = video.loop ? '#555' : '#333';
                };
                window.toggleSpeed = function() {
                    if (video.playbackRate === 1) {
                        video.playbackRate = 0.5;
                        document.getElementById('speedBtn').textContent = '0.5x';
                    } else {
                        video.playbackRate = 1;
                        document.getElementById('speedBtn').textContent = '1x';
                    }
                };

                function parseTime(str) {
                    var parts = str.split(':').map(Number);
                    if (parts.length === 3) return parts[0]*3600 + parts[1]*60 + parts[2];
                    if (parts.length === 2) return parts[0]*60 + parts[1];
                    return parts[0] || 0;
                }

                function addTime() {
                    var input = document.getElementById('jumpTimeInput');
                    var val = input.value.trim();
                    if (!val) return;
                    var seconds = parseTime(val);
                    var row = document.getElementById('savedTimesRow');
                    var btn = document.createElement('button');
                    btn.textContent = val;
                    btn.style.cssText = 'padding:4px 10px;border-radius:6px;border:1px solid #555;background:#444;color:#fff;cursor:pointer;font-size:12px;';
                    btn.addEventListener('click', function() { video.currentTime = seconds; video.play(); });
                    row.appendChild(btn);
                    input.value = '';
                }

                document.getElementById('addTimeBtn').addEventListener('click', addTime);
                document.getElementById('jumpTimeInput').addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') { e.preventDefault(); addTime(); }
                });
            })();
            </script>

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
            <!-- Landing page: folder thumbnail grid + root-level files -->
            <?php
            $rootImageExts = ['png','jpg','jpeg','gif','webp','bmp','svg'];
            $rootVideoExts = ['mp4','webm','ogg','mov','avi','mkv','m4v'];
            $rootAudioExts = ['mp3','m4a'];
            $rootTextExts  = ['txt','csv','json','log','md','html','htm','docx','rtf','pdf'];
            $rootFiles     = array_values(array_filter($items, function($i) { return $i['type'] === 'file'; }));
            $hasAnything   = !empty($folderCards) || !empty($rootFiles);
            ?>
            <?php if (!$hasAnything): ?>
                <div style="color:#999;text-align:center;padding:60px 20px">
                    <p style="font-size:18px;margin-bottom:8px">No content yet</p>
                    <p style="font-size:13px">Add subfolders or files to <code><?= htmlspecialchars($contentDir) ?>/</code> and they'll appear here.</p>
                </div>
            <?php else: ?>
                <?php if (!empty($folderCards)): ?>
                <div class="folder-grid" <?= !empty($rootFiles) ? 'style="margin-bottom:20px"' : '' ?>>
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
                <?php endif; ?>

                <?php if (!empty($rootFiles)): ?>
                <div class="gallery">
                    <?php
                    $rootImgIdx = 0;
                    foreach ($rootFiles as $rf):
                        $rfExt = $rf['ext'] ?? '';
                        $rfEnc = implode('/', array_map('rawurlencode', explode('/', $rf['path'])));
                        if (in_array($rfExt, $rootImageExts)):
                    ?>
                        <div class="gallery-item">
                            <a href="<?= itemUrl(['file'=>$rf['path']]) ?>">
                                <img src="<?= $contentDir . '/' . htmlspecialchars($rfEnc) ?>"
                                     alt="<?= htmlspecialchars($rf['name']) ?>">
                            </a>
                            <div class="caption"><?= htmlspecialchars($rf['name']) ?></div>
                        </div>
                    <?php $rootImgIdx++;
                        elseif (in_array($rfExt, $rootVideoExts)):
                    ?>
                        <div class="gallery-item">
                            <a href="<?= itemUrl(['file'=>$rf['path']]) ?>">
                                <video style="width:100%;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,0.1)" muted preload="metadata">
                                    <source src="<?= $contentDir . '/' . htmlspecialchars($rfEnc) ?>" type="video/mp4">
                                </video>
                            </a>
                            <div class="caption"><?= htmlspecialchars($rf['name']) ?></div>
                        </div>
                    <?php elseif (in_array($rfExt, $rootAudioExts)): ?>
                        <div class="gallery-item">
                            <a href="<?= itemUrl(['file'=>$rf['path']]) ?>"
                               style="display:block;padding:20px;background:#1a1a2e;border-radius:4px;text-decoration:none;color:#eee;box-shadow:0 2px 8px rgba(0,0,0,0.08);text-align:center">
                                <div style="font-size:28px;margin-bottom:8px">&#9835;</div>
                                <?= htmlspecialchars($rf['name']) ?>
                            </a>
                            <div class="caption"><?= htmlspecialchars($rf['name']) ?></div>
                        </div>
                    <?php elseif (in_array($rfExt, $rootTextExts)): ?>
                        <div class="gallery-item">
                            <a href="<?= itemUrl(['file'=>$rf['path']]) ?>"
                               style="display:block;padding:20px;background:#fff;border-radius:4px;text-decoration:none;color:#333;box-shadow:0 2px 8px rgba(0,0,0,0.08)">
                                <?= htmlspecialchars($rf['name']) ?>
                            </a>
                        </div>
                    <?php endif; endforeach; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

    </div>
    <div class="pane-divider" id="paneDivider" style="display:none"></div>
    <div class="pane-right" id="paneRight" style="display:none">
        <?php if ($p2DisplayType): ?>
        <div class="pane-right-bar">
            <span><?= htmlspecialchars(basename($p2File)) ?></span>
            <?php if ($p2DisplayType === 'text' || $p2DisplayType === 'markdown'): ?>
                <button id="p2TxtMdBtn" onclick="toggleRightTxtMd()" title="Toggle markdown/text view" style="height:22px;font-size:10px;font-weight:700;padding:0 6px;border:none;border-radius:4px;cursor:pointer;background:rgba(0,0,0,0.12);color:inherit;margin-right:6px"><?= $p2DisplayType === 'markdown' ? 'MD&gt;TXT' : 'TXT&gt;MD' ?></button>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($p2CloseUrl) ?>" class="pr-close" title="Close right pane">&times;</a>
        </div>
        <?php endif; ?>
        <button title="Page down" onclick="rightPanePageDown()" style="position:sticky;top:6px;left:6px;z-index:10;width:40px;height:40px;background:rgba(0,0,0,0.08);border-radius:50%;border:1.5px solid rgba(0,0,0,0.3);opacity:0.2;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:0.2s;margin-bottom:-40px;float:left;"><svg width="40" height="40" viewBox="0 0 64 64"><path d="M8 20 L32 44 L56 20" stroke="rgba(0,0,0,0.7)" stroke-width="8" fill="none" stroke-linecap="round" stroke-linejoin="round"></path></svg></button>
        <?php if ($p2DisplayType === 'text'): ?>
            <div class="text-content"><?= htmlspecialchars($p2DisplayContent) ?></div>
        <?php elseif ($p2DisplayType === 'markdown'): ?>
            <div class="markdown-content" id="p2-md-render"></div>
            <script>
            (function() {
                var raw = <?= json_encode($p2DisplayContent, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
                marked.setOptions({ breaks: true, gfm: true });
                document.getElementById('p2-md-render').innerHTML = marked.parse(raw);
                document.querySelectorAll('#p2-md-render pre code').forEach(function(b) { hljs.highlightElement(b); });
            })();
            </script>
        <?php else: ?>
            <div style="display:flex;align-items:center;justify-content:center;height:70%;color:#aaa;font-size:13px;text-align:center;pointer-events:none">
                <div><div style="font-size:36px;margin-bottom:10px">&#128196;</div><p>Click a .txt or .md file<br>to open it here</p></div>
            </div>
        <?php endif; ?>
    </div>
    </div>
</div>

<!-- TTS selection tooltip -->
<div id="ttsTooltip"></div>

<!-- TTS button settings panel -->
<div id="ttsBtnSettings">
    <div class="tbs-title">
        <span>TTS Buttons</span>
        <button class="tbs-close" id="ttsBtnSettingsClose">&times;</button>
    </div>
    <div id="ttsBtnSettingsRows"></div>
</div>

<!-- Shortcuts modal -->
<div id="shortcutsModal">
    <div class="sc-box">
        <div class="sc-title">
            <span>Shortcuts</span>
            <button class="sc-close" onclick="closeShortcuts()" title="Close">&times;</button>
        </div>
        <div class="sc-body" id="shortcutsBody"></div>
    </div>
</div>

<!-- Serve panel -->
<div id="servePanel">
    <div class="sv-title">
        <span>&#9654; PHP Serve</span>
        <button class="sv-close" onclick="closeServePanel()" title="Close">&times;</button>
    </div>
    <div class="sv-row">
        <label>Host</label>
        <input id="svHost" value="0.0.0.0" oninput="updateServeCmd()">
    </div>
    <div class="sv-row">
        <label>Port</label>
        <input id="svPort" value="8080" oninput="updateServeCmd()">
    </div>
    <div class="sv-row">
        <label>Path</label>
        <input id="svPath" value="<?php echo htmlspecialchars(realpath($contentDir), ENT_QUOTES); ?>" oninput="updateServeCmd()">
    </div>
    <div class="sv-cmd" id="svCmd"></div>
    <button class="sv-copy" id="svCopyBtn" onclick="copyServeCmd()">Copy command</button>

    <div class="sv-section">&#8645; rclone Sync</div>
    <div class="sv-row">
        <label>Remote</label>
        <input id="svRcloneRemote" value="dropbox:macbook" placeholder="dropbox:macbook" oninput="updateRcloneCmd()">
    </div>
    <div class="sv-toggle">
        <button id="svRcloneCopy" class="sv-active" onclick="setRcloneMode('copy')">copy</button>
        <button id="svRcloneSync" onclick="setRcloneMode('sync')">sync</button>
    </div>
    <div class="sv-cmd" id="svRcloneCmd"></div>
    <button class="sv-copy" id="svRcloneCopyBtn" onclick="copyRcloneCmd()">Copy command</button>
    <div class="sv-info">
        <b>copy</b> src dst<br>
        &nbsp;· Copies new/updated files from src → dst<br>
        &nbsp;· Does <em>not</em> delete files at dst removed from src<br>
        &nbsp;· Re-running uploads only changed files (size + modtime)<br>
        <hr>
        <b>sync</b> src dst<br>
        &nbsp;· Makes dst an <em>exact mirror</em> of src<br>
        &nbsp;· Deletes files at dst that no longer exist in src<br>
        &nbsp;· Destructive — local deletions propagate to remote<br>
        <hr>
        <span class="sv-info-safe">&#10003; Use copy for mobile browsing</span> — nothing on Dropbox gets destroyed. sync removes remote files that don't match local, so a missing local file wipes the remote copy.
    </div>
</div>

<!-- Audio time-jump modal -->
<div id="audioJumpModal">
    <div class="ajm-box">
        <div class="ajm-label">Jump to time</div>
        <input id="audioJumpInput" type="text" placeholder="1:23 or 90" autocomplete="off" inputmode="numeric">
        <div class="ajm-hint">Enter&nbsp;to jump &nbsp;·&nbsp; Esc&nbsp;to cancel &nbsp;·&nbsp; bare&nbsp;seconds&nbsp;ok</div>
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
<div class="yt-modal" id="ytModal">
    <div class="yt-modal-header">
        <div class="yt-modal-title" id="ytTitle">YouTube Music</div>
        <button class="yt-modal-close" onclick="toggleYtModal()">&times;</button>
    </div>
    <div class="yt-track-list" id="ytTrackList"></div>
    <div style="margin-bottom:8px"><button id="ytRandomBtn" onclick="ytRandomSeek()" style="background:linear-gradient(45deg,#ff9800,#f57c00);padding:6px 12px;font-size:12px;border:none;border-radius:6px;color:#fff;cursor:pointer;font-weight:600">🎲 Random</button></div>
    <div class="yt-iframe-wrap" id="ytIframeWrap"></div>
</div>

<div class="audio-modal" id="audioModal">
    <div class="audio-modal-header">
        <div class="audio-modal-title" id="audioTitle">No audio</div>
        <button class="audio-modal-close" onclick="toggleAudioModal()">&times;</button>
    </div>
    <div class="audio-modal-controls">
        <button class="audio-nav-btn" id="audioPrevBtn" onclick="audioNav(-1)">&#8249;</button>
        <button class="audio-nav-btn" onclick="audioSeek(-10)" style="font-size:13px;font-weight:700">-10</button>
        <audio controls id="audioPlayer"></audio>
        <button class="audio-nav-btn" onclick="audioSeek(10)" style="font-size:13px;font-weight:700">+10</button>
        <button class="audio-nav-btn" id="audioNextBtn" onclick="audioNav(1)">&#8250;</button>
    </div>
</div>

<!-- YouTube Embed (paste-any-URL) footer modal -->
<div class="yt-embed-modal" id="ytEmbedModal">
    <div class="yt-embed-bar">
        <input class="yt-embed-input" id="ytEmbedInput" type="text" placeholder="Paste YouTube URL then press Enter or Load…">
        <button class="yt-embed-load-btn" onclick="loadYtEmbed()">&#9654; Load</button>
        <button class="yt-embed-close" onclick="toggleYtEmbedModal()">&times;</button>
    </div>
    <div class="yt-embed-player-wrap" id="ytEmbedPlayerWrap">
        <div id="ytEmbedPlayerEl"></div>
    </div>
</div>

<script>
var imageList = <?= json_encode($imageList, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?: '[]' ?>;
var modalIndex = 0;

// --- Navigation history ([ = back, ] = forward) ---
(function() {
    var currentUrl = window.location.href;
    var action     = '';
    var backStack  = [];
    var fwdStack   = [];
    try {
        action    = sessionStorage.getItem('phpNavAction') || '';
        backStack = JSON.parse(sessionStorage.getItem('phpNavBack') || '[]');
        fwdStack  = JSON.parse(sessionStorage.getItem('phpNavFwd')  || '[]');
    } catch(e) {}

    // Extract the 'folder' param from a URL string
    function getFolder(url) {
        try {
            var qs = url.indexOf('?') >= 0 ? url.substring(url.indexOf('?') + 1) : '';
            var val = '';
            qs.split('&').forEach(function(pair) {
                var idx = pair.indexOf('=');
                if (idx > 0 && decodeURIComponent(pair.substring(0, idx)) === 'folder')
                    val = decodeURIComponent(pair.substring(idx + 1));
            });
            return val;
        } catch(e) { return ''; }
    }

    if (action === 'back' || action === 'forward') {
        // Arrived via [ or ] — don't push anything new
        try { sessionStorage.removeItem('phpNavAction'); } catch(e) {}
    } else {
        // Only push when the folder changes — file-to-file navigation is ignored
        var prevUrl = '';
        try { prevUrl = sessionStorage.getItem('phpNavCurrent') || ''; } catch(e) {}
        if (prevUrl && prevUrl !== currentUrl && getFolder(prevUrl) !== getFolder(currentUrl)) {
            backStack.push(prevUrl);
            fwdStack = [];
            try {
                sessionStorage.setItem('phpNavBack', JSON.stringify(backStack));
                sessionStorage.setItem('phpNavFwd',  JSON.stringify(fwdStack));
            } catch(e) {}
        }
    }
    try { sessionStorage.setItem('phpNavCurrent', currentUrl); } catch(e) {}

    window._navBack = function() {
        if (!backStack.length) return false;
        var target = backStack.pop();
        fwdStack.push(currentUrl);
        try {
            sessionStorage.setItem('phpNavBack',    JSON.stringify(backStack));
            sessionStorage.setItem('phpNavFwd',     JSON.stringify(fwdStack));
            sessionStorage.setItem('phpNavAction',  'back');
            sessionStorage.setItem('phpNavCurrent', target);
        } catch(e) {}
        window.location.href = target;
        return true;
    };
    window._navFwd = function() {
        if (!fwdStack.length) return false;
        var target = fwdStack.pop();
        backStack.push(currentUrl);
        try {
            sessionStorage.setItem('phpNavBack',    JSON.stringify(backStack));
            sessionStorage.setItem('phpNavFwd',     JSON.stringify(fwdStack));
            sessionStorage.setItem('phpNavAction',  'forward');
            sessionStorage.setItem('phpNavCurrent', target);
        } catch(e) {}
        window.location.href = target;
        return true;
    };
})();
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
(function() {
    var m = document.cookie.match('(?:^|; )phpFontSize=([^;]*)');
    if (m) fontSize = parseInt(m[1]) || 14;
})();
function adjustFontSize(dir) {
    fontSize = Math.min(32, Math.max(8, fontSize + dir * 2));
    applyFontSize();
    document.cookie = 'phpFontSize=' + fontSize + '; path=/; max-age=31536000';
}
function applyFontSize() {
    var area = document.getElementById('contentArea');
    area.style.fontSize = fontSize + 'px';
    var targets = area.querySelectorAll('.text-content, .markdown-content, .docx-content');
    targets.forEach(function(el) { el.style.fontSize = fontSize + 'px'; });
    // Also apply to right pane when split is active
    var paneRight = document.getElementById('paneRight');
    if (paneRight) {
        paneRight.style.fontSize = fontSize + 'px';
        paneRight.querySelectorAll('.text-content, .markdown-content').forEach(function(el) {
            el.style.fontSize = fontSize + 'px';
        });
    }
}
if (fontSize !== 14) applyFontSize();

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

// --- Reading margins toggle ---
var marginsOn = false;
function applyMargins() {
    var areas = [document.getElementById('contentArea'), document.getElementById('paneRight')];
    var btn = document.getElementById('marginBtn');
    areas.forEach(function(area) {
        if (!area) return;
        var targets = area.querySelectorAll('.text-content, .markdown-content, .docx-content');
        if (marginsOn) {
            targets.forEach(function(el) { el.style.maxWidth = '750px'; el.style.margin = '0 auto'; });
        } else {
            targets.forEach(function(el) { el.style.maxWidth = ''; el.style.margin = ''; });
        }
    });
    if (marginsOn) {
        btn.style.background = '#7ec8e3';
        btn.style.color = '#1a1a2e';
    } else {
        btn.style.background = 'rgb(224,224,224)';
        btn.style.color = 'rgb(51,51,51)';
        if (document.body.classList.contains('dark')) {
            btn.style.background = '#555';
            btn.style.color = '#ffdd57';
        }
    }
}
function toggleMargins() {
    marginsOn = !marginsOn;
    applyMargins();
    try { localStorage.setItem('readingMargins', marginsOn ? '1' : '0'); } catch(e) {}
}
(function() {
    try {
        if (localStorage.getItem('readingMargins') === '1') {
            marginsOn = true;
            applyMargins();
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
    // Sync sidebar active highlight
    var targetUrl = img.url;
    document.querySelectorAll('.sidebar-item').forEach(function(el) {
        el.classList.remove('active');
        if (el.getAttribute('href') === targetUrl) {
            el.classList.add('active');
            el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    });
}

// Grid/gallery keyboard navigation (0/9 = prev/next, Enter = open)
(function() {
    var kbIdx = -1;

    // Collect all navigable items: folder cards + gallery items
    function getNavItems() {
        var items = [];
        // Folder cards (<a> tags)
        document.querySelectorAll('.folder-grid .folder-card').forEach(function(el) {
            items.push({ el: el, type: 'link', href: el.getAttribute('href') });
        });
        // Gallery items
        document.querySelectorAll('.gallery .gallery-item').forEach(function(el) {
            var link = el.querySelector('a');
            if (link) {
                items.push({ el: el, type: 'link', href: link.getAttribute('href') });
            }
        });
        return items;
    }

    function setKbFocus(items, idx) {
        items.forEach(function(it) { it.el.classList.remove('kb-focus'); });
        if (idx < 0 || idx >= items.length) { kbIdx = -1; return; }
        kbIdx = idx;
        items[idx].el.classList.add('kb-focus');
        items[idx].el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    window.folderGridNav = function(dir) {
        var items = getNavItems();
        if (!items.length) return false;
        var next = kbIdx < 0 ? (dir > 0 ? 0 : items.length - 1)
                              : Math.max(0, Math.min(items.length - 1, kbIdx + dir));
        setKbFocus(items, next);
        return true;
    };

    window.folderGridOpen = function() {
        var items = getNavItems();
        if (kbIdx < 0 || kbIdx >= items.length) return false;
        var href = items[kbIdx].href;
        if (splitMode) {
            var qs = href.indexOf('?') >= 0 ? href.substring(href.indexOf('?') + 1) : '';
            var hp = {};
            qs.split('&').forEach(function(pair) {
                var idx = pair.indexOf('=');
                if (idx > 0) hp[decodeURIComponent(pair.substring(0, idx))] = decodeURIComponent(pair.substring(idx + 1));
            });
            var fp = hp['file'];
            var textExts = ['txt','csv','json','log','md'];
            if (fp && textExts.indexOf(fp.split('.').pop().toLowerCase()) !== -1) {
                var p = new URLSearchParams(window.location.search);
                p.set('p2', fp);
                window.location.href = '?' + p.toString();
                return true;
            }
        }
        window.location.href = href;
        return true;
    };

    // Reset highlight on click
    document.addEventListener('click', function(e) {
        if (e.target.closest && (e.target.closest('.folder-card') || e.target.closest('.gallery-item'))) {
            kbIdx = -1;
        }
    });
})();

document.addEventListener('keydown', function(e) {
    // Never hijack keys while editing or typing in any input/textarea
    if (isEditing || e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

    if (shortcutsModal.classList.contains('open')) {
        if (e.key === 'Escape') closeShortcuts();
        return;
    }

    // 1 / 2 — pane control: 1 = single pane, 2 = dual pane (P2)
    if ((e.key === '1' || e.key === '2') && !e.metaKey && !e.ctrlKey && !e.altKey) {
        var wantSplit = e.key === '2';
        if (wantSplit !== splitMode) { e.preventDefault(); toggleSplit(); }
        return;
    }

    // [ / ] — history back / forward
    if ((e.key === '[' || e.key === ']') && !e.metaKey && !e.ctrlKey && !e.altKey) {
        e.preventDefault();
        if (e.key === '[') {
            if (window._navBack && window._navBack()) return;
            // Fallback: go up one folder level if no history
            var backLink = document.querySelector('.sidebar-header a[href]');
            if (backLink && backLink.textContent.trim() === 'Back') {
                window.location.href = backLink.getAttribute('href');
            }
        } else {
            if (window._navFwd) window._navFwd();
        }
        return;
    }

    // e — enter edit mode
    if (e.key === 'e' && !e.metaKey && !e.ctrlKey && !e.altKey) {
        if (currentFilePath && (currentDisplayType === 'text' || currentDisplayType === 'markdown')) {
            e.preventDefault();
            toggleEdit();
        }
        return;
    }

    // u — toggle between open file and folder file menu
    if (e.key === 'u' && !e.metaKey && !e.ctrlKey && !e.altKey) {
        e.preventDefault();
        var p = new URLSearchParams(window.location.search);
        var hasFile = p.has('file');
        var hasFolder = p.has('folder');
        if (hasFile) {
            // Save current file URL, go to folder menu
            sessionStorage.setItem('uMenuReturnUrl', window.location.href);
            p.delete('file');
            p.delete('view');
            window.location.href = '?' + p.toString();
        } else {
            // Return to last file (or try sessionStorage)
            var ret = sessionStorage.getItem('uMenuReturnUrl');
            if (ret) {
                sessionStorage.removeItem('uMenuReturnUrl');
                window.location.href = ret;
            }
        }
        return;
    }

    // Grid/gallery navigation: 9 = prev (left), 0 = next (right), Enter/- = open focused item
    if (!e.metaKey && !e.ctrlKey && !e.altKey) {
        var hasGrid = document.querySelector('.folder-grid, .gallery') !== null;
        if (hasGrid) {
            if (e.key === '0') { if (window.folderGridNav(1)) { e.preventDefault(); return; } }
            if (e.key === '9') { if (window.folderGridNav(-1)) { e.preventDefault(); return; } }
            if (e.key === '8') { if (window.folderGridNav(5)) { e.preventDefault(); return; } }
            if (e.key === '7') { if (window.folderGridNav(-5)) { e.preventDefault(); return; } }
            if ((e.key === 'Enter' || e.key === '-') && window.folderGridOpen()) { e.preventDefault(); return; }
        } else {
            // No grid — file is open: 9/0 act as prev/next file
            if ((e.key === '9' || e.key === '0')) {
                var fileNavBtns = document.querySelectorAll('.sidebar-nav .sidebar-nav-btn');
                if (fileNavBtns.length) {
                    var fileNavBtn = e.key === '9' ? fileNavBtns[0] : fileNavBtns[fileNavBtns.length - 1];
                    if (fileNavBtn && fileNavBtn.tagName === 'A' && fileNavBtn.getAttribute('href')) {
                        e.preventDefault();
                        if (splitMode) {
                            // Mirror the ArrowLeft/ArrowRight P2 routing logic
                            var destHref = fileNavBtn.getAttribute('href');
                            var destQs = destHref.indexOf('?') >= 0 ? destHref.substring(destHref.indexOf('?') + 1) : '';
                            var destParams = {};
                            destQs.split('&').forEach(function(pair) {
                                var idx = pair.indexOf('=');
                                if (idx > 0) destParams[decodeURIComponent(pair.substring(0, idx))] = decodeURIComponent(pair.substring(idx + 1));
                            });
                            var destFile = destParams['file'];
                            var textExts = ['txt','csv','json','log','md'];
                            if (destFile && textExts.indexOf(destFile.split('.').pop().toLowerCase()) !== -1) {
                                var p = new URLSearchParams(window.location.search);
                                if (p.get('p2') === destFile) {
                                    // Txt already in right pane — advance left pane to next/prev image
                                    var curFile = p.get('file') || '';
                                    var curImgIdx = -1;
                                    for (var ii = 0; ii < imageList.length; ii++) {
                                        var iuParams = new URLSearchParams((imageList[ii].url.split('?')[1]) || '');
                                        if (iuParams.get('file') === curFile) { curImgIdx = ii; break; }
                                    }
                                    var nextImgIdx = curImgIdx + (e.key === '0' ? 1 : -1);
                                    if (nextImgIdx >= 0 && nextImgIdx < imageList.length) {
                                        window.location.href = imageList[nextImgIdx].url;
                                    }
                                    return;
                                }
                                // Text file → load into right pane, keep current left pane file
                                p.set('p2', destFile);
                                window.location.href = '?' + p.toString();
                                return;
                            }
                        }
                        // Media file (or P2 closed) → normal left-pane navigation
                        window.location.href = fileNavBtn.href;
                        return;
                    }
                }
            }
        }
    }

    if (modal.classList.contains('open')) {
        if (e.key === 'Escape') closeModal();
        else if (e.key === 'ArrowLeft') modalNav(-1);
        else if (e.key === 'ArrowRight') modalNav(1);
    } else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
        // Navigate prev/next file via sidebar nav buttons
        var navBtns = document.querySelectorAll('.sidebar-nav .sidebar-nav-btn');
        if (!navBtns.length) return;
        var btn = e.key === 'ArrowLeft' ? navBtns[0] : navBtns[navBtns.length - 1];
        if (btn && btn.tagName === 'A' && btn.getAttribute('href')) {
            e.preventDefault();
            if (splitMode) {
                // When P2 is open, route text/md files to the right pane
                var destHref = btn.getAttribute('href');
                var destQs = destHref.indexOf('?') >= 0 ? destHref.substring(destHref.indexOf('?') + 1) : '';
                var destParams = {};
                destQs.split('&').forEach(function(pair) {
                    var idx = pair.indexOf('=');
                    if (idx > 0) destParams[decodeURIComponent(pair.substring(0, idx))] = decodeURIComponent(pair.substring(idx + 1));
                });
                var destFile = destParams['file'];
                var textExts = ['txt','csv','json','log','md'];
                if (destFile && textExts.indexOf(destFile.split('.').pop().toLowerCase()) !== -1) {
                    var p = new URLSearchParams(window.location.search);
                    if (p.get('p2') === destFile) {
                        // Txt already in right pane — advance left pane to next/prev image
                        var curFile = p.get('file') || '';
                        var curImgIdx = -1;
                        for (var ii = 0; ii < imageList.length; ii++) {
                            var iuParams = new URLSearchParams((imageList[ii].url.split('?')[1]) || '');
                            if (iuParams.get('file') === curFile) { curImgIdx = ii; break; }
                        }
                        var nextImgIdx = curImgIdx + (e.key === 'ArrowRight' ? 1 : -1);
                        if (nextImgIdx >= 0 && nextImgIdx < imageList.length) {
                            window.location.href = imageList[nextImgIdx].url;
                        }
                        return;
                    }
                    // Text file → load into right pane, keep current left pane file
                    p.set('p2', destFile);
                    window.location.href = '?' + p.toString();
                    return;
                }
            }
            // Media file (or P2 closed) → normal left-pane navigation
            window.location.href = btn.href;
        }
    } else if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
        // Scroll text content area by one page (like PageUp/PageDown)
        var scrollTarget = null;
        var paneRightEl = document.getElementById('paneRight');
        var contentAreaEl = document.getElementById('contentArea');
        if (splitMode && paneRightEl && paneRightEl.style.display !== 'none' && paneRightEl.scrollHeight > paneRightEl.clientHeight) {
            scrollTarget = paneRightEl;
        } else if (contentAreaEl && contentAreaEl.scrollHeight > contentAreaEl.clientHeight) {
            scrollTarget = contentAreaEl;
        }
        if (scrollTarget) {
            e.preventDefault();
            var pageAmt = scrollTarget.clientHeight * 0.85;
            scrollTarget.scrollBy({ top: e.key === 'ArrowDown' ? pageAmt : -pageAmt, behavior: 'smooth' });
        }
    } else if (audioModal.classList.contains('open')) {
        if (e.key === 's') {
            e.preventDefault();
            if (audioPlayer.paused) audioPlayer.play(); else audioPlayer.pause();
        } else if (e.key === 'v') {
            e.preventDefault();
            openAudioJumpModal();
        } else if (e.key === 'c') {
            // Jump to highlighted (selected) text — accepts M:SS, H:MM:SS, or bare seconds
            e.preventDefault();
            var sel = window.getSelection();
            var text = sel ? sel.toString().trim() : '';
            if (text && /^(\d+:\d{2}(:\d{2})?|\d+)$/.test(text)) {
                audioPlayer.currentTime = parseAudioTime(text);
                audioPlayer.play();
            }
        }
    }

    // TTS — works anywhere (not editing, no modifier keys)
    if (!e.metaKey && !e.ctrlKey && !e.altKey) {
        if (e.key === 'a' || e.key === 'm' || e.key === 'h' || e.key === 'k' || e.key === 'f' || e.key === 'r') {
            var ttsSel = window.getSelection();
            var ttsText = ttsSel ? ttsSel.toString().trim() : '';
            if (ttsText) {
                e.preventDefault();
                var ttsLangMap = { a: 'zh-HK', m: 'zh-CN', h: 'es-ES', k: 'ko-KR', f: 'fr-FR', r: 'en-US' };
                speakSelection(ttsText, ttsLangMap[e.key]);
            }
        }

        // o — page up, p — page down; TTS Spanish still fires when text is selected (handled above)
        if (e.key === 'p' || e.key === 'o') {
            var pSel = window.getSelection();
            if (!pSel || !pSel.toString().trim()) {
                var pScrollTarget = null;
                var pPaneRight = document.getElementById('paneRight');
                var pContentArea = document.getElementById('contentArea');
                if (splitMode && pPaneRight && pPaneRight.style.display !== 'none' && pPaneRight.scrollHeight > pPaneRight.clientHeight) {
                    pScrollTarget = pPaneRight;
                } else if (pContentArea && pContentArea.scrollHeight > pContentArea.clientHeight) {
                    pScrollTarget = pContentArea;
                }
                if (pScrollTarget) {
                    e.preventDefault();
                    pScrollTarget.scrollBy({ top: (e.key === 'p' ? 1 : -1) * pScrollTarget.clientHeight * 0.85, behavior: 'smooth' });
                }
            }
        }

        // y — seek YouTube embed to highlighted time (M:SS, H:MM:SS, or bare seconds)
        if (e.key === 'y' && ytEmbedModal.classList.contains('open')) {
            var ytSel = window.getSelection();
            var ytText = ytSel ? ytSel.toString().trim() : '';
            if (ytText && /^(\d+:\d{2}(:\d{2})?|\d+)$/.test(ytText)) {
                e.preventDefault();
                ytEmbedSeekTo(parseAudioTime(ytText));
            }
        }
    }

    // , / . — navigate prev/next line in text content (useful for CSV row-by-row reading)
    if ((e.key === ',' || e.key === '.') && !e.metaKey && !e.ctrlKey && !e.altKey) {
        var lnSel = window.getSelection();
        var lnTextEl = null;
        var lnStartOff = 0;
        var lnHasSelection = lnSel && lnSel.rangeCount && lnSel.toString().length > 0;

        if (lnHasSelection) {
            // Walk up from selection to find text container
            var lnRange = lnSel.getRangeAt(0);
            var lnNode = lnRange.startContainer.nodeType === 3
                ? lnRange.startContainer.parentElement
                : lnRange.startContainer;
            while (lnNode) {
                if (lnNode.classList && (lnNode.classList.contains('text-content') || lnNode.classList.contains('markdown-content'))) {
                    lnTextEl = lnNode;
                    break;
                }
                lnNode = lnNode.parentElement;
            }
        }

        // No selection (or selection outside text) — find the visible text container and start at line 0
        if (!lnTextEl) {
            lnTextEl = document.querySelector('.pane-right .text-content, .pane-right .markdown-content, .content-area .text-content, .content-area .markdown-content');
            if (!lnTextEl) return;
            lnHasSelection = false; // force start at line 0
        }

        // Calculate char offset of selection start within lnTextEl
        function lnGetOffset(root, targetNode, targetOff) {
            var pos = 0;
            function walk(n) {
                if (n === targetNode) { pos += targetOff; return true; }
                if (n.nodeType === 3) { pos += n.textContent.length; return false; }
                for (var i = 0; i < n.childNodes.length; i++) { if (walk(n.childNodes[i])) return true; }
                return false;
            }
            walk(root);
            return pos;
        }

        var lnFullText = lnTextEl.textContent;
        var lnLines = lnFullText.split('\n');

        // If no prior selection, treat current line as -1 so '.' starts at 0
        var lnCurLine = -1;
        if (lnHasSelection) {
            var lnRange = lnSel.getRangeAt(0);
            lnStartOff = lnGetOffset(lnTextEl, lnRange.startContainer, lnRange.startOffset);
            var lnCharCount = 0;
            for (var li = 0; li < lnLines.length; li++) {
                var lnLen = lnLines[li].length + 1;
                if (lnStartOff < lnCharCount + lnLen) { lnCurLine = li; break; }
                lnCharCount += lnLen;
                lnCurLine = li;
            }
        }

        var lnTarget = e.key === ',' ? lnCurLine - 1 : lnCurLine + 1;
        if (lnTarget < 0 || lnTarget >= lnLines.length) return;

        e.preventDefault();

        // Compute char start/end for target line
        var lnTargetStart = 0;
        for (var li2 = 0; li2 < lnTarget; li2++) lnTargetStart += lnLines[li2].length + 1;
        var lnTargetEnd = lnTargetStart + lnLines[lnTarget].length;

        // Build a DOM range spanning the target line
        function lnMakeRange(root, startChar, endChar) {
            var pos = 0, sNode = null, sOff = 0, eNode = null, eOff = 0;
            function walk(n) {
                if (sNode && eNode) return;
                if (n.nodeType === 3) {
                    var len = n.textContent.length;
                    if (!sNode && pos + len > startChar) { sNode = n; sOff = startChar - pos; }
                    if (!eNode && pos + len >= endChar)  { eNode = n; eOff = endChar - pos; }
                    pos += len;
                } else {
                    for (var i = 0; i < n.childNodes.length; i++) {
                        walk(n.childNodes[i]);
                        if (sNode && eNode) return;
                    }
                }
            }
            walk(root);
            if (!sNode) return null;
            if (!eNode) { eNode = sNode; eOff = sNode.textContent.length; }
            var r = document.createRange();
            r.setStart(sNode, sOff);
            r.setEnd(eNode, eOff);
            return r;
        }

        var lnNewRange = lnMakeRange(lnTextEl, lnTargetStart, lnTargetEnd);
        if (!lnNewRange) return;
        lnSel.removeAllRanges();
        lnSel.addRange(lnNewRange);

        // Scroll highlighted line into view
        var lnSpan = document.createElement('span');
        lnNewRange.insertNode(lnSpan);
        lnSpan.scrollIntoView({ behavior: 'smooth', block: 'center' });
        lnSpan.parentNode.removeChild(lnSpan);
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
/* YouTube modal */
var ytModal = document.getElementById('ytModal');
var ytTracks = [
    { id: 'rdoq4yi9cV0', title: '4 Classical Pieces | Relaxing Piano [15min]', duration: 900 },
    { id: 'oPEBWXvo1Xc', title: '4 Pieces by Yiruma | Relaxing Piano [15min]', duration: 900 },
    { id: 'mdJU5ogrPMY', title: 'Classical Music for Studying (2 hrs)', duration: 7200 }
];
var ytCurrentIdx = -1;

function toggleYtModal() {
    if (ytModal.classList.contains('open')) {
        ytModal.classList.remove('open');
    } else {
        ytModal.classList.add('open');
        if (ytCurrentIdx < 0) { buildYtTrackList(); loadYtTrack(0); }
    }
}

function buildYtTrackList() {
    var list = document.getElementById('ytTrackList');
    list.innerHTML = '';
    ytTracks.forEach(function(t, i) {
        var btn = document.createElement('button');
        btn.className = 'yt-track-btn';
        btn.textContent = t.title;
        btn.addEventListener('click', function() { loadYtTrack(i); });
        list.appendChild(btn);
    });
}

function loadYtTrack(idx, startSec) {
    ytCurrentIdx = idx;
    var t = ytTracks[idx];
    var src = 'https://www.youtube.com/embed/' + t.id + '?autoplay=1';
    if (startSec) {
        src += '&start=' + startSec;
        var m = Math.floor(startSec / 60);
        var s = startSec % 60;
        document.getElementById('ytTitle').textContent = t.title + ' — jumping to ' + m + ':' + (s < 10 ? '0' : '') + s;
    } else {
        document.getElementById('ytTitle').textContent = t.title;
    }
    document.getElementById('ytIframeWrap').innerHTML =
        '<iframe src="' + src + '" allow="autoplay; encrypted-media" allowfullscreen></iframe>';
    var btns = document.querySelectorAll('.yt-track-btn');
    btns.forEach(function(b, i) {
        b.className = 'yt-track-btn' + (i === idx ? ' active' : '');
    });
}

function ytRandomSeek() {
    if (ytCurrentIdx < 0) return;
    var t = ytTracks[ytCurrentIdx];
    var randomSec = Math.floor(Math.random() * (t.duration - 30));
    loadYtTrack(ytCurrentIdx, randomSec);
}

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

function audioSeek(seconds) {
    if (!audioPlayer.duration || isNaN(audioPlayer.duration)) return;
    var newTime = audioPlayer.currentTime + seconds;
    audioPlayer.currentTime = Math.max(0, Math.min(audioPlayer.duration, newTime));
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

// =====================================================================
// SHORTCUTS REFERENCE — edit the template literal below freely.
// Rendered as Markdown. Reload the page after saving to see changes.
// =====================================================================
var shortcutsContent = `
## Navigation
- **← / →** — Previous / next file in sidebar
- **↑ / ↓** or **p** — Page up / page down in text content (P2 right pane when active); p scrolls down only
- **u** — Toggle between open file and folder file menu
- **Esc** — Close any open modal

## Folder / File Grid
- **0** — Move highlight right (next item)
- **9** — Move highlight left (previous item)
- **8** — Jump forward 5 items
- **7** — Jump back 5 items
- **Enter** or **-** — Open highlighted folder or file
- **[** — Go back (up one folder level)

## File Open (no grid)
- **0** — Next file (→)
- **9** — Previous file (←)

## Image Modal
- **← / →** — Prev / next image
- **Esc** — Close

## Audio Player  *(open with ♩ button)*
- **s** — Play / pause toggle
- **v** — Open time-jump modal (type \`1:23\` or \`90\` → Enter/v to jump)
- **c** — Jump to highlighted/selected time in text (no modal)

## Dual Pane (P2)
- **P2 button** — Toggle left/right split view
- **1** — Single pane (close P2)
- **2** — Dual pane (open P2)
- Right pane shows text/md files; left pane shows media

## Text / Markdown
- **+  /  −** — Increase / decrease font size
- **↦** — Toggle reading margins
- **TXT>MD** — Render plain .txt as Markdown
- **e** — Enter edit mode (✎ button)
- **Esc** — Save & exit edit mode
- **📋** — Copy raw content
- **,  /  .** — Select previous / next line; starts at line 1 if nothing highlighted (great for CSV row-by-row)

## YouTube Embed  *(open with **YT** button)*
- Paste any YouTube URL → Enter or **▶ Load**
- **y** — Seek to highlighted time in text (e.g. highlight \`1:23\` and press y)

## Text-to-Speech (highlight any text first)
- **a** — Read selection in Cantonese (zh-HK)
- **m** — Read selection in Mandarin (zh-CN)
- **h** — Read selection in Spanish (es-ES)
- **k** — Read selection in Korean (ko-KR)
- **f** — Read selection in French (fr-FR)
- **r** — Read selection in English (en-US)

## General
- **☽ / ☀** — Toggle dark / light mode


`;
// =====================================================================

var shortcutsModal = document.getElementById('shortcutsModal');
var shortcutsBody  = document.getElementById('shortcutsBody');

function openShortcuts() {
    shortcutsBody.innerHTML = (typeof marked !== 'undefined') ? marked.parse(shortcutsContent) : '<pre>' + shortcutsContent + '</pre>';
    shortcutsModal.classList.add('open');
}
function closeShortcuts() {
    shortcutsModal.classList.remove('open');
}
shortcutsModal.addEventListener('click', function(e) {
    if (e.target === shortcutsModal) closeShortcuts();
});

// --- Serve panel ---
var servePanel = document.getElementById('servePanel');
function openServePanel() {
    updateServeCmd();
    updateRcloneCmd();
    servePanel.classList.add('open');
}
function closeServePanel() {
    servePanel.classList.remove('open');
}
function updateServeCmd() {
    var host = document.getElementById('svHost').value || '0.0.0.0';
    var port = document.getElementById('svPort').value || '8080';
    var path = document.getElementById('svPath').value || '.';
    document.getElementById('svCmd').textContent = 'cd ' + path + ' && php -S ' + host + ':' + port;
}
function copyServeCmd() {
    var cmd = document.getElementById('svCmd').textContent;
    var btn = document.getElementById('svCopyBtn');
    var reset = function() {
        btn.textContent = 'Copy command';
        btn.style.background = 'rgb(52,168,83)';
    };
    var onSuccess = function() {
        btn.textContent = '✓ Copied!';
        btn.style.background = '#388e3c';
        setTimeout(reset, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(cmd).then(onSuccess).catch(function() {
            var ta = document.createElement('textarea');
            ta.value = cmd; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.focus(); ta.select();
            if (document.execCommand('copy')) onSuccess();
            document.body.removeChild(ta);
        });
    } else {
        var ta = document.createElement('textarea');
        ta.value = cmd; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.focus(); ta.select();
        if (document.execCommand('copy')) onSuccess();
        document.body.removeChild(ta);
    }
}

var rcloneMode = 'copy';
function setRcloneMode(mode) {
    rcloneMode = mode;
    document.getElementById('svRcloneCopy').classList.toggle('sv-active', mode === 'copy');
    document.getElementById('svRcloneSync').classList.toggle('sv-active', mode === 'sync');
    updateRcloneCmd();
}
function updateRcloneCmd() {
    var path = document.getElementById('svPath').value || '.';
    var remote = document.getElementById('svRcloneRemote').value || 'dropbox:macbook';
    var localPath = currentFolderPath ? path + '/' + currentFolderPath : path;
    var remotePath = currentFolderPath ? (remote.endsWith(':') ? remote + currentFolderPath : remote + '/' + currentFolderPath) : remote;
    document.getElementById('svRcloneCmd').textContent = 'rclone ' + rcloneMode + ' "' + localPath + '" ' + remotePath;
}
function copyRcloneCmd() {
    var cmd = document.getElementById('svRcloneCmd').textContent;
    var btn = document.getElementById('svRcloneCopyBtn');
    var reset = function() {
        btn.textContent = 'Copy command';
        btn.style.background = 'rgb(52,168,83)';
    };
    var onSuccess = function() {
        btn.textContent = '✓ Copied!';
        btn.style.background = '#388e3c';
        setTimeout(reset, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(cmd).then(onSuccess).catch(function() {
            var ta = document.createElement('textarea');
            ta.value = cmd; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.focus(); ta.select();
            if (document.execCommand('copy')) onSuccess();
            document.body.removeChild(ta);
        });
    } else {
        var ta = document.createElement('textarea');
        ta.value = cmd; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.focus(); ta.select();
        if (document.execCommand('copy')) onSuccess();
        document.body.removeChild(ta);
    }
}

// --- Text-to-Speech (m = Mandarin, a = Cantonese) ---
var ttsVoices = [];
function ttsLoadVoices() { ttsVoices = speechSynthesis.getVoices(); }
ttsLoadVoices();
if (speechSynthesis.onvoiceschanged !== undefined) {
    speechSynthesis.addEventListener('voiceschanged', ttsLoadVoices);
}

function ttsBestVoice(lang) {
    var voices = ttsVoices.length ? ttsVoices : speechSynthesis.getVoices();
    var bad = ['Eddy', 'Flo', 'Grandma', 'Grandpa'];
    var notBad = function(v) { return !bad.some(function(b) { return v.name.includes(b); }); };
    var has = function(v, tag) { return v.lang.toLowerCase().startsWith(tag.toLowerCase()); };

    if (lang === 'zh-CN') {
        var preferred = ['Li-Mu', 'Tingting', 'Ting-Ting', 'Mei-Jia', 'Yaoyao', 'Kangkang', 'Huihui', 'Sin-ji'];
        return (
            voices.find(function(v) { return has(v, 'zh-CN') && v.name.includes('Google') && notBad(v); }) ||
            voices.find(function(v) { return has(v, 'zh-CN') && (v.name.includes('Enhanced') || v.name.includes('Premium')) && notBad(v); }) ||
            (function() { for (var i = 0; i < preferred.length; i++) { var f = voices.find(function(v) { return has(v, 'zh-CN') && v.name.includes(preferred[i]); }); if (f) return f; } return null; })() ||
            voices.find(function(v) { return has(v, 'zh-CN') && notBad(v); }) ||
            voices.find(function(v) { return has(v, 'zh-CN'); }) ||
            null
        );
    } else if (lang === 'es-ES') {
        return (
            voices.find(function(v) { return has(v, 'es') && v.name.includes('Google'); }) ||
            voices.find(function(v) { return has(v, 'es') && (v.name.includes('Enhanced') || v.name.includes('Premium')); }) ||
            voices.find(function(v) { return has(v, 'es-ES'); }) ||
            voices.find(function(v) { return has(v, 'es'); }) ||
            null
        );
    } else if (lang === 'ko-KR') {
        return (
            voices.find(function(v) { return has(v, 'ko') && v.name.includes('Google'); }) ||
            voices.find(function(v) { return has(v, 'ko') && (v.name.includes('Enhanced') || v.name.includes('Premium')); }) ||
            voices.find(function(v) { return has(v, 'ko'); }) ||
            null
        );
    } else if (lang === 'fr-FR') {
        var preferredFR = ['Amelie', 'Thomas', 'Virginie', 'Audrey', 'Marie', 'Paul'];
        return (
            voices.find(function(v) { return has(v, 'fr') && v.name.includes('Google'); }) ||
            voices.find(function(v) { return has(v, 'fr') && (v.name.includes('Enhanced') || v.name.includes('Premium')); }) ||
            (function() { for (var i = 0; i < preferredFR.length; i++) { var f = voices.find(function(v) { return has(v, 'fr') && v.name.includes(preferredFR[i]); }); if (f) return f; } return null; })() ||
            voices.find(function(v) { return has(v, 'fr'); }) ||
            null
        );
    } else if (lang === 'en-US') {
        return (
            voices.find(function(v) { return has(v, 'en') && v.name.includes('Google') && notBad(v); }) ||
            voices.find(function(v) { return has(v, 'en-US') && (v.name.includes('Enhanced') || v.name.includes('Premium')) && notBad(v); }) ||
            voices.find(function(v) { return has(v, 'en-US') && notBad(v); }) ||
            voices.find(function(v) { return has(v, 'en') && notBad(v); }) ||
            voices.find(function(v) { return has(v, 'en'); }) ||
            null
        );
    } else { // zh-HK
        var preferredHK = ['Sin-ji', 'Sinji', 'Hong Kong'];
        return (
            voices.find(function(v) { return has(v, 'zh-HK') && v.name.includes('Google'); }) ||
            voices.find(function(v) { return has(v, 'zh-HK') && (v.name.includes('Enhanced') || v.name.includes('Premium')); }) ||
            (function() { for (var i = 0; i < preferredHK.length; i++) { var f = voices.find(function(v) { return has(v, 'zh-HK') && v.name.includes(preferredHK[i]); }); if (f) return f; } return null; })() ||
            voices.find(function(v) { return has(v, 'zh-HK'); }) ||
            null
        );
    }
}

function speakSelection(text, lang) {
    speechSynthesis.cancel();
    var utt = new SpeechSynthesisUtterance(text);
    utt.lang = lang;
    var voice = ttsBestVoice(lang);
    if (voice) utt.voice = voice;
    speechSynthesis.speak(utt);
}

// --- TTS selection tooltip ---
(function() {
    var TTS_LANGS = [
        { key: 'A', label: 'A 粵', lang: 'zh-HK', title: 'Cantonese' },
        { key: 'M', label: 'M 普', lang: 'zh-CN', title: 'Mandarin'  },
        { key: 'E', label: 'E En', lang: 'en-US', title: 'English'   },
        { key: 'P', label: 'P Es', lang: 'es-ES', title: 'Spanish'   },
        { key: 'K', label: 'K 한', lang: 'ko-KR', title: 'Korean'    },
        { key: 'F', label: 'F Fr', lang: 'fr-FR', title: 'French'    },
    ];
    var STORAGE_KEY = 'tts-visible-btns';

    function loadVisible() {
        try { return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {}; } catch(e) { return {}; }
    }
    function saveVisible(v) { localStorage.setItem(STORAGE_KEY, JSON.stringify(v)); }
    function isVisible(key) { var v = loadVisible(); return !(key in v) || v[key]; }

    var tooltip   = document.getElementById('ttsTooltip');
    var settingsEl = document.getElementById('ttsBtnSettings');
    var rowsEl    = document.getElementById('ttsBtnSettingsRows');

    function buildTooltip() {
        tooltip.innerHTML = '';
        var hasAny = false;
        TTS_LANGS.forEach(function(l) {
            if (!isVisible(l.key)) return;
            hasAny = true;
            var btn = document.createElement('button');
            btn.className = 'tts-tip-btn';
            btn.textContent = l.label;
            btn.title = l.title;
            btn.addEventListener('mousedown', function(e) {
                e.preventDefault(); // keep selection alive
                var sel = window.getSelection();
                var text = sel ? sel.toString().trim() : '';
                if (text) speakSelection(text, l.lang);
                hideTooltip();
            });
            tooltip.appendChild(btn);
        });
        // Gear button
        var gear = document.createElement('button');
        gear.className = 'tts-tip-gear';
        gear.title = 'Choose visible languages';
        gear.textContent = '⚙';
        gear.addEventListener('mousedown', function(e) {
            e.preventDefault();
            e.stopPropagation();
            openSettings();
        });
        tooltip.appendChild(gear);
        return hasAny;
    }

    function showTooltip(x, y) {
        if (!buildTooltip()) return;
        tooltip.classList.add('open');
        // Position above selection, clamped to viewport
        var tw = tooltip.offsetWidth || 200;
        var th = tooltip.offsetHeight || 36;
        var left = Math.min(x, window.innerWidth - tw - 8);
        var top  = Math.max(y - th - 8, 8);
        tooltip.style.left = left + 'px';
        tooltip.style.top  = top  + 'px';
    }

    function hideTooltip() {
        tooltip.classList.remove('open');
    }

    // Show on mouse-up if text is selected inside a text container
    document.addEventListener('mouseup', function(e) {
        if (e.target.closest && e.target.closest('#ttsTooltip, #ttsBtnSettings')) return;
        setTimeout(function() {
            var sel = window.getSelection();
            var text = sel ? sel.toString().trim() : '';
            if (!text) { hideTooltip(); return; }
            // Only show inside text/markdown content
            var node = sel.anchorNode;
            var inText = false;
            while (node) {
                if (node.classList && (node.classList.contains('text-content') || node.classList.contains('markdown-content'))) {
                    inText = true; break;
                }
                node = node.parentNode;
            }
            if (!inText) { hideTooltip(); return; }
            showTooltip(e.clientX, e.clientY);
        }, 10);
    });

    // Hide on click outside
    document.addEventListener('mousedown', function(e) {
        if (e.target.closest && (e.target.closest('#ttsTooltip') || e.target.closest('#ttsBtnSettings'))) return;
        hideTooltip();
        closeSettings();
    });

    // --- Settings panel ---
    function buildSettingsRows() {
        rowsEl.innerHTML = '';
        TTS_LANGS.forEach(function(l) {
            var row = document.createElement('div');
            row.className = 'tbs-row';
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.id = 'tbs-' + l.key;
            cb.checked = isVisible(l.key);
            cb.addEventListener('change', function() {
                var v = loadVisible();
                v[l.key] = cb.checked;
                saveVisible(v);
            });
            var lbl = document.createElement('label');
            lbl.htmlFor = 'tbs-' + l.key;
            lbl.textContent = l.label + ' — ' + l.title;
            row.appendChild(cb);
            row.appendChild(lbl);
            rowsEl.appendChild(row);
        });
    }

    function openSettings() {
        buildSettingsRows();
        // Position near tooltip
        var tr = tooltip.getBoundingClientRect();
        settingsEl.style.left = tr.left + 'px';
        settingsEl.style.top  = (tr.bottom + 6) + 'px';
        settingsEl.classList.add('open');
    }

    function closeSettings() {
        settingsEl.classList.remove('open');
    }

    document.getElementById('ttsBtnSettingsClose').addEventListener('click', closeSettings);
})();

// --- Audio time-jump modal (v key) ---
function parseAudioTime(str) {
    var parts = str.trim().split(':').map(Number);
    if (parts.length === 3) return parts[0]*3600 + parts[1]*60 + parts[2];
    if (parts.length === 2) return parts[0]*60 + parts[1];
    return parts[0] || 0;
}

// --- YouTube Embed Modal ---
var ytEmbedModal = document.getElementById('ytEmbedModal');
var ytEmbedPlayerInst = null;   // YT.Player instance
var ytEmbedAPIReady = false;

// Called by the IFrame API script when it finishes loading
function onYouTubeIframeAPIReady() {
    ytEmbedAPIReady = true;
}

function toggleYtEmbedModal() {
    var open = ytEmbedModal.classList.toggle('open');
    var pads = [
        document.getElementById('contentArea'),
        document.getElementById('paneRight')
    ];
    pads.forEach(function(el) {
        if (el) el.style.paddingBottom = open ? '70vh' : '';
    });
    if (open) {
        setTimeout(function() { document.getElementById('ytEmbedInput').focus(); }, 60);
    }
}

function ytExtractId(url) {
    var m = url.match(/(?:youtu\.be\/|[?&]v=|\/embed\/)([A-Za-z0-9_-]{11})/);
    return m ? m[1] : null;
}

function loadYtEmbed() {
    var url = document.getElementById('ytEmbedInput').value.trim();
    if (!url) return;
    var id = ytExtractId(url);
    if (!id) { alert('Could not find a YouTube video ID in that URL'); return; }
    if (ytEmbedPlayerInst && typeof ytEmbedPlayerInst.loadVideoById === 'function') {
        ytEmbedPlayerInst.loadVideoById(id);
    } else if (typeof YT !== 'undefined' && YT.Player) {
        // Replace the placeholder div (gets consumed by YT.Player)
        document.getElementById('ytEmbedPlayerWrap').innerHTML = '<div id="ytEmbedPlayerEl"></div>';
        ytEmbedPlayerInst = new YT.Player('ytEmbedPlayerEl', {
            height: '100%', width: '100%',
            videoId: id,
            playerVars: { autoplay: 1, rel: 0, modestbranding: 1 }
        });
    } else {
        // API not ready yet — fall back to plain iframe
        document.getElementById('ytEmbedPlayerWrap').innerHTML =
            '<iframe src="https://www.youtube.com/embed/' + id + '?autoplay=1&rel=0"' +
            ' allow="autoplay;encrypted-media" allowfullscreen></iframe>';
    }
}

function ytEmbedSeekTo(seconds) {
    if (ytEmbedPlayerInst && typeof ytEmbedPlayerInst.seekTo === 'function') {
        ytEmbedPlayerInst.seekTo(seconds, true);
        ytEmbedPlayerInst.playVideo();
    }
}

// Enter key in URL input triggers load
document.getElementById('ytEmbedInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); loadYtEmbed(); }
});

var audioJumpModal = document.getElementById('audioJumpModal');
var audioJumpInput = document.getElementById('audioJumpInput');

function openAudioJumpModal() {
    audioJumpInput.value = '';
    audioJumpModal.classList.add('open');
    setTimeout(function() { audioJumpInput.focus(); }, 30);
}
function closeAudioJumpModal() {
    audioJumpModal.classList.remove('open');
    audioJumpInput.value = '';
}
function doAudioJump() {
    var val = audioJumpInput.value.trim();
    if (!val) { closeAudioJumpModal(); return; }
    audioPlayer.currentTime = parseAudioTime(val);
    audioPlayer.play();
    closeAudioJumpModal();
}

audioJumpInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.key === 'v') { e.preventDefault(); doAudioJump(); }
    if (e.key === 'Escape') { e.preventDefault(); closeAudioJumpModal(); }
});
audioJumpModal.addEventListener('click', function(e) {
    if (e.target === audioJumpModal) closeAudioJumpModal();
});

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

// --- Copy content ---
var rawContent = <?= ($displayType === 'text' || $displayType === 'markdown') ? json_encode($displayContent, JSON_HEX_TAG | JSON_HEX_AMP) : 'null' ?>;
function copyContent() {
    if (!rawContent) return;
    var btn = document.getElementById('copyBtn');
    var fallback = function(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        var ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok;
    };
    var onSuccess = function() {
        btn.innerHTML = '&#10003;';
        btn.style.background = '#4caf50';
        btn.style.color = '#fff';
        setTimeout(function() {
            btn.innerHTML = '&#128203;';
            btn.style.background = 'rgb(224,224,224)';
            btn.style.color = 'rgb(51,51,51)';
            if (document.body.classList.contains('dark')) {
                btn.style.background = '#555';
                btn.style.color = '#ffdd57';
            }
        }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(rawContent).then(onSuccess).catch(function() {
            if (fallback(rawContent)) onSuccess();
        });
    } else {
        if (fallback(rawContent)) onSuccess();
    }
}

// --- Search ---
(function() {
    var searchInput = document.getElementById('searchInput');
    var searchResults = document.getElementById('searchResults');
    var sidebarList = document.getElementById('sidebarList');
    var debounceTimer = null;

    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        var query = this.value.trim();
        if (!query) {
            searchResults.style.display = 'none';
            sidebarList.style.display = '';
            searchResults.innerHTML = '';
            return;
        }
        debounceTimer = setTimeout(function() { doSearch(query); }, 300);
    });

    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            this.value = '';
            searchResults.style.display = 'none';
            sidebarList.style.display = '';
            searchResults.innerHTML = '';
            this.blur();
        }
    });

    function doSearch(query) {
        fetch('?search=' + encodeURIComponent(query))
            .then(function(r) { return r.json(); })
            .then(function(items) {
                searchResults.innerHTML = '';
                if (items.length === 0) {
                    searchResults.innerHTML = '<div style="padding:12px 15px;color:#666;font-size:12px">No results found</div>';
                } else {
                    items.forEach(function(item) {
                        var a = document.createElement('a');
                        a.className = 'sidebar-item' + (item.type === 'folder' ? ' folder' : '');
                        a.target = '_blank';
                        if (item.type === 'folder') {
                            a.href = '?folder=' + encodeURIComponent(item.path) + '&sort=<?= $sortBy ?>';
                        } else {
                            var parentFolder = item.parent || '';
                            if (parentFolder) {
                                a.href = '?folder=' + encodeURIComponent(parentFolder) + '&file=' + encodeURIComponent(item.path) + '&sort=<?= $sortBy ?>';
                            } else {
                                a.href = '?file=' + encodeURIComponent(item.path) + '&sort=<?= $sortBy ?>';
                            }
                        }
                        var nameSpan = document.createElement('span');
                        nameSpan.textContent = item.name;
                        a.appendChild(nameSpan);
                        if (item.ext) {
                            var extSpan = document.createElement('span');
                            extSpan.className = 'file-ext';
                            extSpan.textContent = item.ext;
                            a.appendChild(extSpan);
                        }
                        if (item.parent) {
                            var parentSpan = document.createElement('span');
                            parentSpan.className = 'search-result-parent';
                            parentSpan.textContent = item.parent;
                            a.appendChild(parentSpan);
                        }
                        searchResults.appendChild(a);
                    });
                }
                searchResults.style.display = '';
                sidebarList.style.display = 'none';
            })
            .catch(function() {
                searchResults.innerHTML = '<div style="padding:12px 15px;color:#f44;font-size:12px">Search error</div>';
                searchResults.style.display = '';
                sidebarList.style.display = 'none';
            });
    }

    // Keyboard shortcut: Ctrl+F or / to focus search
    document.addEventListener('keydown', function(e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        if (e.key === '/' || (e.ctrlKey && e.key === 'f')) {
            e.preventDefault();
            searchInput.focus();
        }
    });
})();

// --- Rename ---
function renameFile() {
    if (!currentFilePath) return;
    var oldName = currentFilePath.split('/').pop();
    var newName = prompt('Rename file:', oldName);
    if (!newName || newName === oldName) return;
    fetch('?rename=' + encodeURIComponent(currentFilePath), {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({newName: newName})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) {
            // Navigate to renamed file
            var params = new URLSearchParams(window.location.search);
            params.set('file', data.newPath);
            window.location.search = params.toString();
        } else {
            alert('Rename failed: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(function(err) {
        alert('Rename error: ' + err.message);
    });
}

// --- Edit & Save ---
var isEditing = false;
var editTextarea = null;
var currentFilePath = <?= $currentFile ? json_encode($currentFile, JSON_HEX_TAG | JSON_HEX_AMP) : 'null' ?>;
var currentDisplayType = <?= json_encode($displayType) ?>;

function toggleEdit() {
    if (!currentFilePath || (currentDisplayType !== 'text' && currentDisplayType !== 'markdown')) return;
    var editBtn = document.getElementById('editBtn');
    if (!isEditing) {
        enterEditMode(editBtn);
    } else {
        saveAndExit(editBtn);
    }
}

function enterEditMode(editBtn) {
    isEditing = true;
    editBtn.innerHTML = '&#128190;'; // floppy disk icon
    editBtn.title = 'Save changes';
    editBtn.style.background = '#4caf50';
    editBtn.style.color = '#fff';

    var contentEl = currentDisplayType === 'markdown'
        ? document.getElementById('markdown-render')
        : document.querySelector('.text-content');
    if (!contentEl) return;

    editTextarea = document.createElement('textarea');
    editTextarea.value = rawContent;
    editTextarea.style.cssText = 'width:100%;min-height:80vh;padding:16px;font-family:monospace;font-size:14px;border:2px solid #4caf50;border-radius:8px;resize:vertical;box-sizing:border-box;background:#1e1e1e;color:#d4d4d4;line-height:1.6;tab-size:4;';
    editTextarea.addEventListener('keydown', function(e) {
        if (e.key === 'Tab') {
            e.preventDefault();
            var start = this.selectionStart;
            var end = this.selectionEnd;
            this.value = this.value.substring(0, start) + '\t' + this.value.substring(end);
            this.selectionStart = this.selectionEnd = start + 1;
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            saveAndExit(editBtn);
        }
    });
    contentEl.style.display = 'none';
    contentEl.parentNode.insertBefore(editTextarea, contentEl.nextSibling);
    editTextarea.focus();
}

function saveAndExit(editBtn) {
    if (!editTextarea) return;
    var newContent = editTextarea.value;
    editBtn.innerHTML = '&#8987;'; // hourglass
    editBtn.style.background = '#ff9800';

    fetch('?save=' + encodeURIComponent(currentFilePath), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ content: newContent })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) {
            rawContent = newContent;
            var contentEl = currentDisplayType === 'markdown'
                ? document.getElementById('markdown-render')
                : document.querySelector('.text-content');
            if (currentDisplayType === 'markdown') {
                contentEl.innerHTML = marked.parse(newContent);
                contentEl.querySelectorAll('pre code').forEach(function(block) {
                    hljs.highlightElement(block);
                });
            } else {
                contentEl.textContent = newContent;
            }
            contentEl.style.display = '';
            editTextarea.remove();
            editTextarea = null;
            isEditing = false;
            editBtn.innerHTML = '&#9998;';
            editBtn.title = 'Edit and save back to local file';
            editBtn.style.background = 'rgb(224,224,224)';
            editBtn.style.color = 'rgb(51,51,51)';
            if (document.body.classList.contains('dark')) {
                editBtn.style.background = '#555';
                editBtn.style.color = '#ffdd57';
            }
            // Flash green to confirm save
            editBtn.style.background = '#4caf50';
            editBtn.style.color = '#fff';
            editBtn.innerHTML = '&#10003;';
            setTimeout(function() {
                editBtn.innerHTML = '&#9998;';
                editBtn.style.background = 'rgb(224,224,224)';
                editBtn.style.color = 'rgb(51,51,51)';
                if (document.body.classList.contains('dark')) {
                    editBtn.style.background = '#555';
                    editBtn.style.color = '#ffdd57';
                }
            }, 1500);
        } else {
            alert('Save failed: ' + (data.error || 'Unknown error'));
            editBtn.innerHTML = '&#128190;';
            editBtn.style.background = '#4caf50';
            editBtn.style.color = '#fff';
        }
    })
    .catch(function(err) {
        alert('Save error: ' + err.message);
        editBtn.innerHTML = '&#128190;';
        editBtn.style.background = '#4caf50';
        editBtn.style.color = '#fff';
    });
}

// --- New File ---
var currentFolderPath = <?= $currentFolder ? json_encode($currentFolder, JSON_HEX_TAG | JSON_HEX_AMP) : "''" ?>;
function createNewFile() {
    navigator.clipboard.readText().then(function(clipText) {
        var fileName = prompt('New file name:', 'new_file.md');
        if (!fileName) return;
        var params = 'newfile=1';
        if (currentFolderPath) params += '&folder=' + encodeURIComponent(currentFolderPath);
        fetch('?' + params, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: fileName, content: clipText || '' })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                // Navigate to the new file
                var url = '?file=' + encodeURIComponent(data.path);
                if (currentFolderPath) url = '?folder=' + encodeURIComponent(currentFolderPath) + '&file=' + encodeURIComponent(data.path);
                url += '&sort=<?= $sortBy ?>';
                window.location.href = url;
            } else {
                alert('Failed: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(function(err) { alert('Error: ' + err.message); });
    }).catch(function() {
        // Clipboard access denied — create empty file
        var fileName = prompt('New file name (clipboard unavailable, file will be empty):', 'new_file.md');
        if (!fileName) return;
        var params = 'newfile=1';
        if (currentFolderPath) params += '&folder=' + encodeURIComponent(currentFolderPath);
        fetch('?' + params, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: fileName, content: '' })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                var url = '?file=' + encodeURIComponent(data.path);
                if (currentFolderPath) url = '?folder=' + encodeURIComponent(currentFolderPath) + '&file=' + encodeURIComponent(data.path);
                url += '&sort=<?= $sortBy ?>';
                window.location.href = url;
            } else {
                alert('Failed: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(function(err) { alert('Error: ' + err.message); });
    });
}

// --- Preserve sidebar scroll position across page loads ---
(function() {
    var sidebarList = document.getElementById('sidebarList');
    var key = 'sidebarScrollTop';
    var saved = sessionStorage.getItem(key);
    if (saved !== null) {
        sidebarList.scrollTop = parseInt(saved);
    }
    // Save scroll position before navigating away
    window.addEventListener('beforeunload', function() {
        sessionStorage.setItem(key, sidebarList.scrollTop);
    });
})();

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

// --- Dual-pane split mode ---
var splitMode = false;
try { splitMode = localStorage.getItem('splitMode') === '1'; } catch(e) {}

function applySplitMode() {
    var divider = document.getElementById('paneDivider');
    var paneRight = document.getElementById('paneRight');
    var btn = document.getElementById('splitBtn');
    if (splitMode) {
        divider.style.display = '';
        paneRight.style.display = '';
        btn.classList.add('split-active');
        btn.textContent = 'P2:ON';
    } else {
        divider.style.display = 'none';
        paneRight.style.display = 'none';
        btn.classList.remove('split-active');
        btn.textContent = 'P2';
    }
}
function toggleSplit() {
    splitMode = !splitMode;
    applySplitMode();
    try { localStorage.setItem('splitMode', splitMode ? '1' : '0'); } catch(e) {}
    if (!splitMode) {
        // Strip p2 from every link on the page so future navigation doesn't carry it
        document.querySelectorAll('a[href]').forEach(function(el) {
            var href = el.getAttribute('href');
            if (href.indexOf('p2=') === -1) return;
            try {
                var u = new URL(href, window.location.href);
                u.searchParams.delete('p2');
                el.setAttribute('href', u.pathname + (u.search ? u.search : ''));
            } catch(e) {}
        });
        // Also remove p2 from the current URL bar (no reload)
        var p = new URLSearchParams(window.location.search);
        if (p.has('p2')) {
            p.delete('p2');
            var newUrl = window.location.pathname + (p.toString() ? '?' + p.toString() : '');
            window.history.replaceState({}, '', newUrl);
        }
    }
}
function rightPanePageDown() {
    var el = document.getElementById('paneRight');
    el.scrollBy({ top: el.clientHeight * 0.9, behavior: 'smooth' });
}

// --- TXT>MD toggle helpers (cookie persists across ports on localhost) ---
function _getTxtMdCookie(pane) {
    var m = document.cookie.match('(?:^|; )txtMd_' + pane + '=([^;]*)');
    return m ? m[1] : null;
}
function _setTxtMdCookie(pane, val) {
    document.cookie = 'txtMd_' + pane + '=' + val + '; path=/; max-age=31536000';
}

// --- TXT>MD toggle (left pane) ---
// leftShowMd: true = showing markdown render, false = showing raw text
var leftShowMd = currentDisplayType === 'markdown'; // .md files default to MD view
var leftMdEl = null;  // dynamically created markdown div (for .txt files)
var leftTxtEl = null; // dynamically created text div (for .md files)

function applyLeftTxtMd(showMd) {
    var btn = document.getElementById('txtMdBtn');
    if (!btn || !rawContent) return;
    leftShowMd = showMd;
    if (currentDisplayType === 'text') {
        var textEl = document.querySelector('#contentArea .text-content');
        if (!textEl) return;
        if (showMd) {
            if (!leftMdEl) {
                leftMdEl = document.createElement('div');
                leftMdEl.className = 'markdown-content';
                textEl.parentNode.insertBefore(leftMdEl, textEl.nextSibling);
            }
            leftMdEl.innerHTML = marked.parse(rawContent);
            leftMdEl.querySelectorAll('pre code').forEach(function(b) { hljs.highlightElement(b); });
            leftMdEl.style.fontSize = document.getElementById('contentArea').style.fontSize || '';
            leftMdEl.style.display = '';
            textEl.style.display = 'none';
        } else {
            if (leftMdEl) leftMdEl.style.display = 'none';
            textEl.style.display = '';
        }
    } else if (currentDisplayType === 'markdown') {
        var mdEl = document.getElementById('markdown-render');
        if (!mdEl) return;
        if (!showMd) {
            if (!leftTxtEl) {
                leftTxtEl = document.createElement('div');
                leftTxtEl.className = 'text-content';
                leftTxtEl.textContent = rawContent;
                mdEl.parentNode.insertBefore(leftTxtEl, mdEl);
            }
            leftTxtEl.style.fontSize = document.getElementById('contentArea').style.fontSize || '';
            leftTxtEl.style.display = '';
            mdEl.style.display = 'none';
        } else {
            if (leftTxtEl) leftTxtEl.style.display = 'none';
            mdEl.style.display = '';
        }
    }
    // Green = MD mode, grey = text mode
    if (showMd) {
        btn.textContent = 'MD\u003ETXT';
        btn.style.background = '#4caf50'; btn.style.color = '#fff';
    } else {
        btn.textContent = 'TXT\u003EMD';
        btn.style.background = 'rgb(224,224,224)'; btn.style.color = 'rgb(51,51,51)';
    }
    _setTxtMdCookie('left', showMd ? 'md' : 'text');
}
function toggleLeftTxtMd() { applyLeftTxtMd(!leftShowMd); }

// Auto-apply saved preference on load (cookie shared across ports)
(function() {
    var pref = _getTxtMdCookie('left');
    var defaultMd = currentDisplayType === 'markdown';
    applyLeftTxtMd(pref ? pref === 'md' : defaultMd);
})();

// --- TXT>MD toggle (right pane) ---
var p2DisplayType = <?= json_encode($p2DisplayType) ?>;
var p2RawContent = <?= ($p2DisplayType === 'text' || $p2DisplayType === 'markdown') ? json_encode($p2DisplayContent, JSON_HEX_TAG | JSON_HEX_AMP) : 'null' ?>;
var rightShowMd = p2DisplayType === 'markdown';
var rightMdEl = null;
var rightTxtEl = null;

function applyRightTxtMd(showMd) {
    var btn = document.getElementById('p2TxtMdBtn');
    if (!btn || !p2RawContent) return;
    rightShowMd = showMd;
    if (p2DisplayType === 'text') {
        var textEl = document.querySelector('#paneRight .text-content');
        if (!textEl) return;
        if (showMd) {
            if (!rightMdEl) {
                rightMdEl = document.createElement('div');
                rightMdEl.className = 'markdown-content';
                textEl.parentNode.insertBefore(rightMdEl, textEl.nextSibling);
            }
            rightMdEl.innerHTML = marked.parse(p2RawContent);
            rightMdEl.querySelectorAll('pre code').forEach(function(b) { hljs.highlightElement(b); });
            rightMdEl.style.fontSize = document.getElementById('paneRight').style.fontSize || '';
            rightMdEl.style.display = '';
            textEl.style.display = 'none';
        } else {
            if (rightMdEl) rightMdEl.style.display = 'none';
            textEl.style.display = '';
        }
    } else if (p2DisplayType === 'markdown') {
        var mdEl = document.getElementById('p2-md-render');
        if (!mdEl) return;
        if (!showMd) {
            if (!rightTxtEl) {
                rightTxtEl = document.createElement('div');
                rightTxtEl.className = 'text-content';
                rightTxtEl.textContent = p2RawContent;
                mdEl.parentNode.insertBefore(rightTxtEl, mdEl);
            }
            rightTxtEl.style.fontSize = document.getElementById('paneRight').style.fontSize || '';
            rightTxtEl.style.display = '';
            mdEl.style.display = 'none';
        } else {
            if (rightTxtEl) rightTxtEl.style.display = 'none';
            mdEl.style.display = '';
        }
    }
    if (showMd) {
        btn.textContent = 'MD\u003ETXT';
        btn.style.background = '#4caf50'; btn.style.color = '#fff';
    } else {
        btn.textContent = 'TXT\u003EMD';
        btn.style.background = 'rgba(0,0,0,0.12)'; btn.style.color = '';
    }
    _setTxtMdCookie('right', showMd ? 'md' : 'text');
}
function toggleRightTxtMd() { applyRightTxtMd(!rightShowMd); }

// Auto-apply saved preference on load
(function() {
    var pref = _getTxtMdCookie('right');
    var defaultMd = p2DisplayType === 'markdown';
    if (document.getElementById('p2TxtMdBtn')) applyRightTxtMd(pref ? pref === 'md' : defaultMd);
})();

// Text-file gallery clicks → load into right pane when split is ON
(function() {
    var textExts = ['txt','csv','json','log','md'];
    document.querySelectorAll('.gallery-item a').forEach(function(item) {
        var href = item.getAttribute('href');
        if (!href || href.indexOf('?') < 0) return;
        var qs = href.substring(href.indexOf('?') + 1);
        var hp = {};
        qs.split('&').forEach(function(pair) {
            var idx = pair.indexOf('=');
            if (idx > 0) hp[decodeURIComponent(pair.substring(0, idx))] = decodeURIComponent(pair.substring(idx + 1));
        });
        var fp = hp['file'];
        if (!fp) return;
        var ext = fp.split('.').pop().toLowerCase();
        if (textExts.indexOf(ext) === -1) return;
        item.addEventListener('click', function(e) {
            if (!splitMode) return;
            e.preventDefault();
            var p = new URLSearchParams(window.location.search);
            p.set('p2', fp);
            window.location.href = '?' + p.toString();
        }, true);
    });
})();

// Text-file sidebar clicks → load into right pane when split is ON
(function() {
    var textExts = ['txt','csv','json','log','md'];
    document.querySelectorAll('.sidebar-item').forEach(function(item) {
        var href = item.getAttribute('href');
        if (!href || href.indexOf('?') < 0) return;
        var qs = href.substring(href.indexOf('?') + 1);
        var hp = {};
        qs.split('&').forEach(function(pair) {
            var idx = pair.indexOf('=');
            if (idx > 0) hp[decodeURIComponent(pair.substring(0, idx))] = decodeURIComponent(pair.substring(idx + 1));
        });
        var fp = hp['file'];
        if (!fp) return; // folder link
        var ext = fp.split('.').pop().toLowerCase();
        if (textExts.indexOf(ext) === -1) return; // not a text file
        item.addEventListener('click', function(e) {
            if (!splitMode) return; // let normal nav happen
            e.preventDefault();
            var p = new URLSearchParams(window.location.search);
            p.set('p2', fp);
            // Remove 'file' if it was the same text file in the left pane
            // (keep left pane on its current media file)
            window.location.href = '?' + p.toString();
        }, true);
    });
})();

applySplitMode();

// Highlight the P2 sidebar item (txt in right pane) with a distinct orange accent
(function() {
    var p2File = new URLSearchParams(window.location.search).get('p2');
    if (!p2File) return;
    document.querySelectorAll('.sidebar-item').forEach(function(item) {
        var href = item.getAttribute('href');
        if (!href) return;
        var hp = {};
        (href.indexOf('?') >= 0 ? href.substring(href.indexOf('?') + 1) : '').split('&').forEach(function(pair) {
            var idx = pair.indexOf('=');
            if (idx > 0) hp[decodeURIComponent(pair.substring(0, idx))] = decodeURIComponent(pair.substring(idx + 1));
        });
        if (hp['file'] === p2File) item.classList.add('p2-active');
    });
})();
</script>
<script src="https://www.youtube.com/iframe_api"></script>
</body>
</html>
