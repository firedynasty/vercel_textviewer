<?php
// --- Multi-content dir support: pick up ALL subdirectories ---
$allContentDirs = [];
foreach (scandir('.') as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    if (substr($entry, 0, 1) === '.') continue; // skip hidden dirs
    if (is_dir('./' . $entry)) $allContentDirs[] = './' . $entry;
}
sort($allContentDirs);
if (empty($allContentDirs)) $allContentDirs = ['./content'];

// Validate ?dir= against whitelist to prevent path traversal
$requestedDir = isset($_GET['dir']) ? $_GET['dir'] : '';
$contentDir = in_array($requestedDir, $allContentDirs) ? $requestedDir : $allContentDirs[0];
$dirKey = ltrim($contentDir, './'); // e.g. "content" or "content2"

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

// Build URLs preserving dir + sort
function sortUrl($sort) {
    global $contentDir, $allContentDirs;
    $params = $_GET;
    $params['sort'] = $sort;
    $params['dir'] = $contentDir;
    return '?' . http_build_query($params);
}

function itemUrl($params) {
    global $sortBy, $contentDir;
    $params['sort'] = $sortBy;
    $params['dir']  = $contentDir;
    return '?' . http_build_query($params);
}

function dirUrl($dir, $sortBy) {
    return '?' . http_build_query(['dir' => $dir, 'sort' => $sortBy]);
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
        $textExts  = ['txt','md','csv','json','log'];
        if (in_array($ext, $imageExts))             { $displayType = 'image'; $displayContent = $filePath; }
        elseif ($ext === 'html' || $ext === 'htm')  { $displayType = 'html';  $displayContent = $filePath; }
        elseif ($ext === 'pdf')                     { $displayType = 'pdf';   $displayContent = $filePath; }
        elseif ($ext === 'docx')                    { $displayType = 'docx';  $displayContent = $filePath; }
        elseif ($ext === 'rtf')                     { $displayType = 'rtf';   $displayContent = $filePath; }
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

/* Dir tabs */
.dir-tabs {
    display: flex;
    flex-wrap: wrap;
    border-bottom: 1px solid #333;
    background: #111122;
}
.dir-tab {
    padding: 5px 10px;
    font-size: 11px;
    text-decoration: none;
    color: #888;
    border-right: 1px solid #333;
    white-space: nowrap;
}
.dir-tab:hover { background: #16213e; color: #ccc; }
.dir-tab.active { color: #7ec8e3; background: #16213e; }

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
.docx-content {
    background: #fff; padding: 30px; border-radius: 4px;
    font-size: 15px; line-height: 1.7; box-shadow: 0 2px 8px rgba(0,0,0,0.1); max-width: 800px;
}
.docx-content img { max-width: 100%; }
.content-area iframe { width: 100%; height: 80vh; border: none; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }

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
            <span><?= htmlspecialchars($dirKey) ?></span>
            <span style="font-size:11px;color:#888"><?= count($items) ?> items</span>
        <?php endif; ?>
    </div>

    <?php if (count($allContentDirs) > 1): ?>
    <div class="dir-tabs">
        <?php foreach ($allContentDirs as $d): ?>
            <a class="dir-tab <?= ($d === $contentDir ? 'active' : '') ?>"
               href="<?= dirUrl($d, $sortBy) ?>">
                <?= htmlspecialchars(ltrim($d, './')) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

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
</script>
</body>
</html>
