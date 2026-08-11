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

    // Notice client disconnects promptly — a held-open media stream otherwise
    // occupies the single-threaded PHP dev server forever and the app looks "stuck"
    ignore_user_abort(false);
    while (ob_get_level()) ob_end_clean();

    $fp = fopen($streamPath, 'rb');
    fseek($fp, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($fp)) {
        if (connection_aborted()) break;
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
$sortBy        = isset($_GET['sort'])   ? $_GET['sort']   : 'type';

// Text/markdown/docx files only ever render in the right pane (P2).
// Redirect any ?file=<text> deep link to ?p2=<text> (pane 1 keeps its context).
$p2Exts = ['txt','csv','json','log','md','docx','py'];
if ($currentFile && in_array(strtolower(pathinfo($currentFile, PATHINFO_EXTENSION)), $p2Exts)
    && file_exists($contentDir . '/' . $currentFile)) {
    $redir = $_GET;
    unset($redir['file']);
    $redir['p2'] = $currentFile;
    header('Location: ?' . http_build_query($redir, '', '&', PHP_QUERY_RFC3986));
    exit;
}

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
            $priority = ['txt'=>0,'md'=>1,'docx'=>2,'py'=>3];
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
            $priority = ['txt'=>0,'md'=>1,'docx'=>2,'py'=>3];
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
    return '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function itemUrl($params) {
    global $sortBy, $p2File;
    $params['sort'] = $sortBy;
    if (!empty($p2File) && !isset($params['p2'])) $params['p2'] = $p2File;
    return '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
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
        elseif ($ext === 'pgn')                       { $displayType = 'pgn';  $displayContent = file_get_contents($filePath); }
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
        if ($p2Ext === 'py')                    { $p2DisplayType = 'code';     $p2DisplayContent = file_get_contents($p2FilePath); }
        elseif ($p2Ext === 'md')                { $p2DisplayType = 'markdown'; $p2DisplayContent = file_get_contents($p2FilePath); }
        elseif ($p2Ext === 'docx')              { $p2DisplayType = 'docx';     $p2DisplayContent = $p2FilePath; }
        elseif (in_array($p2Ext, $p2TextExts))  { $p2DisplayType = 'text';     $p2DisplayContent = file_get_contents($p2FilePath); }
    }
}
$p2CloseParams = $_GET; unset($p2CloseParams['p2']);
$p2CloseUrl = $p2CloseParams ? '?' . http_build_query($p2CloseParams, '', '&', PHP_QUERY_RFC3986) : '?';

// File list (files-only, used for prev/next arrows, image modal)
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

// PGN list for inline chess viewer (prev/next PGN file cycling)
$pgnList = [];
foreach ($fileList as $f) {
    $fext = $f['ext'] ?? '';
    if ($fext === 'pgn') {
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $f['path'])));
        $pgnList[] = [
            'name' => $f['name'],
            'src'  => $contentDir . '/' . $encodedPath,
        ];
    }
}

// Text list (txt/md/docx) for ← → arrow-key navigation through prose files
$textList    = [];
$textNavExts = ['txt','md','docx','py'];
foreach ($fileList as $f) {
    $fext = $f['ext'] ?? '';
    if (in_array($fext, $textNavExts)) {
        $textList[] = ['name' => $f['name'], 'file' => $f['path']];
    }
}

// Current PGN index (for file-cycling position when viewing a .pgn file)
$currentPgnIdx = -1;
if ($displayType === 'pgn' && !empty($pgnList)) {
    foreach ($pgnList as $pi => $pEntry) {
        if ($pEntry['name'] === basename($currentFile)) { $currentPgnIdx = $pi; break; }
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
    position: fixed;
    left: -220px;
    top: 0;
    z-index: 999;
    transition: left 0.25s ease;
}
.sidebar.open { left: 0; }
.main { margin-left: 0; }
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

/* Sidebar toggle (legacy mobile button, kept hidden) */
.sidebar-toggle { display: none; }

/* Hamburger open button in main-header */
.sidebar-open-btn {
    background: none;
    border: none;
    padding: 5px;
    border-radius: 6px;
    cursor: pointer;
    color: #555;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.sidebar-open-btn:hover { background: #e8e8e8; }
body.dark .sidebar-open-btn { color: #ccc; }
body.dark .sidebar-open-btn:hover { background: #333; }

/* Sidebar close (X) button */
.sidebar-close-btn {
    background: none;
    border: none;
    color: #aaa;
    cursor: pointer;
    font-size: 20px;
    line-height: 1;
    padding: 0 2px;
    flex-shrink: 0;
}
.sidebar-close-btn:hover { color: #fff; }

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
.content-area { flex: 3; padding: 20px; overflow-y: auto; }

/* Dual-pane layout */
.panes-container { flex: 1; display: flex; overflow: hidden; }
.pane-divider {
    width: 6px; background: #ccc; flex-shrink: 0;
    cursor: col-resize; user-select: none;
    transition: background 0.15s;
}
.pane-divider:hover, .pane-divider.dragging { background: #888; }
body.dark .pane-divider { background: #444; }
body.dark .pane-divider:hover, body.dark .pane-divider.dragging { background: #888; }
.pane-right {
    flex: 5; overflow-y: auto; padding: 20px;
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
.code-content pre { margin: 0; overflow-x: auto; }
.code-content code.hljs { background: transparent; padding: 0; display: block; }
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
.gallery { display: grid; grid-template-columns: 1fr; gap: 15px; }
.gallery-item { text-align: center; }
.gallery-item img { width: 100%; cursor: pointer; border-radius: 4px; transition: transform 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.gallery-item img:hover { transform: scale(1.02); }
.gallery-item .caption { font-size: 12px; color: #666; margin-top: 5px; }

/* Stacked images */
.stacked-images { display: flex; flex-direction: column; gap: 10px; align-items: center; }
.stacked-images img { max-width: 100%; }
.stacked-images .p1-item { text-align: center; }
.stacked-images .p1-item img { cursor: pointer; display: block; border-radius: 4px; }
.stacked-images .p1-item.current img { outline: 2px solid #7ec8e3; }
.stacked-images .p1-item .caption { font-size: 12px; color: #666; margin-top: 5px; }
body.dark .stacked-images .p1-item .caption { color: #aaa; }

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

/* PGN viewer (inline in first pane) */
.pgn-inline { display: flex; flex-direction: column; align-items: center; width: 100%; padding: 8px 0 40px; }
.pgn-inline-inner { display: flex; gap: 24px; flex-wrap: wrap; align-items: flex-start; justify-content: center; max-width: 100%; }
.pgn-board-col { flex: 0 0 auto; }
.pgn-controls { margin-top: 10px; display: flex; gap: 8px; justify-content: center; }
.pgn-controls button { padding: 6px 14px; border-radius: 6px; border: 1px solid #555; background: #333; color: #ccc; cursor: pointer; font-size: 13px; }
.pgn-controls button:hover { background: #444; }
.pgn-controls button:disabled { opacity: 0.4; cursor: default; }
.pgn-comment { margin-top: 10px; max-width: 400px; min-height: 1.4em; font-size: 14px; font-style: italic; color: #b8860b; text-align: center; }
body.dark .pgn-comment { color: #ffd700; }
.pgn-moves { flex: 1; min-width: 280px; max-width: 450px; height: 242px; overflow-y: auto; background: #fff; color: #333; padding: 16px 20px; border-radius: 4px; font-size: 15px; line-height: 2; user-select: none; box-shadow: 0 2px 8px rgba(0,0,0,0.12); }
.pgn-moves .move-number { color: #888; }
.pgn-moves .move-san, .pgn-moves .variation-move { cursor: pointer; padding: 2px 4px; border-radius: 3px; margin-right: 4px; font-weight: 600; }
.pgn-moves .move-san:hover, .pgn-moves .variation-move:hover { background: #e6f2ff; }
.pgn-moves .current { background: #ffe58a; }
.pgn-moves .variation-move.current { background: #8b5cf6; color: #fff; }
.pgn-moves .variation-container { color: #888; }
.pgn-file-nav { display: flex; gap: 10px; align-items: center; margin-top: 10px; }
.pgn-file-nav button { padding: 4px 14px; border-radius: 6px; border: 1px solid #555; background: #333; color: #ccc; font-size: 18px; cursor: pointer; }
.pgn-file-nav button:hover { background: #444; }
.pgn-inline-caption { margin-top: 10px; font-size: 13px; color: #555; }
body.dark .pgn-inline-caption { color: #aaa; }
body.dark .pgn-moves { background: #2a2a2a; color: #ddd; }
body.dark .pgn-moves .move-san:hover, body.dark .pgn-moves .variation-move:hover { background: #3a4a5a; }
body.dark .pgn-moves .current { background: #8a6d1a; color: #fff; }
body.dark .pgn-moves .variation-move.current { background: #6d28d9; color: #fff; }

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
body.dark .content-area > button[title^="Page"] { border-color: rgba(255,255,255,0.5); background: rgba(255,255,255,0.1); }
body.dark .content-area > button[title^="Page"] svg path { stroke: rgba(255,255,255,0.5); }
body.dark #copyBtn { background: #555; color: #ffdd57; }

/* Mobile */
@media (max-width: 768px) {
    .main { width: 100%; }
    .folder-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }
    .gallery { grid-template-columns: 1fr; }
    .content-area { padding: 10px; }
    .modal-arrow { padding: 10px 14px; font-size: 24px; }
    .modal-arrow.left { left: 5px; }
    .modal-arrow.right { right: 5px; }
    .yt-modal { left: 0; }
}

/* YouTube modal */
.yt-modal {
    display: none;
    position: fixed; top: 0; left: 0; right: 0;
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
    position: fixed; bottom: 0; left: 0; right: 0;
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

/* PGN paste modal */
.pgn-paste-modal {
    display: none;
    position: fixed; bottom: 0; left: 0; right: 0;
    height: 38vh;
    background: #0f0f0f;
    z-index: 1490;
    flex-direction: column;
    align-items: center;
    padding: 10px 16px 14px;
    box-shadow: 0 -4px 20px rgba(0,0,0,0.5);
    border-top: 2px solid #2e7d32;
}
.pgn-paste-modal.open { display: flex; }
@media (max-width: 768px) { .pgn-paste-modal { left: 0; } }
.pgn-paste-bar {
    display: flex; width: 100%; max-width: 960px;
    gap: 8px; align-items: center; margin-bottom: 8px; flex-shrink: 0;
}
.pgn-paste-load-btn {
    padding: 6px 14px; border-radius: 6px; border: none;
    background: #2e7d32; color: #fff; font-size: 12px;
    font-weight: 700; cursor: pointer; white-space: nowrap;
}
.pgn-paste-load-btn:hover { background: #1b5e20; }
.pgn-paste-close {
    background: none; border: none; color: #888;
    font-size: 22px; cursor: pointer; padding: 0 4px; line-height: 1;
    margin-left: auto;
}
.pgn-paste-close:hover { color: #fff; }
#pgnPasteInput {
    width: 100%; max-width: 960px; flex: 1; min-height: 0;
    padding: 10px; border-radius: 6px;
    border: 1px solid #444; background: #1a1a1a; color: #eee;
    font-family: monospace; font-size: 13px; line-height: 1.5;
    resize: none; outline: none;
}
#pgnPasteInput:focus { border-color: #2e7d32; }

/* Engine eval display (pasted from chess analysis app) */
.pgn-moves .move-eval { font-size: 11px; font-weight: 400; margin-left: 1px; }
.pgn-eval { text-align: center; font-family: monospace; font-size: 13px; min-height: 1.3em; margin-top: 4px; font-weight: 700; }
#evalPasteInput {
    width: 100%; max-width: 960px; flex: 1; min-height: 0;
    padding: 10px; border-radius: 6px;
    border: 1px solid #444; background: #1a1a1a; color: #eee;
    font-family: monospace; font-size: 13px; line-height: 1.5;
    resize: none; outline: none;
}
#evalPasteInput:focus { border-color: #f57f17; }
</style>
</head>
<body>

<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <?php if ($currentFolder): ?>
            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1"><?= htmlspecialchars(basename($currentFolder)) ?></span>
            <div style="display:flex;align-items:center;gap:4px;flex-shrink:0">
                <?php if ($parentFolder !== null): ?>
                    <a href="<?= itemUrl(['folder'=>$parentFolder]) ?>">Back</a>
                <?php else: ?>
                    <a href="<?= itemUrl([]) ?>">Back</a>
                <?php endif; ?>
                <button class="sidebar-close-btn" onclick="toggleSidebar()" title="Close sidebar">&times;</button>
            </div>
        <?php else: ?>
            <span style="flex:1">Content</span>
            <div style="display:flex;align-items:center;gap:6px;flex-shrink:0">
                <span style="font-size:11px;color:#888"><?= count($items) ?> items</span>
                <button class="sidebar-close-btn" onclick="toggleSidebar()" title="Close sidebar">&times;</button>
            </div>
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
<script>
// Restore sidebar open state across page loads (file clicks reload the page).
// Added before first paint with transition disabled so it doesn't slide in.
(function() {
    try {
        if (localStorage.getItem('sidebarOpen') !== '1') return;
        var sb = document.getElementById('sidebar');
        sb.style.transition = 'none';
        sb.classList.add('open');
        void sb.offsetWidth;
        sb.style.transition = '';
    } catch(e) {}
})();
</script>

<div class="main">
    <div class="main-header">
        <button class="sidebar-open-btn" onclick="toggleSidebar()" title="Toggle sidebar">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
            </svg>
        </button>
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
            <button title="Paste clipboard to P2 (no save)" onclick="pasteClipboardToP2()" style="height:28px;font-size:12px;font-weight:700;padding:0 8px;border:none;border-radius:8px;cursor:pointer;background:#10b981;color:#fff">&#128203;Paste</button>
            <button title="Decrease font size" onclick="adjustFontSize(-1)" style="width:32px;height:32px;font-size:18px;font-weight:700;border:none;border-radius:8px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51)">-</button>
            <button title="Increase font size" onclick="adjustFontSize(1)" style="width:32px;height:32px;font-size:18px;font-weight:700;border:none;border-radius:8px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51)">+</button>
            <button id="ttsSettingsBtn" title="Choose text-to-speech languages for the highlight tooltip" style="height:28px;font-size:11px;font-weight:700;padding:0 8px;border:none;border-radius:6px;cursor:pointer;background:#7ec8e3;color:#1a1a2e">TTS</button>
            <button id="darkModeBtn" title="Toggle dark/light mode" onclick="toggleDarkMode()" style="width:32px;height:32px;font-size:16px;font-weight:700;border:none;border-radius:8px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51)">&#9789;</button>
            <button id="splitBtn" title="Toggle dual-pane (left=media, right=text/md)" onclick="toggleSplit()" style="height:28px;font-size:11px;font-weight:700;padding:0 8px;border:none;border-radius:6px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51)">P2</button>
            <button id="shortcutsBtn" title="Keyboard shortcuts" onclick="openShortcuts()" style="height:28px;font-size:11px;font-weight:700;padding:0 10px;border:none;border-radius:6px;cursor:pointer;background:rgb(102,126,234);color:#fff">Shortcuts</button>
            <button id="servePanelBtn" title="cd command to current folder" onclick="openServePanel()" style="height:28px;font-size:11px;font-weight:700;padding:0 10px;border:none;border-radius:6px;cursor:pointer;background:rgb(52,168,83);color:#fff">CD</button>
            <?php if ($p2DisplayType === 'text' || $p2DisplayType === 'markdown'): ?>
                <button id="copyBtn" title="Copy content" onclick="copyContent()" style="height:28px;font-size:12px;font-weight:700;padding:0 8px;border:none;border-radius:8px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51)">&#128203;Copy</button>
                <button id="p2TxtMdBtn" onclick="toggleRightTxtMd()" title="Toggle markdown/text view (right pane)" style="height:28px;font-size:11px;font-weight:700;padding:0 8px;border:none;border-radius:6px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51)"><?= $p2DisplayType === 'markdown' ? 'MD&gt;TXT' : 'TXT&gt;MD' ?></button>
                <button id="p2EditBtn" onclick="toggleP2Edit()" title="Edit and save back to local file" style="width:32px;height:32px;font-size:14px;font-weight:700;border:none;border-radius:8px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51)">&#9998;</button>
            <?php endif; ?>
            <?php if ($displayType === 'text' || $displayType === 'markdown'): ?>
                <button id="txtMdBtn" onclick="toggleLeftTxtMd()" title="Toggle markdown/text view" style="height:28px;font-size:11px;font-weight:700;padding:0 8px;border:none;border-radius:6px;cursor:pointer;background:rgb(224,224,224);color:rgb(51,51,51)"><?= $displayType === 'markdown' ? 'MD&gt;TXT' : 'TXT&gt;MD' ?></button>
            <?php endif; ?>
            <button title="YouTube music" onclick="toggleYtModal()" style="width:32px;height:32px;border:none;border-radius:8px;cursor:pointer;background:rgb(224,224,224);display:flex;align-items:center;justify-content:center;padding:0"><svg width="20" height="14" viewBox="0 0 68 48"><path d="M66.5 7.7s-.7-4.7-2.7-6.8C61-1.7 58-1.7 56.6-1.9 47.3-2.6 34-2.6 34-2.6s-13.3 0-22.6.7C10-1.7 7-1.7 4.2.9 2.2 3 1.5 7.7 1.5 7.7S.8 13.2.8 18.8v5.2c0 5.5.7 11.1.7 11.1s.7 4.7 2.7 6.8c2.8 2.6 6.4 2.5 8 2.8 5.8.5 24.8.7 24.8.7s13.3 0 22.6-.7c1.4-.2 4.4-.2 7.2-2.8 2-2.1 2.7-6.8 2.7-6.8s.7-5.5.7-11.1v-5.2c0-5.6-.7-11.1-.7-11.1z" fill="red"/><path d="M27 33V13l18.2 10L27 33z" fill="white"/></svg></button>
            <button id="ytEmbedToggleBtn" title="YouTube embed — paste any URL" onclick="toggleYtEmbedModal()" style="height:28px;font-size:11px;font-weight:700;padding:0 8px;border:none;border-radius:6px;cursor:pointer;background:#ff0000;color:#fff">YT</button>
            <button id="pgnPasteBtn" title="Paste PGN or FEN and view on chess board" onclick="togglePgnPasteModal()" style="height:28px;font-size:11px;font-weight:700;padding:0 8px;border:none;border-radius:6px;cursor:pointer;background:#2e7d32;color:#fff">PGN</button>
            <button id="evalPasteBtn" title="Paste engine evals copied from the chess analysis app (Copy Evals)" onclick="toggleEvalPasteModal()" style="height:28px;font-size:11px;font-weight:700;padding:0 8px;border:none;border-radius:6px;cursor:pointer;background:#f57f17;color:#fff">EVAL</button>
            <button id="writeBtn" title="Toggle writing panel" onclick="toggleWritePanel()" style="height:28px;font-size:11px;font-weight:700;padding:0 8px;border:none;border-radius:6px;cursor:pointer;background:#4f46e5;color:#fff">Write</button>
            <?php if ($currentFile): ?>
                <a class="download-link" href="<?= $contentDir . '/' . htmlspecialchars($currentFile) ?>" download>Download</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="panes-container" id="panesContainer">
    <div class="content-area" id="contentArea">
        <button title="Page up" onclick="contentPageUp()" style="position:sticky;top:6px;left:6px;z-index:10;width:48px;height:48px;background:rgba(0,0,0,0.08);border-radius:50%;border:1.5px solid rgb(0,0,0);opacity:0.15;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:0.2s;margin-bottom:-48px;float:left;"><svg width="48" height="48" viewBox="0 0 64 64"><path d="M8 44 L32 20 L56 44" stroke="rgba(0,0,0,0.7)" stroke-width="8" fill="none" stroke-linecap="round" stroke-linejoin="round"></path></svg></button>
        <button title="Page down" onclick="contentPageDown()" style="position:sticky;top:50%;left:6px;z-index:10;width:48px;height:48px;background:rgba(0,0,0,0.12);border-radius:50%;border:1.5px solid rgb(0,0,0);opacity:0.2;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:0.2s;margin-bottom:-48px;float:left;transform:scale(1.1);"><svg width="48" height="48" viewBox="0 0 64 64"><path d="M8 20 L32 44 L56 20" stroke="rgba(0,0,0,0.7)" stroke-width="8" fill="none" stroke-linecap="round" stroke-linejoin="round"></path></svg></button>
        <button title="Page down (pane 1)" onclick="pane1PageDown()" style="position:sticky;top:90%;left:6px;z-index:10;width:48px;height:48px;background:rgba(0,0,0,0.08);border-radius:50%;border:1.5px solid rgb(0,0,0);opacity:0.15;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:0.2s;margin-bottom:-48px;float:left;"><svg width="48" height="48" viewBox="0 0 64 64"><path d="M8 20 L32 44 L56 20" stroke="rgba(0,0,0,0.7)" stroke-width="8" fill="none" stroke-linecap="round" stroke-linejoin="round"></path></svg></button>
        <button title="Next file" onclick="textFileNav('ArrowRight')" style="position:sticky;top:50%;right:6px;z-index:10;width:48px;height:48px;background:rgba(0,0,0,0.08);border-radius:50%;border:1.5px solid rgb(0,0,0);opacity:0.15;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:0.2s;margin-bottom:-48px;float:right;"><svg width="48" height="48" viewBox="0 0 64 64"><path d="M20 8 L44 32 L20 56" stroke="rgba(0,0,0,0.7)" stroke-width="8" fill="none" stroke-linecap="round" stroke-linejoin="round"></path></svg></button>
        <button title="Prev file" onclick="textFileNav('ArrowLeft')" style="position:sticky;top:6px;right:6px;z-index:10;width:48px;height:48px;background:rgba(0,0,0,0.08);border-radius:50%;border:1.5px solid rgb(0,0,0);opacity:0.15;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:0.2s;margin-bottom:-48px;float:right;"><svg width="48" height="48" viewBox="0 0 64 64"><path d="M44 8 L20 32 L44 56" stroke="rgba(0,0,0,0.7)" stroke-width="8" fill="none" stroke-linecap="round" stroke-linejoin="round"></path></svg></button>
        <button title="Page down (pane 1)" onclick="pane1PageDown()" style="position:sticky;top:90%;right:6px;z-index:10;width:48px;height:48px;background:rgba(0,0,0,0.08);border-radius:50%;border:1.5px solid rgb(0,0,0);opacity:0.15;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:0.2s;margin-bottom:-48px;float:right;"><svg width="48" height="48" viewBox="0 0 64 64"><path d="M8 20 L32 44 L56 20" stroke="rgba(0,0,0,0.7)" stroke-width="8" fill="none" stroke-linecap="round" stroke-linejoin="round"></path></svg></button>

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

            <!-- Gallery grid inside folder — images only; videos/audio/text open from the sidebar -->
            <div class="gallery">
                <?php
                $imageExts = ['png','jpg','jpeg','gif','webp','bmp','svg'];
                $gridImgCount = 0;
                foreach ($folderFiles as $f):
                    if (!in_array($f['ext'], $imageExts)) continue;
                    // Grid index == imageList index (same source array, same ext filter, same order)
                    $imgIdx = $gridImgCount;
                    $gridImgCount++;
                    $enc = implode('/', array_map('rawurlencode', explode('/', $f['path'])));
                ?>
                    <div class="gallery-item">
                        <a href="<?= itemUrl(['folder'=>$currentFolder,'file'=>$f['path']]) ?>"
                           onclick="openModalAt(<?= $imgIdx ?>); return false;"
                           title="Click to enlarge — ‹ › buttons or swipe change images">
                            <img src="<?= $contentDir . '/' . htmlspecialchars($enc) ?>"
                                 alt="<?= htmlspecialchars($f['name']) ?>">
                        </a>
                        <div class="caption"><?= htmlspecialchars($f['name']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($gridImgCount === 0): ?>
                <?php
                // No images — populate pane 1 with the folder's other files instead of a bare notice
                $niVideoExts   = ['mp4','webm','ogg','mov','avi','mkv','m4v'];
                $niAudioExts   = ['mp3','m4a'];
                $nonImageFiles = array_values(array_filter($folderFiles, function($f) use ($imageExts) {
                    return !in_array($f['ext'], $imageExts);
                }));
                ?>
                <?php if (empty($nonImageFiles)): ?>
                    <div style="color:#999;text-align:center;padding:40px 20px;font-size:13px">No images in this folder — open other files from the sidebar.</div>
                <?php else: ?>
                    <div class="gallery">
                        <?php foreach ($nonImageFiles as $nf):
                            $nfEnc = implode('/', array_map('rawurlencode', explode('/', $nf['path'])));
                            $nfUrl = itemUrl(['folder'=>$currentFolder,'file'=>$nf['path']]);
                            if (in_array($nf['ext'], $niVideoExts)):
                        ?>
                            <div class="gallery-item">
                                <a href="<?= $nfUrl ?>">
                                    <video style="width:100%;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,0.1)" muted preload="metadata">
                                        <source src="<?= $contentDir . '/' . htmlspecialchars($nfEnc) ?>" type="video/mp4">
                                    </video>
                                </a>
                                <div class="caption"><?= htmlspecialchars($nf['name']) ?></div>
                            </div>
                        <?php elseif (in_array($nf['ext'], $niAudioExts)): ?>
                            <div class="gallery-item">
                                <a href="<?= $nfUrl ?>"
                                   style="display:block;padding:20px;background:#1a1a2e;border-radius:4px;text-decoration:none;color:#eee;box-shadow:0 2px 8px rgba(0,0,0,0.08);text-align:center">
                                    <div style="font-size:28px;margin-bottom:8px">&#9835;</div>
                                    <?= htmlspecialchars($nf['name']) ?>
                                </a>
                                <div class="caption"><?= htmlspecialchars($nf['name']) ?></div>
                            </div>
                        <?php else: ?>
                            <div class="gallery-item">
                                <a href="<?= $nfUrl ?>"
                                   style="display:block;padding:20px;background:#fff;border-radius:4px;text-decoration:none;color:#333;box-shadow:0 2px 8px rgba(0,0,0,0.08)">
                                    <?= htmlspecialchars($nf['name']) ?>
                                </a>
                            </div>
                        <?php endif; endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

        <?php elseif ($displayType === 'image'):
            $encodedCurrentPath = $contentDir . '/' . implode('/', array_map('rawurlencode', explode('/', $currentFile)));
            $currentImgIdx = 0;
            foreach ($imageList as $ii => $imgEntry) {
                if ($imgEntry['src'] === $encodedCurrentPath) { $currentImgIdx = $ii; break; }
            }
        ?>
            <!-- Vertical list of ALL folder images (the modal's set, laid out vertically) -->
            <div class="stacked-images" id="p1ImageList">
                <?php foreach ($imageList as $ii => $imgEntry): ?>
                    <div class="p1-item<?= $ii === $currentImgIdx ? ' current' : '' ?>" data-img-idx="<?= $ii ?>">
                        <img src="<?= htmlspecialchars($imgEntry['src']) ?>"
                             alt="<?= htmlspecialchars($imgEntry['name']) ?>"
                             onclick="openModalAt(<?= $ii ?>)"
                             title="Click to enlarge — ‹ › buttons or swipe change images">
                        <div class="caption"><?= htmlspecialchars($imgEntry['name']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <script>
            (function() {
                var cur = document.querySelector('#p1ImageList .p1-item.current');
                if (!cur) return;
                function go() { cur.scrollIntoView({ block: 'center' }); }
                go();
                // Images decode after first layout — re-pin the scroll position as
                // each one loads so the scrollbar lands on (and stays on) the image
                document.querySelectorAll('#p1ImageList img').forEach(function(im) {
                    if (!im.complete) im.addEventListener('load', go, { once: true });
                });
                window.addEventListener('load', go);
            })();
            </script>

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

        <?php elseif ($displayType === 'audio'):
            $audioMimeMap = ['mp3'=>'audio/mpeg','m4a'=>'audio/mp4'];
            $aExt = strtolower(pathinfo($currentFile, PATHINFO_EXTENSION));
            $aMime = isset($audioMimeMap[$aExt]) ? $audioMimeMap[$aExt] : 'audio/mpeg';
            $encodedAudioPath = implode('/', array_map('rawurlencode', explode('/', $currentFile)));
        ?>
            <div style="padding:60px 20px;text-align:center">
                <div style="font-size:56px;margin-bottom:14px">&#9835;</div>
                <div style="font-size:14px;margin-bottom:20px;word-break:break-all"><?= htmlspecialchars(basename($currentFile)) ?></div>
                <audio controls autoplay style="width:100%;max-width:640px">
                    <source src="?stream=<?= $encodedAudioPath ?>" type="<?= $aMime ?>">
                    Your browser does not support this audio format.
                </audio>
            </div>

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

        <?php elseif ($displayType === 'pgn'): ?>
            <div id="pgnInlineHost" style="width:100%"></div>
            <script>
            window._pgnInlineBoot = {
                name: <?= json_encode(basename($currentFile)) ?>,
                text: <?= json_encode($displayContent, JSON_HEX_TAG | JSON_HEX_AMP) ?>
            };
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
            $rootTextExts  = ['txt','csv','json','log','md','html','htm','docx','rtf','pdf','pgn','py'];
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
            <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-right:6px"><?= htmlspecialchars(basename($p2File)) ?></span>
            <a href="<?= htmlspecialchars($p2CloseUrl) ?>" class="pr-close" title="Close right pane">&times;</a>
        </div>
        <?php endif; ?>
        <button title="Page down" onclick="rightPanePageDown()" style="position:sticky;top:50%;left:6px;z-index:10;width:48px;height:48px;background:rgba(0,0,0,0.12);border-radius:50%;border:1.5px solid rgb(0,0,0);opacity:0.2;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:0.2s;margin-bottom:-48px;float:left;transform:scale(1.1);"><svg width="48" height="48" viewBox="0 0 64 64"><path d="M8 20 L32 44 L56 20" stroke="rgba(0,0,0,0.7)" stroke-width="8" fill="none" stroke-linecap="round" stroke-linejoin="round"></path></svg></button>
        <button title="Page up" onclick="contentPageUp()" style="position:sticky;top:6px;left:6px;z-index:10;width:48px;height:48px;background:rgba(0,0,0,0.08);border-radius:50%;border:1.5px solid rgb(0,0,0);opacity:0.15;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:0.2s;margin-bottom:-48px;float:left;"><svg width="48" height="48" viewBox="0 0 64 64"><path d="M8 44 L32 20 L56 44" stroke="rgba(0,0,0,0.7)" stroke-width="8" fill="none" stroke-linecap="round" stroke-linejoin="round"></path></svg></button>
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
        <?php elseif ($p2DisplayType === 'code'): ?>
            <div class="text-content code-content"><pre><code class="language-python" id="p2-code-render"><?= htmlspecialchars($p2DisplayContent) ?></code></pre></div>
            <script>hljs.highlightElement(document.getElementById('p2-code-render'));</script>
        <?php elseif ($p2DisplayType === 'docx'): ?>
            <div class="docx-content" id="p2-docx-render">Loading document...</div>
            <script>
            (function() {
                fetch('<?= htmlspecialchars($p2DisplayContent) ?>')
                    .then(function(r) { return r.arrayBuffer(); })
                    .then(function(buf) { return mammoth.convertToHtml({ arrayBuffer: buf }); })
                    .then(function(result) { document.getElementById('p2-docx-render').innerHTML = result.value; })
                    .catch(function(err) {
                        document.getElementById('p2-docx-render').innerHTML =
                            '<p style="color:red">Could not render DOCX: ' + err.message + '</p>' +
                            '<p><a href="<?= $contentDir . '/' . htmlspecialchars($p2File) ?>" download>Download instead</a></p>';
                    });
            })();
            </script>
        <?php else: ?>
            <div style="display:flex;align-items:center;justify-content:center;height:70%;color:#aaa;font-size:13px;text-align:center;pointer-events:none">
                <div><div style="font-size:36px;margin-bottom:10px">&#128196;</div><p>Click a .txt, .md, .py or .docx file<br>to open it here</p></div>
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
        <span>&#128193; Go to folder</span>
        <button class="sv-close" onclick="closeServePanel()" title="Close">&times;</button>
    </div>
    <div class="sv-row">
        <label>Path</label>
        <input id="svPath" value="<?php echo htmlspecialchars(realpath($currentFolder ? $contentDir . '/' . $currentFolder : $contentDir) ?: realpath($contentDir), ENT_QUOTES); ?>" oninput="updateServeCmd()">
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

<!-- Image Modal -->
<div class="image-modal" id="imageModal">
    <button class="modal-close" onclick="closeModal()">&times;</button>
    <button class="modal-arrow left" onclick="modalImageNav('ArrowLeft')">&#8249;</button>
    <img class="modal-img" id="modalImg" src="" alt="">
    <button class="modal-arrow right" onclick="modalImageNav('ArrowRight')">&#8250;</button>
    <div class="modal-caption" id="modalCaption"></div>
    <div class="modal-counter" id="modalCounter"></div>
</div>

<!-- PGN Paste Modal -->
<div class="pgn-paste-modal" id="pgnPasteModal">
    <div class="pgn-paste-bar">
        <span style="color:#eee;font-size:12px;font-weight:700">Paste PGN or FEN below — headers, {comments}, (variations), ? ?? ! annotations, [FEN "..."] start positions supported</span>
        <button class="pgn-paste-load-btn" onclick="loadPastedPgn()">&#9654; Load</button>
        <button class="pgn-paste-close" onclick="togglePgnPasteModal()">&times;</button>
    </div>
    <textarea id="pgnPasteInput" placeholder="1. b4 {A00 Polish Opening} e5 2. Bb2 Qf6 (3. Nf3 Nc6 4. b5 Nd4 5. e3) 3. f4? exf4?? 4. Bxf6 *"></textarea>
</div>

<!-- Eval Paste Modal -->
<div class="pgn-paste-modal" id="evalPasteModal" style="border-top: 2px solid #f57f17">
    <div class="pgn-paste-bar">
        <span style="color:#eee;font-size:12px;font-weight:700">Paste evals copied from the chess analysis app ("&#128203; Copy Evals") — load the same PGN here, then evals show next to each move</span>
        <button class="pgn-paste-load-btn" style="background:#f57f17" onclick="loadPastedEval()">&#9654; Load Evals</button>
        <button class="pgn-paste-close" onclick="toggleEvalPasteModal()">&times;</button>
    </div>
    <textarea id="evalPasteInput" placeholder="rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w	0&#10;rnbqkbnr/pppppppp/8/8/3P4/8/PPP1PPPP/RNBQKBNR b	30"></textarea>
</div>

<!-- YouTube Music Modal -->
<div class="yt-modal" id="ytModal">
    <div class="yt-modal-header">
        <div class="yt-modal-title" id="ytTitle">YouTube Music</div>
        <button class="yt-modal-close" onclick="toggleYtModal()">&times;</button>
    </div>
    <div class="yt-track-list" id="ytTrackList"></div>
    <div style="margin-bottom:8px"><button id="ytRandomBtn" onclick="ytRandomSeek()" style="background:linear-gradient(45deg,#ff9800,#f57c00);padding:6px 12px;font-size:12px;border:none;border-radius:6px;color:#fff;cursor:pointer;font-weight:600">🎲 Random</button></div>
    <div class="yt-iframe-wrap" id="ytIframeWrap"></div>
</div>

<!-- Writing panel — fixed floating panel at bottom of screen -->
<div id="writingPanel" style="display:none;position:fixed;bottom:0;left:0;right:0;z-index:9000;box-shadow:0 -4px 24px rgba(0,0,0,0.18);background:#ffffff;border-top:2px solid #6366f1;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 12px 4px;border-bottom:1px solid #e5e7eb;">
        <span style="font-size:12px;font-weight:600;color:#6366f1;letter-spacing:0.03em;">Writing <span style="font-size:10px;color:#9ca3af;font-weight:400;margin-left:4px;">tab to scroll</span></span>
        <div style="display:flex;gap:6px;align-items:center;">
            <button onclick="writeAdjFont(-1)" style="font-size:13px;font-weight:700;color:#6b7280;background:none;border:1px solid #d1d5db;cursor:pointer;padding:1px 7px;border-radius:3px;line-height:1.4;" title="Decrease font size">A−</button>
            <span id="writeFontSizeLabel" style="font-size:11px;color:#9ca3af;min-width:24px;text-align:center;">15</span>
            <button onclick="writeAdjFont(1)" style="font-size:13px;font-weight:700;color:#6b7280;background:none;border:1px solid #d1d5db;cursor:pointer;padding:1px 7px;border-radius:3px;line-height:1.4;" title="Increase font size">A+</button>
            <button onclick="writeAdjMargin(-10)" style="font-size:11px;color:#6b7280;background:none;border:1px solid #d1d5db;cursor:pointer;padding:1px 7px;border-radius:3px;line-height:1.4;font-family:monospace;" title="Decrease left margin">←</button>
            <span id="writeMarginLabel" style="font-size:11px;color:#9ca3af;min-width:28px;text-align:center;">0%</span>
            <button onclick="writeAdjMargin(10)" style="font-size:11px;color:#6b7280;background:none;border:1px solid #d1d5db;cursor:pointer;padding:1px 7px;border-radius:3px;line-height:1.4;font-family:monospace;" title="Increase left margin">→</button>
            <button onclick="writeCopy()" style="font-size:11px;color:#6b7280;background:none;border:1px solid #d1d5db;cursor:pointer;padding:2px 8px;border-radius:3px;" title="Copy text to clipboard">Copy</button>
            <button onclick="writeClear()" style="font-size:11px;color:#6b7280;background:none;border:1px solid #d1d5db;cursor:pointer;padding:2px 8px;border-radius:3px;">Clear</button>
            <button onclick="toggleWritePanel()" style="font-size:14px;font-weight:700;color:#6b7280;background:none;border:none;cursor:pointer;padding:2px 6px;line-height:1;" title="Close">✕</button>
        </div>
    </div>
    <textarea id="writingTextarea"
        placeholder="Write here..."
        style="width:100%;height:160px;resize:vertical;padding:10px 14px;font-size:15px;line-height:1.6;background:#f9fafb;color:#111827;border:none;outline:none;font-family:inherit;box-sizing:border-box;display:block;"
    ></textarea>
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
var pgnList = <?= json_encode($pgnList, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?: '[]' ?>;
var textList = <?= json_encode($textList, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?: '[]' ?>;
var pgnAutoOpenIdx = <?= $currentPgnIdx ?>;
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
    var sb = document.getElementById('sidebar');
    sb.classList.toggle('open');
    try { localStorage.setItem('sidebarOpen', sb.classList.contains('open') ? '1' : '0'); } catch(e) {}
}

function contentPageTarget() {
    // Page buttons on pane 1 always scroll pane 2 whenever the right pane is visible
    var p2 = document.getElementById('paneRight');
    return (p2 && p2.style.display !== 'none') ? p2 : document.getElementById('contentArea');
}
function contentPageDown() {
    var el = contentPageTarget();
    el.scrollBy({ top: el.clientHeight * 0.9, behavior: 'smooth' });
}
function contentPageUp() {
    var el = contentPageTarget();
    el.scrollBy({ top: -el.clientHeight * 0.9, behavior: 'smooth' });
}
function pane1PageDown() {
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
        paneRight.querySelectorAll('.text-content, .markdown-content, .docx-content').forEach(function(el) {
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
    // Reflect the last-viewed modal image back into pane 1's vertical list
    var item = document.querySelector('#p1ImageList .p1-item[data-img-idx="' + modalIndex + '"]');
    if (item) {
        var prev = document.querySelector('#p1ImageList .p1-item.current');
        if (prev) prev.classList.remove('current');
        item.classList.add('current');
        item.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
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

// ← → arrow keys: cycle through text files (txt/md/docx) only — images and
// other media are skipped entirely. Destination always loads in the right pane (p2).
function textFileNav(key, evt) {
    if (!textList.length) return;
    if (evt) evt.preventDefault();
    var p = new URLSearchParams(window.location.search);
    var cur = p.get('p2') || p.get('file') || '';
    var idx = -1;
    for (var i = 0; i < textList.length; i++) {
        if (textList[i].file === cur) { idx = i; break; }
    }
    var next;
    if (idx === -1) next = key === 'ArrowRight' ? 0 : textList.length - 1;
    else next = idx + (key === 'ArrowRight' ? 1 : -1);
    if (next < 0 || next >= textList.length) return;
    p.set('p2', textList[next].file);
    window.location.href = '?' + p.toString();
}

// Modal ‹ › buttons / swipe: cycle images in place (no page reload) — pane 1's
// vertical list re-syncs to the last-viewed image when the modal closes.
function modalImageNav(key, evt) {
    if (!imageList.length) return;
    if (evt) evt.preventDefault();
    var next = modalIndex + (key === 'ArrowRight' ? 1 : -1);
    if (next < 0 || next >= imageList.length) return;
    modalIndex = next;
    showModalImage();
    // Keep the URL truthful so refresh / 9-0 file nav stay in sync
    try { history.replaceState(null, '', imageList[modalIndex].url); } catch (e) {}
}

// =====================================================================
// PGN CHESS VIEWER (inline in first pane)
// =====================================================================
var pgnPasteModalEl = document.getElementById('pgnPasteModal');
var evalPasteModalEl = document.getElementById('evalPasteModal');
var pgnBoard = null, pgnRoot = null, pgnCurrent = null, pgnNodeRegistry = {};
var pgnEvalMap = {}; // "<board> <turn>" -> centipawns, White perspective (pasted from chess analysis app)

// --- Eval paste box (toolbar EVAL button) ---
function toggleEvalPasteModal() {
    var open = evalPasteModalEl.classList.toggle('open');
    if (open) setTimeout(function() { document.getElementById('evalPasteInput').focus(); }, 60);
}

function pgnEvalKey(fen) {
    var p = fen.split(/\s+/);
    return p[0] + ' ' + p[1];
}

function pgnEvalCpForFen(fen) {
    if (!fen) return null;
    var k = pgnEvalKey(fen);
    return (pgnEvalMap[k] !== undefined) ? pgnEvalMap[k] : null;
}

function pgnFormatEval(cp) {
    if (Math.abs(cp) >= 9000) {
        var m = 10000 - Math.abs(cp);
        return (cp > 0 ? 'M' : '-M') + m;
    }
    var p = cp / 100;
    return (p >= 0 ? '+' : '') + p.toFixed(2);
}

function loadPastedEval() {
    var input = document.getElementById('evalPasteInput');
    var text = input.value.trim();
    if (!text) return;

    // Accepts a bare eval list or a whole analysis report — ENGINE EVALUATIONS
    // is always the last section of the report, so parse from that marker down.
    var marker = text.lastIndexOf('ENGINE EVALUATIONS');
    if (marker >= 0) text = text.slice(marker);

    var map = {};
    var lines = text.split('\n');
    for (var i = 0; i < lines.length; i++) {
        var line = lines[i].trim();
        if (!line || line.charAt(0) === '#') continue;
        // Format: "<board> <turn><TAB>cp" (full-FEN keys also accepted — eval is the last token)
        var sep = line.lastIndexOf('\t');
        if (sep < 0) sep = line.lastIndexOf(' ');
        if (sep <= 0) continue;
        var keyPart = line.slice(0, sep).trim();
        if (keyPart.indexOf('/') < 0) continue; // data lines must start with a FEN board field
        var cp = parseInt(line.slice(sep + 1).trim(), 10);
        if (isNaN(cp)) continue;
        map[pgnEvalKey(keyPart)] = cp;
    }
    pgnEvalMap = map;
    input.value = '';
    evalPasteModalEl.classList.remove('open');
    if (pgnRoot) pgnRenderMoveTable(pgnRoot);
    if (pgnCurrent) pgnJumpToNode(pgnCurrent.nodeId);
}

// Small eval badge after a move in the move list (green = White better, red = Black better)
function pgnAppendEvalBadge(node, container) {
    var cp = pgnEvalCpForFen(node.fen);
    if (cp === null) return;
    var evSpan = document.createElement('span');
    evSpan.className = 'move-eval';
    evSpan.textContent = pgnFormatEval(cp);
    evSpan.style.color = cp > 0 ? '#43a047' : (cp < 0 ? '#e53935' : '#888');
    container.appendChild(evSpan);
    container.appendChild(document.createTextNode(' '));
}
var pgnFileIndex = -1;
var pgnInlineActive = false;

// --- Lazy-load chess libraries (jQuery -> chessboard-js -> chess.js) ---
var pgnLibsState = 'none'; // none | loading | ready
var pgnLibsCallbacks = [];
function ensureChessLibs(cb) {
    if (pgnLibsState === 'ready') { cb(); return; }
    pgnLibsCallbacks.push(cb);
    if (pgnLibsState === 'loading') return;
    pgnLibsState = 'loading';
    function loadScript(src, next) {
        var s = document.createElement('script');
        s.src = src;
        s.onload = next;
        s.onerror = function() {
            pgnLibsState = 'none'; pgnLibsCallbacks = [];
            document.getElementById('pgnMoves').textContent = 'Failed to load chess libraries (internet required).';
        };
        document.head.appendChild(s);
    }
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'https://cdnjs.cloudflare.com/ajax/libs/chessboard-js/1.0.0/chessboard-1.0.0.min.css';
    document.head.appendChild(link);
    loadScript('https://cdnjs.cloudflare.com/ajax/libs/jquery/2.2.4/jquery.min.js', function() {
        loadScript('https://cdnjs.cloudflare.com/ajax/libs/chessboard-js/1.0.0/chessboard-1.0.0.min.js', function() {
            loadScript('https://cdnjs.cloudflare.com/ajax/libs/chess.js/0.10.3/chess.min.js', function() {
                pgnLibsState = 'ready';
                var cbs = pgnLibsCallbacks; pgnLibsCallbacks = [];
                cbs.forEach(function(f) { f(); });
            });
        });
    });
}

// --- Inline viewer markup (single source of truth for both PHP-boot and paste flows) ---
function pgnInlineHtml() {
    return '<div class="pgn-inline" id="pgnInline">'
        + '<div class="pgn-inline-inner">'
        +   '<div class="pgn-board-col">'
        +     '<div id="pgnBoard" style="width:400px;max-width:90vw"></div>'
        +     '<div class="pgn-controls">'
        +       '<button id="pgnStartBtn" onclick="pgnGoStart()" title="Start">|&laquo;</button>'
        +       '<button id="pgnPrevBtn" onclick="pgnGoPrev()" title="Previous move">&laquo; Prev</button>'
        +       '<button id="pgnNextBtn" onclick="pgnGoNext()" title="Next move">Next &raquo;</button>'
        +       '<button id="pgnEndBtn" onclick="pgnGoEnd()" title="End">&raquo;|</button>'
        +     '</div>'
        +     '<div class="pgn-comment" id="pgnComment"></div>'
        +     '<div class="pgn-eval" id="pgnEval"></div>'
        +   '</div>'
        +   '<div class="pgn-moves" id="pgnMoves"></div>'
        + '</div>'
        + '<div class="pgn-file-nav" id="pgnFileNav" style="display:none">'
        +   '<button onclick="pgnFileNav(-1)" title="Previous PGN file">&#8249;</button>'
        +   '<div class="pgn-inline-caption" id="pgnCaption" style="margin-top:0"></div>'
        +   '<button onclick="pgnFileNav(1)" title="Next PGN file">&#8250;</button>'
        + '</div>'
        + '<div class="pgn-inline-caption" id="pgnCaptionSolo"></div>'
        + '</div>';
}

// Find or create the viewer host inside the first pane. On non-PGN pages
// (paste flow) this replaces the content area's current contents.
function pgnEnsureInlineHost() {
    var host = document.getElementById('pgnInlineHost');
    if (!host) {
        var area = document.getElementById('contentArea');
        area.innerHTML = '';
        host = document.createElement('div');
        host.id = 'pgnInlineHost';
        host.style.width = '100%';
        area.appendChild(host);
    }
    if (!document.getElementById('pgnInline')) {
        pgnBoard = null; // board element (if any) was destroyed with old markup
        host.innerHTML = pgnInlineHtml();
    }
    return host;
}

// --- Open/show ---
function pgnShowInline(caption, text, idx) {
    pgnEnsureInlineHost();
    pgnInlineActive = true;
    pgnFileIndex = (typeof idx === 'number') ? idx : -1;
    var multi = pgnList.length > 1 && pgnFileIndex >= 0;
    document.getElementById('pgnFileNav').style.display = multi ? 'flex' : 'none';
    document.getElementById('pgnCaptionSolo').style.display = multi ? 'none' : 'block';
    if (multi) document.getElementById('pgnCaption').textContent = caption + ' (' + (pgnFileIndex + 1) + '/' + pgnList.length + ')';
    document.getElementById('pgnCaptionSolo').textContent = caption;
    document.getElementById('pgnMoves').innerHTML = 'Loading&#8230;';
    document.getElementById('pgnComment').textContent = '';
    document.getElementById('contentArea').scrollTop = 0;
    ensureChessLibs(function() { renderPgnText(text); });
}

function openPgnInline(idx) {
    if (!pgnList.length) return;
    idx = ((idx % pgnList.length) + pgnList.length) % pgnList.length;
    var entry = pgnList[idx];
    fetch(entry.src)
        .then(function(r) { return r.text(); })
        .then(function(text) { pgnShowInline(entry.name, text, idx); })
        .catch(function() {
            pgnEnsureInlineHost();
            document.getElementById('pgnMoves').textContent = 'Failed to load PGN file.';
        });
}

function pgnFileNav(dir) { if (pgnFileIndex >= 0) openPgnInline(pgnFileIndex + dir); }

// --- Paste-PGN box (toolbar PGN button) ---
function togglePgnPasteModal() {
    var open = pgnPasteModalEl.classList.toggle('open');
    if (open) setTimeout(function() { document.getElementById('pgnPasteInput').focus(); }, 60);
}

function loadPastedPgn() {
    var input = document.getElementById('pgnPasteInput');
    var text = input.value.trim();
    if (!text) return;
    input.value = ''; // clear box once loaded
    // Raw FEN (no PGN headers) — wrap it so the parser uses it as the start position.
    // Loose check: only the first token must look like a FEN board (8 rows); the
    // remaining fields are sanitized/padded, so partial or sloppy FENs still load.
    // Lichess-style [Variant "From Position"] + [FEN "..."] PGNs already work via the header.
    var fenParts = text.split(/\s+/);
    var isFen = /^([rnbqkpRNBQKP1-8]+\/){7}[rnbqkpRNBQKP1-8]+$/.test(fenParts[0]);
    if (isFen) {
        fenParts.length = Math.min(fenParts.length, 6);
        var fenTurn = (fenParts[1] || '').toLowerCase();
        fenParts[1] = (fenTurn === 'w' || fenTurn === 'b') ? fenTurn : 'w';
        var fenCastling = (fenParts[2] || '').replace(/[^KQkq]/g, '');
        fenParts[2] = fenCastling || '-';
        fenParts[3] = /^[a-h][1-8]$/.test(fenParts[3] || '') ? fenParts[3] : '-';
        fenParts[4] = /^\d+$/.test(fenParts[4] || '') ? fenParts[4] : '0';
        fenParts[5] = /^[1-9]\d*$/.test(fenParts[5] || '') ? fenParts[5] : '1';
        text = '[FEN "' + fenParts.join(' ') + '"]\n*';
    }
    pgnPasteModalEl.classList.remove('open');
    pgnShowInline(isFen ? 'Pasted FEN' : 'Pasted PGN', text);
}

// --- Shared parse + render (used by file loading and pasted PGN) ---
function renderPgnText(text) {
    // Multi-game PGNs: viewer shows the first game only
    var evRe = /^\[Event /gm, m, hits = [];
    while ((m = evRe.exec(text)) !== null) hits.push(m.index);
    if (hits.length > 1) text = text.slice(0, hits[1]);

    pgnRoot = pgnParseWithVariations(text);
    if (!pgnBoard) {
        pgnBoard = Chessboard('pgnBoard', {
            draggable: false,
            position: pgnRoot.fen,
            pieceTheme: 'https://assets.codepen.io/1075762/{piece}.png'
        });
    }
    pgnRenderMoveTable(pgnRoot);
    pgnJumpToNode('root');
}

// --- PGN parsing (adapted from chess analysis app) ---
var pgnNagToSymbol = {
    '$1': '!', '$2': '?', '$3': '!!', '$4': '??', '$5': '!?', '$6': '?!',
    '$7': '□', '$8': '□', '$9': '', '$10': '=', '$11': '=', '$12': '=',
    '$13': '∞', '$14': '+=', '$15': '=+', '$16': '±', '$17': '∓',
    '$18': '+-', '$19': '-+', '$22': '⨀', '$32': '⟳', '$36': '→',
    '$40': '↑', '$132': '⇆', '$138': '⊕', '$140': '∆', '$146': 'N'
};

function pgnTokenize(moveText) {
    var tokens = [];
    var current = '';
    var inComment = false;
    for (var i = 0; i < moveText.length; i++) {
        var ch = moveText[i];
        if (ch === '{') {
            if (current.trim()) tokens.push(current.trim());
            current = '{'; inComment = true;
        } else if (ch === '}') {
            current += '}'; tokens.push(current); current = ''; inComment = false;
        } else if (inComment) {
            current += ch;
        } else if (ch === '(' || ch === ')') {
            if (current.trim()) tokens.push(current.trim());
            tokens.push(ch); current = '';
        } else if (/\s/.test(ch)) {
            if (current.trim()) tokens.push(current.trim());
            current = '';
        } else {
            current += ch;
        }
    }
    if (current.trim()) tokens.push(current.trim());
    return tokens;
}

function pgnParseWithVariations(pgnString) {
    var fenMatch = pgnString.match(/\[FEN\s+"([^"]+)"\]/);
    var startingFen = fenMatch ? fenMatch[1] : 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';
    var moveText = pgnString.replace(/\[.*?\]/g, '').trim();
    var tokens = pgnTokenize(moveText);

    var chess = new Chess();
    try { chess.load(startingFen); } catch (e) { chess.reset(); }

    var rootNode = {
        san: null, fen: chess.fen(), moveNumber: 0, isBlackMove: false,
        comment: '', annotation: '', parent: null, children: [], depth: 0, nodeId: 'root'
    };
    pgnNodeRegistry = { 'root': rootNode };
    pgnParseTokens(tokens, 0, chess, rootNode, 0, { counter: 0 });
    return rootNode;
}

function pgnParseTokens(tokens, startIndex, chess, parentNode, depth, counterObj) {
    var i = startIndex;
    var lastMoveNode = parentNode;
    var branchChess = chess;
    var mainlineNeedsReorder = false;

    while (i < tokens.length) {
        var token = tokens[i];

        if (token === '(') {
            var branchPoint = lastMoveNode.parent || parentNode;
            var firstMoveToken = null;
            var firstMoveIsBlack = null;
            for (var j = i + 1; j < tokens.length; j++) {
                var t = tokens[j];
                if (t === ')' || t === '(') break;
                if (t.charAt(0) === '{') continue;
                if (/^\$\d+$/.test(t)) continue;
                if (['1-0', '0-1', '1/2-1/2', '*'].indexOf(t) !== -1) continue;
                if (/^\d+\.+$/.test(t)) { firstMoveIsBlack = t.indexOf('...') !== -1; continue; }
                firstMoveToken = t.replace(/^\d+\.+/, '').replace(/[!?]+$/, '');
                break;
            }

            // Detect continuation-style variations (variation continues from last move
            // instead of replacing it)
            if (firstMoveToken && lastMoveNode !== parentNode) {
                if (firstMoveIsBlack !== null) {
                    var currentTurn = lastMoveNode.fen.split(' ')[1];
                    if (firstMoveIsBlack === (currentTurn === 'b')) {
                        branchPoint = lastMoveNode;
                        mainlineNeedsReorder = true;
                    }
                } else {
                    var testCurrent = new Chess();
                    testCurrent.load(lastMoveNode.fen);
                    if (testCurrent.move(firstMoveToken, { sloppy: true })) {
                        var parentBranch = lastMoveNode.parent || parentNode;
                        var testParent = new Chess();
                        testParent.load(parentBranch.fen);
                        if (!testParent.move(firstMoveToken, { sloppy: true })) {
                            branchPoint = lastMoveNode;
                            mainlineNeedsReorder = true;
                        }
                    }
                }
            }

            var variationChess = new Chess();
            variationChess.load(branchPoint.fen);
            i = pgnParseTokens(tokens, i + 1, variationChess, branchPoint, depth + 1, counterObj);
            continue;
        }

        if (token === ')') return i + 1;

        if (token.charAt(0) === '{') {
            var comment = token.slice(1, -1).trim();
            if (lastMoveNode && lastMoveNode !== parentNode) lastMoveNode.comment = comment;
            i++;
            continue;
        }

        if (/^\d+\.+$/.test(token)) { i++; continue; }

        if (/^\$\d+$/.test(token)) {
            var nagSymbol = pgnNagToSymbol[token] || '';
            if (nagSymbol && lastMoveNode && lastMoveNode.nodeId !== 'root') {
                lastMoveNode.annotation = (lastMoveNode.annotation || '') + nagSymbol;
            }
            i++;
            continue;
        }

        if (['1-0', '0-1', '1/2-1/2', '*'].indexOf(token) !== -1) { i++; continue; }

        // Move token
        try {
            var moveToken = token.replace(/^\d+\.+/, '');
            var annotationMatch = moveToken.match(/^(.+?)([!?]+)$/);
            var cleanMove = annotationMatch ? annotationMatch[1] : moveToken;
            var annotation = annotationMatch ? annotationMatch[2] : '';

            var turnBeforeMove = branchChess.turn();
            var move = branchChess.move(cleanMove, { sloppy: true });
            if (move) {
                var moveNumber;
                var isBlackMove = (turnBeforeMove === 'b');
                if (lastMoveNode.nodeId === 'root') {
                    var fenParts = lastMoveNode.fen.split(' ');
                    moveNumber = fenParts.length >= 6 ? parseInt(fenParts[5]) || 1 : 1;
                } else if (isBlackMove) {
                    moveNumber = lastMoveNode.moveNumber;
                } else {
                    moveNumber = lastMoveNode.moveNumber + (lastMoveNode.isBlackMove ? 1 : 0);
                }

                var newNode = {
                    san: move.san, annotation: annotation, fen: branchChess.fen(),
                    moveNumber: moveNumber, isBlackMove: isBlackMove, comment: '',
                    parent: lastMoveNode, children: [], depth: depth,
                    nodeId: 'pgn-node-' + (counterObj.counter++)
                };
                if (mainlineNeedsReorder && lastMoveNode.children.length > 0) {
                    lastMoveNode.children.unshift(newNode);
                } else {
                    lastMoveNode.children.push(newNode);
                }
                mainlineNeedsReorder = false;
                pgnNodeRegistry[newNode.nodeId] = newNode;
                lastMoveNode = newNode;
            }
        } catch (e) { /* skip invalid move */ }

        i++;
    }
    return i;
}

// --- Move list rendering ---
function pgnAnnotationColor(annotation) {
    if (!annotation) return null;
    if (annotation.indexOf('??') !== -1) return '#ff4444'; // Blunder - red
    if (annotation.indexOf('?!') !== -1) return '#ffd700'; // Dubious - yellow
    if (annotation.indexOf('?') !== -1 && annotation.indexOf('!?') === -1) return '#ff69b4'; // Mistake - pink
    return null;
}

function pgnRenderMoveTable(rootNode) {
    var container = document.getElementById('pgnMoves');
    if (!container) return;
    container.innerHTML = '';
    if (rootNode.children && rootNode.children.length > 0) {
        pgnRenderMoveSequence(rootNode.children[0], container, true);
    } else {
        container.innerHTML = '<span style="color:#999;font-style:italic">Position loaded &#8212; no moves.</span>';
    }
}

function pgnRenderMoveSequence(startNode, container, isMainLine) {
    var node = startNode;
    var lastMoveNumber = 0;
    while (node) {
        if (!node.isBlackMove && node.moveNumber !== lastMoveNumber) {
            var numSpan = document.createElement('span');
            numSpan.className = 'move-number';
            numSpan.textContent = node.moveNumber + '. ';
            container.appendChild(numSpan);
            lastMoveNumber = node.moveNumber;
        } else if (node.isBlackMove && node === startNode) {
            // FEN start position with Black to move — show "N..." on the first move
            var bNumSpan = document.createElement('span');
            bNumSpan.className = 'move-number';
            bNumSpan.textContent = node.moveNumber + '... ';
            container.appendChild(bNumSpan);
            lastMoveNumber = node.moveNumber;
        }

        var moveSpan = document.createElement('span');
        moveSpan.className = isMainLine ? 'move-san main-line' : 'move-san';
        moveSpan.textContent = node.san + (node.annotation || '');
        moveSpan.dataset.nodeId = node.nodeId;
        moveSpan.onclick = function() { pgnJumpToNode(this.dataset.nodeId); };
        var color = pgnAnnotationColor(node.annotation);
        if (color) moveSpan.style.color = color;
        container.appendChild(moveSpan);
        pgnAppendEvalBadge(node, container);

        if (node.parent && node.parent.children.length > 1) {
            var siblingIndex = node.parent.children.indexOf(node);
            if (siblingIndex === 0) {
                for (var v = 1; v < node.parent.children.length; v++) {
                    pgnRenderVariation(node.parent.children[v], container);
                }
            }
        }

        if (node.children && node.children.length > 0) node = node.children[0];
        else break;
    }
}

function pgnRenderVariation(startNode, container) {
    var openParen = document.createElement('span');
    openParen.className = 'variation-container';
    openParen.textContent = '(';
    container.appendChild(openParen);

    var node = startNode;
    var isFirst = true;
    while (node) {
        if (isFirst) {
            var numSpan = document.createElement('span');
            numSpan.className = 'move-number';
            numSpan.textContent = node.isBlackMove ? node.moveNumber + '... ' : node.moveNumber + '. ';
            container.appendChild(numSpan);
            isFirst = false;
        } else if (!node.isBlackMove) {
            var wSpan = document.createElement('span');
            wSpan.className = 'move-number';
            wSpan.textContent = node.moveNumber + '. ';
            container.appendChild(wSpan);
        }

        var moveSpan = document.createElement('span');
        moveSpan.className = 'variation-move';
        moveSpan.textContent = node.san + (node.annotation || '');
        moveSpan.dataset.nodeId = node.nodeId;
        moveSpan.onclick = function() { pgnJumpToNode(this.dataset.nodeId); };
        var color = pgnAnnotationColor(node.annotation);
        if (color) moveSpan.style.color = color;
        container.appendChild(moveSpan);
        pgnAppendEvalBadge(node, container);
        container.appendChild(document.createTextNode(' '));

        if (node.children && node.children.length > 1) {
            for (var v = 1; v < node.children.length; v++) {
                pgnRenderVariation(node.children[v], container);
            }
        }

        if (node.children && node.children.length > 0) node = node.children[0];
        else break;
    }

    var closeParen = document.createElement('span');
    closeParen.className = 'variation-container';
    closeParen.textContent = ') ';
    container.appendChild(closeParen);
}

// --- Navigation ---
function pgnJumpToNode(nodeId) {
    var node = pgnNodeRegistry[nodeId];
    if (!node) return;
    pgnCurrent = node;
    if (pgnBoard) pgnBoard.position(node.fen);

    var cur = document.querySelectorAll('#pgnMoves .current');
    for (var i = 0; i < cur.length; i++) cur[i].classList.remove('current');
    var el = document.querySelector('#pgnMoves [data-node-id="' + nodeId + '"]');
    if (el) {
        el.classList.add('current');
        // Scroll only within the moves panel — never scroll the page (board stays visible)
        var movesEl = document.getElementById('pgnMoves');
        if (movesEl) {
            var er = el.getBoundingClientRect(), cr = movesEl.getBoundingClientRect();
            var relTop = er.top - cr.top, relBot = er.bottom - cr.top;
            if (relTop < 0) movesEl.scrollTop += relTop - 4;
            else if (relBot > movesEl.clientHeight) movesEl.scrollTop += relBot - movesEl.clientHeight + 4;
        }
    }
    document.getElementById('pgnComment').textContent = node.comment || '';
    var evEl = document.getElementById('pgnEval');
    if (evEl) {
        var curCp = pgnEvalCpForFen(node.fen);
        if (curCp !== null) {
            evEl.textContent = 'Eval: ' + pgnFormatEval(curCp) + (curCp > 0 ? ' (White)' : curCp < 0 ? ' (Black)' : '');
            evEl.style.color = curCp > 0 ? '#43a047' : (curCp < 0 ? '#e53935' : '#888');
        } else {
            evEl.textContent = '';
        }
    }
    pgnUpdateNavButtons();
}

function pgnGoPrev() { if (pgnCurrent && pgnCurrent.parent) pgnJumpToNode(pgnCurrent.parent.nodeId); }
function pgnGoNext() { if (pgnCurrent && pgnCurrent.children.length) pgnJumpToNode(pgnCurrent.children[0].nodeId); }
function pgnGoStart() { if (pgnRoot) pgnJumpToNode('root'); }
function pgnGoEnd() {
    var n = pgnCurrent;
    while (n && n.children.length) n = n.children[0];
    if (n) pgnJumpToNode(n.nodeId);
}
function pgnUpdateNavButtons() {
    var hasParent = !!(pgnCurrent && pgnCurrent.parent);
    var hasChild = !!(pgnCurrent && pgnCurrent.children.length);
    document.getElementById('pgnPrevBtn').disabled = !hasParent;
    document.getElementById('pgnStartBtn').disabled = !hasParent;
    document.getElementById('pgnNextBtn').disabled = !hasChild;
    document.getElementById('pgnEndBtn').disabled = !hasChild;
}

// --- Tab key: enter / exit variations ---
function pgnIsInVariation() {
    var n = pgnCurrent;
    while (n && n.parent) {
        if (n.parent.children[0] !== n) return true;
        n = n.parent;
    }
    return false;
}
// Find the outermost parent node where the variation chain departs from main line.
function pgnOutermostBranchParent() {
    var n = pgnCurrent, out = null;
    while (n && n.parent) {
        if (n.parent.children[0] !== n) out = n.parent;
        n = n.parent;
    }
    return out;
}
// Tab: if on main line with a variation available, enter it; otherwise no-op.
function pgnEnterVariation() {
    if (!pgnCurrent || !pgnCurrent.parent) return false;
    var p = pgnCurrent.parent;
    if (p.children[0] === pgnCurrent && p.children.length > 1) {
        pgnJumpToNode(p.children[1].nodeId);
        return true;
    }
    return false;
}
// Tab (when in variation): exit to the main-line move AFTER the variation ends.
function pgnExitVariationToNext() {
    if (!pgnIsInVariation()) return false;
    var bp = pgnOutermostBranchParent();
    if (!bp) return false;
    var mainMove = bp.children[0];
    if (mainMove && mainMove.children.length) {
        pgnJumpToNode(mainMove.children[0].nodeId);
    } else if (mainMove) {
        pgnJumpToNode(mainMove.nodeId);
    }
    return true;
}
// Shift+Tab: exit to the main-line branch-point move itself.
function pgnExitVariationToBranch() {
    if (!pgnIsInVariation()) return false;
    var bp = pgnOutermostBranchParent();
    if (!bp) return false;
    var mainMove = bp.children[0];
    if (mainMove) pgnJumpToNode(mainMove.nodeId);
    return true;
}

// Boot inline viewer on ?file=....pgn pages (PHP embedded the PGN text)
if (window._pgnInlineBoot) {
    pgnShowInline(window._pgnInlineBoot.name, window._pgnInlineBoot.text,
                  pgnAutoOpenIdx >= 0 ? pgnAutoOpenIdx : undefined);
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
        // Text files always open in the right pane, even when P2 is off
        var qs = href.indexOf('?') >= 0 ? href.substring(href.indexOf('?') + 1) : '';
        var hp = {};
        qs.split('&').forEach(function(pair) {
            var idx = pair.indexOf('=');
            if (idx > 0) hp[decodeURIComponent(pair.substring(0, idx))] = decodeURIComponent(pair.substring(idx + 1));
        });
        var fp = hp['file'];
        var textExts = ['txt','csv','json','log','md','docx','py'];
        if (fp && textExts.indexOf(fp.split('.').pop().toLowerCase()) !== -1) {
            var p = new URLSearchParams(window.location.search);
            p.set('p2', fp);
            window.location.href = '?' + p.toString();
            return true;
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

    // e — edit right-pane text file
    if (e.key === 'e' && !e.metaKey && !e.ctrlKey && !e.altKey) {
        if (typeof p2FilePath !== 'undefined' && p2FilePath && (p2DisplayType === 'text' || p2DisplayType === 'markdown')) {
            e.preventDefault();
            toggleP2Edit();
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
                        // Text files always route to the right pane, even when P2 is off
                        var destHref = fileNavBtn.getAttribute('href');
                        // Use URLSearchParams so + is decoded as space (decodeURIComponent does not do this)
                        var destParams = new URLSearchParams(destHref.indexOf('?') >= 0 ? destHref.substring(destHref.indexOf('?') + 1) : '');
                        var destFile = destParams.get('file');
                        var textExts = ['txt','csv','json','log','md','docx','py'];
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
                        // Media file → normal left-pane navigation
                        window.location.href = fileNavBtn.href;
                        return;
                    }
                }
            }
        }
    }

    if (evalPasteModalEl.classList.contains('open')) {
        if (e.key === 'Escape') toggleEvalPasteModal();
    } else if (pgnPasteModalEl.classList.contains('open')) {
        if (e.key === 'Escape') togglePgnPasteModal();
    } else if (pgnInlineActive) {
        if (e.key === 'ArrowLeft') { e.preventDefault(); pgnGoPrev(); }
        else if (e.key === 'ArrowRight') { e.preventDefault(); pgnGoNext(); }
        else if (e.key === 'Tab') {
            e.preventDefault();
            if (e.shiftKey) { pgnExitVariationToNext(); }
            else { if (!pgnEnterVariation()) pgnExitVariationToBranch(); }
        }
    } else if (modal.classList.contains('open')) {
        if (e.key === 'Escape') closeModal();
        else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') textFileNav(e.key, e);
    } else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
        textFileNav(e.key, e);
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
    }

    // TTS — works anywhere (not editing, no modifier keys)
    if (!e.metaKey && !e.ctrlKey && !e.altKey) {
        if (e.key === 'a' || e.key === 'm' || e.key === 'h' || e.key === 'k' || e.key === 'f' || e.key === 'r' || e.key === 'g' || e.key === 'b') {
            var ttsSel = window.getSelection();
            var ttsText = ttsSel ? ttsSel.toString().trim() : '';
            if (ttsText) {
                e.preventDefault();
                var ttsLangMap = { a: 'zh-HK', m: 'zh-CN', h: 'es-ES', k: 'ko-KR', f: 'fr-FR', r: 'en-US', g: 'el-GR', b: 'he-IL' };
                speakSelection(ttsText, ttsLangMap[e.key]);
            }
        }

        // j — CSV drill: speak first column (foreign) then last column (English)
        // Language for first column = first non-English visible in TTS settings
        if (e.key === 'j') {
            var jSel = window.getSelection();
            var jLine = jSel ? jSel.toString().trim() : '';
            if (jLine && speakCSVLine(jLine)) e.preventDefault();
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
            modalImageNav(dx < 0 ? 'ArrowRight' : 'ArrowLeft');
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
            try { localStorage.setItem('sidebarOpen', '0'); } catch(e) {}
        }
    });
});

// --- YouTube Music Modal ---
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

// =====================================================================
// SHORTCUTS REFERENCE — edit the template literal below freely.
// Rendered as Markdown. Reload the page after saving to see changes.
// =====================================================================
var shortcutsContent = `
## Navigation
- **← / →** — Previous / next text file (txt / md / docx only — opens in right pane)
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
- **‹ › buttons** or **swipe** — Prev / next image
- **← / →** — Prev / next text file (txt / md / docx), leaves the modal
- **Esc** — Close

## Dual Pane (P2)
- **P2 button** — Toggle left/right split view
- **1** — Single pane (close P2)
- **2** — Dual pane (open P2)
- Right pane shows text/md files; left pane shows media

## Text / Markdown
- **+  /  −** — Increase / decrease font size
- **TXT>MD** — Render plain .txt as Markdown
- **e** — Enter edit mode (✎ button)
- **Esc** — Save & exit edit mode
- **📋** — Copy raw content
- **,  /  .** — Select previous / next line; starts at line 1 if nothing highlighted (great for CSV row-by-row)

## YouTube Embed  *(open with **YT** button)*
- Paste any YouTube URL → Enter or **▶ Load**
- **y** — Seek to highlighted time in text (e.g. highlight \`1:23\` and press y)

## Text-to-Speech (highlight any text first)
- **TTS button** (header) — Choose which languages appear in the highlight tooltip
- **a** — Read selection in Cantonese (zh-HK)
- **m** — Read selection in Mandarin (zh-CN)
- **h** — Read selection in Spanish (es-ES)
- **k** — Read selection in Korean (ko-KR)
- **f** — Read selection in French (fr-FR)
- **r** — Read selection in English (en-US)
- **g** — Read selection in Greek (el-GR)
- **b** — Read selection in Hebrew (he-IL)

## CSV / Vocabulary Drill  *(navigate rows with  ,  /  .  then press)*
- **j** — Suggestopedia drill: speaks first CSV column in foreign language → 1.5 s pause → last column in English → 2.5 s pause. Language follows TTS settings (first non-English visible language)

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
document.addEventListener('click', function(e) {
    if (!servePanel.classList.contains('open')) return;
    if (e.target.closest('#servePanel') || e.target.closest('#servePanelBtn')) return;
    closeServePanel();
});
function updateServeCmd() {
    var path = document.getElementById('svPath').value || '.';
    document.getElementById('svCmd').textContent = 'cd ' + path;
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

// Parse a single CSV line respecting double-quoted fields (handles commas and
// semicolons inside quotes correctly).
// e.g. '"¿Hola, cómo estás?",Hello' → ['¿Hola, cómo estás?', 'Hello']
function parseCSVLine(line) {
    var fields = [];
    var i = 0;
    while (i <= line.length) {
        if (i === line.length) { break; } // no trailing empty field after closing quote
        if (line[i] === '"') {
            i++; // skip opening quote
            var field = '';
            while (i < line.length) {
                if (line[i] === '"' && line[i + 1] === '"') { field += '"'; i += 2; }
                else if (line[i] === '"') { i++; break; }
                else { field += line[i++]; }
            }
            fields.push(field.trim());
            if (line[i] === ',') i++;
        } else {
            var end = line.indexOf(',', i);
            if (end === -1) end = line.length;
            fields.push(line.substring(i, end).trim());
            i = end + 1;
        }
    }
    return fields;
}

// j — speak first CSV column in foreign language, last column in English.
// Foreign language = first non-English visible language in TTS settings.
function speakCSVLine(line) {
    var fields = parseCSVLine(line);
    if (fields.length < 2) return false;
    var foreign = fields[0];
    var english = fields[fields.length - 1];
    if (!foreign || !english || foreign === english) return false;

    // Determine foreign language from TTS settings (first non-English visible)
    var visMap = {};
    try { visMap = JSON.parse(localStorage.getItem('tts-visible-btns')) || {}; } catch(ex) {}
    // key → lang ordered by priority
    var candidates = [
        { key: 'M', lang: 'zh-CN' }, { key: 'A', lang: 'zh-HK' },
        { key: 'P', lang: 'es-ES' }, { key: 'K', lang: 'ko-KR' },
        { key: 'F', lang: 'fr-FR' },
    ];
    var foreignLang = 'zh-CN'; // fallback
    for (var ci = 0; ci < candidates.length; ci++) {
        var c = candidates[ci];
        if (!(c.key in visMap) || visMap[c.key]) { foreignLang = c.lang; break; }
    }

    speechSynthesis.cancel();
    var u1 = new SpeechSynthesisUtterance(foreign);
    u1.lang = foreignLang;
    var v1 = ttsBestVoice(foreignLang); if (v1) u1.voice = v1;
    var u2 = new SpeechSynthesisUtterance(english);
    u2.lang = 'en-US';
    var v2 = ttsBestVoice('en-US'); if (v2) u2.voice = v2;
    // Suggestopedia cadence: foreign → 1.5s gap → English → 2.5s gap
    u1.onend = function() {
        setTimeout(function() {
            speechSynthesis.speak(u2);
        }, 1500);
    };
    speechSynthesis.speak(u1);
    return true;
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
        { key: 'G', label: 'G Ελ', lang: 'el-GR', title: 'Greek'     },
        { key: 'B', label: 'B עב', lang: 'he-IL', title: 'Hebrew'    },
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
        if (e.target.closest && e.target.closest('#ttsTooltip, #ttsBtnSettings, #ttsSettingsBtn')) return;
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
        if (e.target.closest && (e.target.closest('#ttsTooltip') || e.target.closest('#ttsBtnSettings') || e.target.closest('#ttsSettingsBtn'))) return;
        hideTooltip();
        closeSettings();
    });

    // --- Settings panel ---
    function buildSettingsRows() {
        rowsEl.innerHTML = '';
        // Select All / Deselect All controls
        var ctrlRow = document.createElement('div');
        ctrlRow.style.cssText = 'display:flex;gap:6px;margin-bottom:8px;';
        var btnSelAll = document.createElement('button');
        btnSelAll.textContent = 'Select All';
        btnSelAll.style.cssText = 'flex:1;font-size:11px;font-weight:600;padding:3px 0;border:1px solid #555;border-radius:4px;background:#2a2a3e;color:#7ec8e3;cursor:pointer;';
        btnSelAll.addEventListener('click', function() {
            var v = loadVisible();
            TTS_LANGS.forEach(function(l) { v[l.key] = true; });
            saveVisible(v);
            rowsEl.querySelectorAll('input[type=checkbox]').forEach(function(cb) { cb.checked = true; });
        });
        var btnDeselAll = document.createElement('button');
        btnDeselAll.textContent = 'Deselect All';
        btnDeselAll.style.cssText = 'flex:1;font-size:11px;font-weight:600;padding:3px 0;border:1px solid #555;border-radius:4px;background:#2a2a3e;color:#aaa;cursor:pointer;';
        btnDeselAll.addEventListener('click', function() {
            var v = loadVisible();
            TTS_LANGS.forEach(function(l) { v[l.key] = false; });
            saveVisible(v);
            rowsEl.querySelectorAll('input[type=checkbox]').forEach(function(cb) { cb.checked = false; });
        });
        ctrlRow.appendChild(btnSelAll);
        ctrlRow.appendChild(btnDeselAll);
        rowsEl.appendChild(ctrlRow);

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

    function openSettings(anchorEl) {
        buildSettingsRows();
        settingsEl.classList.add('open');
        // Position below the anchor (tooltip gear → tooltip, header button → itself)
        var tr = anchorEl ? anchorEl.getBoundingClientRect() : tooltip.getBoundingClientRect();
        var pw = settingsEl.offsetWidth || 180;
        var left = Math.max(8, Math.min(tr.left, window.innerWidth - pw - 8));
        settingsEl.style.left = left + 'px';
        settingsEl.style.top  = (tr.bottom + 6) + 'px';
    }

    function closeSettings() {
        settingsEl.classList.remove('open');
    }

    document.getElementById('ttsBtnSettingsClose').addEventListener('click', closeSettings);

    // Header TTS button toggles the settings panel — always reachable,
    // even when every language is unchecked and the tooltip can't appear
    var headerBtn = document.getElementById('ttsSettingsBtn');
    if (headerBtn) {
        headerBtn.addEventListener('click', function() {
            if (settingsEl.classList.contains('open')) closeSettings();
            else openSettings(headerBtn);
        });
    }
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

// --- Writing panel ---
(function() {
    var panel = document.getElementById('writingPanel');
    var textarea = document.getElementById('writingTextarea');
    var fontSizeLabel = document.getElementById('writeFontSizeLabel');
    var marginLabel = document.getElementById('writeMarginLabel');
    var writeFontSize = 15;
    var writeMargin = 0;
    var writeOpen = false;

    try { writeFontSize = parseFloat(localStorage.getItem('write-font-size')) || 15; } catch(e) {}
    try { writeMargin = parseFloat(localStorage.getItem('write-margin')) || 0; } catch(e) {}
    try { textarea.value = localStorage.getItem('write-text') || ''; } catch(e) {}

    function applyWriteStyles() {
        var isDark = document.body.classList.contains('dark');
        panel.style.background = isDark ? '#1a1a2e' : '#ffffff';
        panel.style.borderTopColor = isDark ? '#4f46e5' : '#6366f1';
        textarea.style.background = isDark ? '#111827' : '#f9fafb';
        textarea.style.color = isDark ? '#e5e7eb' : '#111827';
        textarea.style.fontSize = writeFontSize + 'px';
        textarea.style.paddingLeft = 'calc(' + writeMargin + '% + 14px)';
        fontSizeLabel.textContent = writeFontSize;
        marginLabel.textContent = writeMargin + '%';
    }
    applyWriteStyles();

    textarea.addEventListener('input', function() {
        try { localStorage.setItem('write-text', textarea.value); } catch(e) {}
    });

    textarea.addEventListener('keydown', function(e) {
        if (e.key === 'Tab') {
            e.preventDefault();
            var pane = document.getElementById('contentArea');
            if (pane) pane.scrollBy({ top: pane.clientHeight * 0.15, behavior: 'smooth' });
        }
    });

    window.toggleWritePanel = function() {
        writeOpen = !writeOpen;
        panel.style.display = writeOpen ? 'block' : 'none';
        applyWriteStyles();
        var btn = document.getElementById('writeBtn');
        if (btn) {
            btn.textContent = writeOpen ? 'Write:ON' : 'Write';
            btn.style.background = writeOpen ? '#4338ca' : '#4f46e5';
        }
        if (writeOpen) setTimeout(function() { textarea.focus(); }, 60);
    };

    window.writeAdjFont = function(dir) {
        writeFontSize = Math.min(32, Math.max(10, writeFontSize + dir));
        try { localStorage.setItem('write-font-size', writeFontSize); } catch(e) {}
        applyWriteStyles();
    };

    window.writeAdjMargin = function(dir) {
        writeMargin = Math.min(70, Math.max(0, writeMargin + dir));
        try { localStorage.setItem('write-margin', writeMargin); } catch(e) {}
        applyWriteStyles();
    };

    window.writeCopy = function() {
        try { navigator.clipboard.writeText(textarea.value); } catch(e) {}
    };

    window.writeClear = function() {
        textarea.value = '';
        try { localStorage.removeItem('write-text'); } catch(e) {}
    };

    // Re-apply colours on dark mode toggle
    var origToggleDark = window.toggleDarkMode;
    window.toggleDarkMode = function() {
        if (origToggleDark) origToggleDark();
        applyWriteStyles();
    };
})();

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

// --- Copy content (right pane — text/md files always open in P2) ---
var rawContent = <?= ($displayType === 'text' || $displayType === 'markdown') ? json_encode($displayContent, JSON_HEX_TAG | JSON_HEX_AMP) : 'null' ?>;
function copyContent() {
    if (typeof p2RawContent === 'undefined' || p2RawContent === null) return;
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
            btn.innerHTML = '&#128203;Copy';
            btn.style.background = 'rgb(224,224,224)';
            btn.style.color = 'rgb(51,51,51)';
            if (document.body.classList.contains('dark')) {
                btn.style.background = '#555';
                btn.style.color = '#ffdd57';
            }
        }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(p2RawContent).then(onSuccess).catch(function() {
            if (fallback(p2RawContent)) onSuccess();
        });
    } else {
        if (fallback(p2RawContent)) onSuccess();
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

// --- Edit & Save (right pane — text/md files always open in P2) ---
var isEditing = false;
var editTextarea = null;
var currentFilePath = <?= $currentFile ? json_encode($currentFile, JSON_HEX_TAG | JSON_HEX_AMP) : 'null' ?>;
var currentDisplayType = <?= json_encode($displayType) ?>;

// Content element currently showing p2 text (respects TXT/MD toggle state)
function p2ContentEl() {
    var el = null;
    if (p2DisplayType === 'markdown') {
        el = rightShowMd ? document.getElementById('p2-md-render') : rightTxtEl;
    } else {
        el = rightShowMd ? rightMdEl : document.querySelector('#paneRight .text-content');
    }
    if (!el) el = document.querySelector('#paneRight .text-content') || document.getElementById('p2-md-render');
    return el;
}

function toggleP2Edit() {
    if (!p2FilePath || (p2DisplayType !== 'text' && p2DisplayType !== 'markdown')) return;
    var editBtn = document.getElementById('p2EditBtn');
    if (isEditing) {
        p2SaveAndExit(editBtn);
    } else {
        p2EnterEditMode(editBtn);
    }
}

function p2EnterEditMode(editBtn) {
    var contentEl = p2ContentEl();
    if (!contentEl) return;

    isEditing = true;
    editBtn.innerHTML = '&#128190;'; // floppy disk icon
    editBtn.title = 'Save changes';
    editBtn.style.background = '#4caf50';
    editBtn.style.color = '#fff';

    editTextarea = document.createElement('textarea');
    editTextarea.value = p2RawContent;
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
            p2SaveAndExit(editBtn);
        }
    });
    contentEl.style.display = 'none';
    contentEl.parentNode.insertBefore(editTextarea, contentEl.nextSibling);
    editTextarea.focus();
}

function p2SaveAndExit(editBtn) {
    if (!editTextarea) return;
    var newContent = editTextarea.value;
    editBtn.innerHTML = '&#8987;'; // hourglass
    editBtn.style.background = '#ff9800';

    fetch('?save=' + encodeURIComponent(p2FilePath), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ content: newContent })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) {
            p2RawContent = newContent;
            var textEl = document.querySelector('#paneRight .text-content');
            if (textEl) textEl.textContent = newContent;
            if (rightTxtEl) rightTxtEl.textContent = newContent;
            var mdEl = document.getElementById('p2-md-render');
            if (mdEl) {
                mdEl.innerHTML = marked.parse(newContent);
                mdEl.querySelectorAll('pre code').forEach(function(b) { hljs.highlightElement(b); });
            }
            editTextarea.remove();
            editTextarea = null;
            isEditing = false;
            // Restores the visible view per TXT/MD toggle state
            applyRightTxtMd(rightShowMd);
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
function pasteClipboardToP2() {
    navigator.clipboard.readText().then(function(clipText) {
        if (!clipText) { alert('Clipboard is empty.'); return; }
        var paneRight = document.getElementById('paneRight');

        // Remove any existing content div(s) inside paneRight (keep the pane-right-bar and page-down button)
        paneRight.querySelectorAll('.text-content, .markdown-content, .docx-content').forEach(function(el) { el.remove(); });
        // Also remove the empty-state placeholder if present
        paneRight.querySelectorAll('div[style*="pointer-events:none"]').forEach(function(el) { el.remove(); });

        // Remove existing pane-right-bar (we'll insert our own label)
        var existingBar = paneRight.querySelector('.pane-right-bar');
        if (existingBar) existingBar.remove();

        // Insert a minimal bar showing "(clipboard)"
        var bar = document.createElement('div');
        bar.className = 'pane-right-bar';
        bar.innerHTML = '<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-right:6px;opacity:0.7">(clipboard)</span>';
        paneRight.insertBefore(bar, paneRight.firstChild);

        // Insert text content div
        var textDiv = document.createElement('div');
        textDiv.className = 'text-content';
        textDiv.textContent = clipText;
        paneRight.appendChild(textDiv);

        // Apply current font size
        var fs = paneRight.style.fontSize;
        if (!fs) { try { fs = localStorage.getItem('fontSize'); } catch(e) {} }
        if (fs) textDiv.style.fontSize = fs;

        // Update JS state so scroll/TTS/line-nav work on the new content
        p2DisplayType = 'text';

        // Open P2 if not already open
        if (!splitMode) toggleSplit();
    }).catch(function() {
        alert('Clipboard access denied. Please allow clipboard permissions.');
    });
}

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

// --- Abort media streams before navigation ---
// A held-open video/audio range stream occupies the single-threaded PHP dev
// server, so every subsequent page load hangs ("stuck"). Tearing the element
// down on beforeunload makes the browser close the connection immediately.
window.addEventListener('beforeunload', function() {
    document.querySelectorAll('video, audio').forEach(function(m) {
        try { m.pause(); m.removeAttribute('src'); m.innerHTML = ''; m.load(); } catch(e) {}
    });
});

// --- Preserve pane-1 scroll position across page loads ---
// (clicking a text file, sidebar item, or using arrow nav reloads with ?p2=...; keep pane 1 where it was)
// When an image is the active file, skip restoration so scrollIntoView can scroll pane 1 to that image.
(function() {
    var area = document.getElementById('contentArea');
    if (!area) return;
    var key = 'gridScrollTop:' + <?= json_encode(($currentFolder ?: '') . '|' . $sortBy . '|' . (isset($_GET['view']) ? $_GET['view'] : '')) ?>;
    var saved = sessionStorage.getItem(key);
    var hasActiveImage = !!document.querySelector('#p1ImageList .p1-item.current');
    if (saved !== null && !hasActiveImage) {
        var target = parseInt(saved, 10);
        var restore = function() { area.scrollTop = target; };
        restore();
        // Re-apply as images/videos load and shift the grid layout
        window.addEventListener('load', restore);
        setTimeout(restore, 400);
        setTimeout(restore, 1200);
    }
    window.addEventListener('beforeunload', function() {
        sessionStorage.setItem(key, area.scrollTop);
    });
})();

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
// --- Pane divider drag-to-resize ---
(function() {
    var divider = document.getElementById('paneDivider');
    var leftPane = document.getElementById('contentArea');
    var rightPane = document.getElementById('paneRight');
    var container = document.getElementById('panesContainer');
    if (!divider) return;

    // Restore saved ratio
    try {
        var saved = localStorage.getItem('paneSplitRatio');
        if (saved) {
            var ratio = parseFloat(saved);
            if (ratio > 0.1 && ratio < 0.9) {
                leftPane.style.flex = 'none';
                rightPane.style.flex = 'none';
                leftPane.style.width = (ratio * 100) + '%';
                rightPane.style.width = ((1 - ratio) * 100) + '%';
            }
        }
    } catch(e) {}

    divider.addEventListener('mousedown', function(e) {
        e.preventDefault();
        divider.classList.add('dragging');
        var startX = e.clientX;
        var containerW = container.offsetWidth - divider.offsetWidth;
        var startLeftW = leftPane.offsetWidth;

        function onMove(e) {
            var dx = e.clientX - startX;
            var newLeft = Math.min(Math.max(startLeftW + dx, containerW * 0.1), containerW * 0.9);
            var newRight = containerW - newLeft;
            leftPane.style.flex = 'none';
            rightPane.style.flex = 'none';
            leftPane.style.width = newLeft + 'px';
            rightPane.style.width = newRight + 'px';
        }
        function onUp() {
            divider.classList.remove('dragging');
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            // Save ratio
            try {
                var ratio = leftPane.offsetWidth / (leftPane.offsetWidth + rightPane.offsetWidth);
                localStorage.setItem('paneSplitRatio', ratio);
            } catch(e) {}
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    });

    // Double-click resets to default 3:5 ratio
    divider.addEventListener('dblclick', function() {
        leftPane.style.flex = '3';
        leftPane.style.width = '';
        rightPane.style.flex = '5';
        rightPane.style.width = '';
        try { localStorage.removeItem('paneSplitRatio'); } catch(e) {}
    });
})();

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
var p2FilePath = <?= $p2File ? json_encode($p2File, JSON_HEX_TAG | JSON_HEX_AMP) : 'null' ?>;
var p2DisplayType = <?= json_encode($p2DisplayType) ?>;
var p2RawContent = <?= ($p2DisplayType === 'text' || $p2DisplayType === 'markdown') ? json_encode($p2DisplayContent, JSON_HEX_TAG | JSON_HEX_AMP) : 'null' ?>;
var rightShowMd = p2DisplayType === 'markdown';
var rightMdEl = null;
var rightTxtEl = null;

function applyRightTxtMd(showMd) {
    var btn = document.getElementById('p2TxtMdBtn');
    if (!btn || p2RawContent === null) return;
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
        btn.style.background = 'rgb(224,224,224)'; btn.style.color = 'rgb(51,51,51)';
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

// Text files only open in the right pane (P2) — intercept ALL internal text-file
// links, regardless of split state. (href must start with '?' so external links
// rendered inside markdown are never hijacked.)
document.addEventListener('click', function(e) {
    var a = e.target.closest ? e.target.closest('a') : null;
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (href.charAt(0) !== '?' || href.indexOf('file=') === -1) return;
    var qs = href.substring(1);
    var fp = null;
    qs.split('&').forEach(function(pair) {
        var i = pair.indexOf('=');
        if (i > 0 && decodeURIComponent(pair.substring(0, i)) === 'file') fp = decodeURIComponent(pair.substring(i + 1));
    });
    if (!fp) return;
    var textExts = ['txt','csv','json','log','md','docx','py'];
    if (textExts.indexOf(fp.split('.').pop().toLowerCase()) === -1) return;
    e.preventDefault();
    var p = new URLSearchParams(window.location.search);
    p.set('p2', fp);
    window.location.href = '?' + p.toString();
}, true);

// Text files live in the right pane only — a p2 param means split must be ON,
// even if the user previously toggled P2 off.
(function() {
    if (splitMode) return;
    if (!new URLSearchParams(window.location.search).get('p2')) return;
    splitMode = true;
    try { localStorage.setItem('splitMode', '1'); } catch(e) {}
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
        if (hp['file'] === p2File) {
            item.classList.add('p2-active');
            item.scrollIntoView({ block: 'nearest' });
        }
    });
})();
</script>
<script src="https://www.youtube.com/iframe_api"></script>
</body>
</html>
