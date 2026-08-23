#!/usr/bin/env python3
"""
Process content files from folder_w_text/ and generate content_data.js

Supports:
- .txt files (plain text)
- .md files (markdown)
- .docx files (Word documents, converted to markdown with images extracted)
- .mp3, .m4a, .wav, .ogg, .flac, .aac files (audio)
- .mp4, .webm, .mov, .avi, .mkv, .m4v files (video)
- .png, .jpg, .jpeg, .gif, .webp, .svg files (image)

Usage: python process_content.py [-i INPUT_DIR] [-o OUTPUT_FILE]
"""

import os
import re
import argparse
import json
from urllib.parse import quote


def natural_sort_key(s):
    """Sort strings containing numbers in natural order."""
    return [int(text) if text.isdigit() else text.lower()
            for text in re.split(r'(\d+)', s)]


def sort_key_text_first(filename):
    """Sort by leading number group, with text/md/docx files first within each group.

    Files sharing the same leading number string (e.g. '001', '01', '02') are
    grouped together. Within each group, text-type files sort before media files.
    Leading zeros are preserved so '001' and '01' stay in separate groups.
    Files with no leading number go to the end.
    """
    parts = natural_sort_key(filename)
    ext = os.path.splitext(filename)[1].lower()
    # 0 = text types (float to top), 1 = everything else
    type_priority = 0 if ext in ('.txt', '.md', '.docx') else 1
    # Extract leading digit string, preserving leading zeros for grouping
    m = re.match(r'^(\d+)', filename)
    leading_str = m.group(1) if m else ''
    leading_int = int(leading_str) if leading_str else 99999
    return (leading_int, leading_str, type_priority) + tuple(parts)


def get_file_type(filename):
    """Determine file type from extension."""
    ext = os.path.splitext(filename)[1].lower()

    if ext == '.md':
        return 'markdown'
    elif ext == '.txt':
        return 'text'
    elif ext == '.docx':
        return 'docx'
    elif ext in ['.mp3', '.m4a', '.wav', '.ogg', '.flac', '.aac']:
        return 'audio'
    elif ext in ['.mp4', '.webm', '.mov', '.avi', '.mkv', '.m4v']:
        return 'video'
    elif ext in ['.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg', '.bmp']:
        return 'image'
    else:
        return None


def extract_docx_images(doc, input_dir, docx_name):
    """Extract embedded images from a docx and save to disk.

    Returns a dict of rId -> relative path (relative to input_dir).
    """
    images_dir = os.path.join(input_dir, 'docx_images', docx_name)
    os.makedirs(images_dir, exist_ok=True)

    ext_map = {
        'image/jpeg': 'jpg',
        'image/png': 'png',
        'image/gif': 'gif',
        'image/webp': 'webp',
        'image/bmp': 'bmp',
        'image/svg+xml': 'svg',
        'image/tiff': 'tif',
        'image/wmf': 'wmf',
        'image/emf': 'emf',
    }

    image_map = {}
    for rId, rel in doc.part.rels.items():
        if 'image' not in rel.reltype:
            continue
        try:
            img_data = rel.target_part.blob
            content_type = rel.target_part.content_type
            ext = ext_map.get(content_type, 'png')
            img_filename = f"{rId}.{ext}"
            img_path = os.path.join(images_dir, img_filename)
            with open(img_path, 'wb') as f:
                f.write(img_data)
            # Path relative to input_dir so rewrite_image_paths() can adjust it
            image_map[rId] = f'./docx_images/{docx_name}/{img_filename}'
        except Exception as e:
            print(f"  [WARN] Could not extract image {rId}: {e}")

    return image_map


def process_docx_file(file_path, input_dir):
    """Convert a .docx file to a markdown string, extracting images to disk."""
    try:
        from docx import Document
    except ImportError:
        return '*python-docx not installed. Run: pip install python-docx*'

    docx_name = os.path.splitext(os.path.basename(file_path))[0]
    # Sanitise name for use as a directory name
    docx_name = re.sub(r'[^\w\-]', '_', docx_name)

    doc = Document(file_path)
    image_map = extract_docx_images(doc, input_dir, docx_name)

    # Namespaces needed to find image references inside paragraph XML
    A_NS = 'http://schemas.openxmlformats.org/drawingml/2006/main'
    R_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'

    def para_images(para):
        blips = para._element.findall('.//{%s}blip' % A_NS)
        return [image_map[b.get('{%s}embed' % R_NS)]
                for b in blips
                if b.get('{%s}embed' % R_NS) in image_map]

    lines = []
    for para in doc.paragraphs:
        style = para.style.name if para.style else ''
        text = para.text.strip()
        imgs = para_images(para)

        if style.startswith('Heading 1') and text:
            lines.append(f'# {text}')
        elif style.startswith('Heading 2') and text:
            lines.append(f'## {text}')
        elif style.startswith('Heading 3') and text:
            lines.append(f'### {text}')
        elif style.startswith('Heading 4') and text:
            lines.append(f'#### {text}')
        elif text:
            lines.append(text)

        for img_path in imgs:
            lines.append(f'![image]({img_path})')

    return '\n\n'.join(lines)


def filename_to_title(filename):
    """Convert filename to display title."""
    name = os.path.splitext(filename)[0]
    # Replace underscores and hyphens with spaces
    title = name.replace('_', ' ').replace('-', ' ')
    return title


def filename_to_key(filename):
    """Convert filename to a safe key."""
    name = os.path.splitext(filename)[0]
    # Keep alphanumeric, underscores, hyphens
    key = re.sub(r'[^a-zA-Z0-9_-]', '_', name)
    return key.lower()


def rewrite_image_paths(content, input_dir):
    """Rewrite relative image paths in markdown so they resolve from the HTML page.

    Markdown files use paths relative to their own location (e.g., ./images/foo.png),
    but when rendered in the browser, paths resolve relative to the HTML page which
    is one level up. This prepends the input_dir to relative paths.

    Also URL-encodes spaces and special characters so marked.js parses them correctly.
    """
    def rewrite_match(match):
        alt = match.group(1)
        path = match.group(2)
        # Skip absolute URLs and data URIs
        if re.match(r'^(https?://|data:|/)', path):
            return match.group(0)
        # Already starts with input_dir, just encode it
        if path.startswith(input_dir):
            encoded_path = quote(path, safe='/.%')
            return f'![{alt}]({encoded_path})'
        # Join with input_dir and normalize
        new_path = os.path.normpath(os.path.join(input_dir, path))
        # Ensure ./ prefix for consistency
        if not new_path.startswith('.'):
            new_path = './' + new_path
        # URL-encode spaces and special chars (keep / . % safe)
        encoded_path = quote(new_path, safe='/.%')
        return f'![{alt}]({encoded_path})'

    content = re.sub(r'!\[([^\]]*)\]\(([^)]+)\)', rewrite_match, content)
    return content


def extract_youtube_links(files_data):
    """Extract YouTube URLs with timestamps from text/markdown file contents."""
    youtube_re = re.compile(
        r'(?:https?://)?(?:www\.)?'
        r'(?:youtube\.com/(?:watch\?v=|shorts/)|youtu\.be/)'
        r'([A-Za-z0-9_-]{11})'
    )
    youtube_links = []

    for file_entry in files_data:
        if file_entry['type'] not in ('text', 'markdown') or not file_entry['content']:
            continue

        lines = file_entry['content'].splitlines()
        in_code_block = False

        for i, line in enumerate(lines):
            # Track fenced code blocks
            if line.strip().startswith('```'):
                in_code_block = not in_code_block
                continue
            if in_code_block:
                continue

            m = youtube_re.search(line)
            if not m:
                continue

            video_id = m.group(1)
            # Get full URL from match start to end of URL portion
            url_start = m.start()
            # Extract the URL and any trailing timestamps
            rest = line[url_start:]
            # Split on whitespace to isolate the URL+timestamps token
            token = rest.split()[0] if rest.split() else rest
            # URL is everything up to the end of the video_id
            vid_end = token.find(video_id) + len(video_id)
            url = token[:vid_end]
            if not url.startswith('http'):
                url = 'https://' + url
            # Everything after video_id is potential timestamps, split by , or ?
            after_vid = token[vid_end:]
            time_parts = re.split(r'[,?]', after_vid)
            times = [t.strip() for t in time_parts if re.match(r'^\d+:\d{2}$', t.strip())]

            # Extract hint from line above (skip blank lines)
            hint = ''
            for j in range(i - 1, -1, -1):
                candidate = lines[j].strip()
                if candidate.startswith('```'):
                    break
                if candidate and not youtube_re.search(candidate):
                    hint = candidate
                    break

            youtube_links.append({
                'url': url,
                'video_id': video_id,
                'hint': hint,
                'source_file': file_entry['filename'],
                'times': times,
            })

    return youtube_links


def process_content_folder(input_dir, output_file):
    """Scan input directory and generate content_data.js."""

    if not os.path.isdir(input_dir):
        print(f"Error: Input directory '{input_dir}' does not exist.")
        return False

    files_data = []

    # Get all files in the directory
    all_files = os.listdir(input_dir)

    # Filter to supported file types
    supported_files = []
    for f in all_files:
        if os.path.isfile(os.path.join(input_dir, f)) and get_file_type(f):
            supported_files.append(f)

    # Sort naturally
    supported_files.sort(key=sort_key_text_first)

    print(f"Found {len(supported_files)} supported files:")

    for filename in supported_files:
        filepath = os.path.join(input_dir, filename)
        file_type = get_file_type(filename)

        file_entry = {
            'key': filename_to_key(filename),
            'filename': filename,
            'title': filename_to_title(filename),
            'type': file_type,
            'path': f"{input_dir}/{filename}",
            'content': None
        }

        # Read content for text and markdown files
        if file_type in ['text', 'markdown']:
            try:
                with open(filepath, 'r', encoding='utf-8') as f:
                    file_entry['content'] = f.read()
                # Rewrite relative image paths for markdown files
                if file_type == 'markdown':
                    file_entry['content'] = rewrite_image_paths(
                        file_entry['content'], input_dir
                    )
                print(f"  [TEXT] {filename}")
            except Exception as e:
                print(f"  [ERROR] {filename}: {e}")
                file_entry['content'] = f"Error reading file: {e}"
        elif file_type == 'docx':
            try:
                content = process_docx_file(filepath, input_dir)
                # Rewrite image paths so they resolve from the HTML page
                file_entry['content'] = rewrite_image_paths(content, input_dir)
                # Render as markdown in the viewer
                file_entry['type'] = 'markdown'
                print(f"  [DOCX] {filename}")
            except Exception as e:
                print(f"  [ERROR] {filename}: {e}")
                file_entry['content'] = f"Error reading file: {e}"
        elif file_type == 'audio':
            print(f"  [AUDIO] {filename}")
        elif file_type == 'video':
            print(f"  [VIDEO] {filename}")
        elif file_type == 'image':
            print(f"  [IMAGE] {filename}")

        files_data.append(file_entry)

    # Extract YouTube links from text/markdown files
    youtube_links = extract_youtube_links(files_data)
    if youtube_links:
        print(f"\nFound {len(youtube_links)} YouTube link(s)")

    # Generate JavaScript output
    js_content = f"""// Auto-generated by process_content.py
// Do not edit manually - regenerate with: python process_content.py

const contentData = {{
  files: {json.dumps(files_data, indent=2, ensure_ascii=False)},
  youtube_links: {json.dumps(youtube_links, indent=2, ensure_ascii=False)}
}};
"""

    # Write output file
    with open(output_file, 'w', encoding='utf-8') as f:
        f.write(js_content)

    print(f"\nGenerated: {output_file}")
    print(f"Total files: {len(files_data)}")

    return True


def main():
    parser = argparse.ArgumentParser(
        description='Process content files and generate content_data.js'
    )
    parser.add_argument(
        '-i', '--input',
        default='./contents_folder',
        help='Input directory containing content files (default: ./contents_folder)'
    )
    parser.add_argument(
        '-o', '--output',
        default='./content_data.js',
        help='Output JavaScript file (default: ./content_data.js)'
    )

    args = parser.parse_args()

    success = process_content_folder(args.input, args.output)
    return 0 if success else 1


if __name__ == '__main__':
    exit(main())
