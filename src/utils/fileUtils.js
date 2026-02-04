// File type detection utilities

const VIDEO_EXTENSIONS = ['.mp4', '.webm', '.ogg', '.mov', '.avi', '.mkv', '.m4v'];
const IMAGE_EXTENSIONS = ['.jpg', '.jpeg', '.png', '.gif', '.bmp', '.webp', '.tiff', '.svg'];
const TEXT_EXTENSIONS = ['.txt', '.rtf'];
const MARKDOWN_EXTENSIONS = ['.md'];
const PDF_EXTENSIONS = ['.pdf'];
const AUDIO_EXTENSIONS = ['.mp3', '.m4a', '.wav', '.ogg', '.aac', '.flac'];

export function isVideoFile(filename) {
  const lower = filename.toLowerCase();
  return VIDEO_EXTENSIONS.some(ext => lower.endsWith(ext));
}

export function isImageFile(filename) {
  const lower = filename.toLowerCase();
  return IMAGE_EXTENSIONS.some(ext => lower.endsWith(ext));
}

export function isTextFile(filename) {
  const lower = filename.toLowerCase();
  return TEXT_EXTENSIONS.some(ext => lower.endsWith(ext));
}

export function isMarkdownFile(filename) {
  const lower = filename.toLowerCase();
  return MARKDOWN_EXTENSIONS.some(ext => lower.endsWith(ext));
}

export function isPdfFile(filename) {
  const lower = filename.toLowerCase();
  return PDF_EXTENSIONS.some(ext => lower.endsWith(ext));
}

export function isAudioFile(filename) {
  const lower = filename.toLowerCase();
  return AUDIO_EXTENSIONS.some(ext => lower.endsWith(ext));
}

export function isRtfFile(filename) {
  return filename.toLowerCase().endsWith('.rtf');
}

export function isValidFile(filename) {
  return isVideoFile(filename) || isImageFile(filename) || isTextFile(filename) || isMarkdownFile(filename) || isPdfFile(filename) || isAudioFile(filename);
}

export function getFileType(filename) {
  if (isMarkdownFile(filename)) return 'markdown';
  if (isRtfFile(filename)) return 'rtf';
  if (isTextFile(filename)) return 'text';
  if (isVideoFile(filename)) return 'video';
  if (isImageFile(filename)) return 'image';
  if (isPdfFile(filename)) return 'pdf';
  if (isAudioFile(filename)) return 'audio';
  return 'unknown';
}

export function getFileExtension(filename) {
  const match = filename.match(/\.[^.]+$/);
  return match ? match[0].toLowerCase() : '';
}

export function getDisplayName(filename) {
  // Remove extension and return clean display name
  return filename.replace(/\.[^.]+$/, '');
}

// RTF to plain text converter (fallback for when rtf-parser fails)
export function rtfToPlainText(rtf) {
  // Check if this is actually RTF content
  if (!rtf.trim().startsWith('{\\rtf')) {
    return rtf; // Not RTF, return as-is
  }

  // Remove nested groups (like fonttbl, colortbl, etc.) by counting braces
  function removeNestedGroups(text, groupName) {
    const pattern = new RegExp('\\{\\\\' + groupName, 'gi');
    let result = '';
    let i = 0;
    while (i < text.length) {
      const match = text.slice(i).match(pattern);
      if (match && text.slice(i).indexOf(match[0]) === 0) {
        // Found a group to remove, skip until balanced braces
        let depth = 1;
        i += match[0].length;
        while (i < text.length && depth > 0) {
          if (text[i] === '{') depth++;
          else if (text[i] === '}') depth--;
          i++;
        }
      } else {
        result += text[i];
        i++;
      }
    }
    return result;
  }

  let text = rtf;

  // Remove various RTF groups that don't contain readable text
  const groupsToRemove = [
    'fonttbl', 'colortbl', 'stylesheet', 'listtable', 'listoverridetable',
    'info', 'expandedcolortbl', '\\*\\expandedcolortbl', '\\*\\listtable',
    '\\*\\listoverridetable', 'listtext'
  ];
  for (const group of groupsToRemove) {
    text = removeNestedGroups(text, group.replace(/\\/g, '\\\\'));
  }

  // Remove {\*\...} destination groups
  text = text.replace(/\{\\\*\\[^{}]*\}/g, '');

  // Handle hex characters (\'XX)
  text = text.replace(/\\'([0-9a-fA-F]{2})/g, (match, hex) => {
    const code = parseInt(hex, 16);
    // Map Windows-1252 special chars to Unicode
    const win1252Map = {
      0x92: "'", 0x93: '"', 0x94: '"', 0x96: '–', 0x97: '—',
      0x85: '…', 0x91: "'", 0xa0: ' '
    };
    return win1252Map[code] || String.fromCharCode(code);
  });

  // Handle Unicode characters (\uN followed by replacement char)
  text = text.replace(/\\u(-?\d+)[\s\\]?/g, (match, code) => {
    const charCode = parseInt(code);
    if (charCode < 0) return String.fromCharCode(charCode + 65536);
    return String.fromCharCode(charCode);
  });

  // Replace RTF control words with their meaning
  text = text.replace(/\\par\b/g, '\n');
  text = text.replace(/\\line\b/g, '\n');
  text = text.replace(/\\tab\b/g, '\t');
  text = text.replace(/\\\n/g, '\n');
  text = text.replace(/\\~/g, ' ');
  text = text.replace(/\\_/g, '-');
  text = text.replace(/\\\{/g, '{');
  text = text.replace(/\\\}/g, '}');
  text = text.replace(/\\\\/g, '\\');

  // Remove all other control words
  text = text.replace(/\\[a-z]+(-?\d+)?\s?/gi, '');

  // Remove remaining braces
  text = text.replace(/[{}]/g, '');

  // Clean up whitespace
  text = text.replace(/\r\n/g, '\n');
  text = text.replace(/\r/g, '\n');
  text = text.replace(/\n{3,}/g, '\n\n');
  text = text.replace(/[ \t]+/g, ' ');
  text = text.replace(/^ +/gm, '');
  text = text.trim();

  return text;
}

// Process files from drag-drop or folder input
export function processFiles(files) {
  const filesArray = Array.from(files);

  // Filter for valid files
  const validFiles = filesArray.filter(file => isValidFile(file.name));

  if (validFiles.length === 0) {
    return { files: [], error: 'No valid files found. Please upload images, videos, text, or markdown files.' };
  }

  // Organize files by subdirectory
  const filesByFolder = {};
  const rootFiles = [];
  const imagePathToBlobUrl = {};
  const MAX_DEPTH = 2;

  validFiles.forEach(file => {
    const relativePath = file.webkitRelativePath || file.name;
    const pathParts = relativePath.split('/');

    // Store blob URLs for images (for markdown image references)
    if (isImageFile(file.name)) {
      const blobUrl = URL.createObjectURL(file);
      const relativePathFromRoot = pathParts.length > 1 ? pathParts.slice(1).join('/') : file.name;
      // Store multiple path variants to handle different markdown references
      imagePathToBlobUrl[relativePathFromRoot] = blobUrl;
      imagePathToBlobUrl['./' + relativePathFromRoot] = blobUrl;
      // Also store URL-encoded versions (spaces as %20)
      const encodedPath = relativePathFromRoot.replace(/ /g, '%20');
      imagePathToBlobUrl[encodedPath] = blobUrl;
      imagePathToBlobUrl['./' + encodedPath] = blobUrl;
    }

    if (pathParts.length <= 2) {
      // Root file or file in root of selected folder
      rootFiles.push(file);
    } else {
      const depth = pathParts.length - 2;
      if (depth <= MAX_DEPTH) {
        const subfolderPath = pathParts.slice(1, -1).join('/');
        if (!filesByFolder[subfolderPath]) {
          filesByFolder[subfolderPath] = [];
        }
        filesByFolder[subfolderPath].push(file);
      }
    }
  });

  // Build the gallery items array
  const galleryItems = [];
  let itemIndex = 0;

  // Natural sort function
  const naturalSort = (a, b) => {
    const aName = a.name || a.webkitRelativePath.split('/').pop();
    const bName = b.name || b.webkitRelativePath.split('/').pop();
    return aName.localeCompare(bName, undefined, { numeric: true, sensitivity: 'base' });
  };

  // Sort folder names
  const folderNames = Object.keys(filesByFolder).sort((a, b) =>
    a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' })
  );

  // Add root files first
  if (rootFiles.length > 0) {
    // Add divider for root files if there are also folders
    if (folderNames.length > 0) {
      galleryItems.push({
        key: './',
        type: 'divider',
        url: null
      });
    }

    rootFiles.sort(naturalSort);

    rootFiles.forEach(file => {
      const objectURL = URL.createObjectURL(file);
      const displayName = getDisplayName(file.name);
      const fileType = getFileType(file.name);

      galleryItems.push({
        key: `${++itemIndex}_${displayName}`,
        url: objectURL,
        type: fileType,
        file: file,
        originalName: file.name
      });
    });
  }

  // Add files by folder
  folderNames.forEach(folderName => {
    const folderFiles = filesByFolder[folderName];
    folderFiles.sort(naturalSort);

    // Add divider
    galleryItems.push({
      key: folderName,
      type: 'divider',
      url: null
    });

    // Add files
    folderFiles.forEach(file => {
      const objectURL = URL.createObjectURL(file);
      const displayName = getDisplayName(file.name);
      const fileType = getFileType(file.name);

      galleryItems.push({
        key: `${++itemIndex}_${displayName}`,
        url: objectURL,
        type: fileType,
        file: file,
        originalName: file.name
      });
    });
  });

  return {
    files: galleryItems,
    imagePathToBlobUrl,
    error: null
  };
}
