#!/usr/bin/env python3
"""
Process content files from folder_w_text/ and generate content_data.js

Supports:
- .txt files (plain text)
- .md files (markdown)
- .mp3, .m4a, .wav, .ogg, .flac files (audio)
- .png, .jpg, .jpeg, .gif, .webp, .svg files (image)

Usage: python process_content.py [-i INPUT_DIR] [-o OUTPUT_FILE]
"""

import os
import re
import argparse
import json


def natural_sort_key(s):
    """Sort strings containing numbers in natural order."""
    return [int(text) if text.isdigit() else text.lower()
            for text in re.split(r'(\d+)', s)]


def get_file_type(filename):
    """Determine file type from extension."""
    ext = os.path.splitext(filename)[1].lower()

    if ext == '.md':
        return 'markdown'
    elif ext == '.txt':
        return 'text'
    elif ext in ['.mp3', '.m4a', '.wav', '.ogg', '.flac', '.aac', '.webm']:
        return 'audio'
    elif ext in ['.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg', '.bmp']:
        return 'image'
    else:
        return None


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
    supported_files.sort(key=natural_sort_key)

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
                print(f"  [TEXT] {filename}")
            except Exception as e:
                print(f"  [ERROR] {filename}: {e}")
                file_entry['content'] = f"Error reading file: {e}"
        elif file_type == 'audio':
            print(f"  [AUDIO] {filename}")
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
