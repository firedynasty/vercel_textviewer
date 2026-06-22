#!/usr/bin/env python3
"""Convert imagepaths.txt to public/imagepaths.json.

Usage:
  python generate_imagepaths.py
  python generate_imagepaths.py -i imagepaths.txt -o public/imagepaths.json

Each line is a Dropbox URL like:
  https://www.dropbox.com/home/images/art

Output is JSON array of {label, path} objects:
  [{"label": "images/art", "path": "/images/art"}]
"""

import argparse
import json
from pathlib import Path
from urllib.parse import urlparse, unquote


def dropbox_url_to_path(url):
    """Extract Dropbox API path from a Dropbox URL.
    https://www.dropbox.com/home/images/art -> /images/art
    """
    parsed = urlparse(url)
    raw = unquote(parsed.path)
    # Remove /home prefix
    if raw.startswith('/home/'):
        raw = raw[len('/home'):]
    elif raw.startswith('/home'):
        raw = raw[len('/home'):] or '/'
    return raw


def make_label(path):
    """Last 2 path segments as label."""
    parts = path.strip('/').split('/')
    return '/'.join(parts[-2:]) if len(parts) >= 2 else parts[-1]


def main():
    parser = argparse.ArgumentParser(description='Convert imagepaths.txt to JSON')
    parser.add_argument('-i', '--input', default='imagepaths.txt',
                        help='Input text file (default: imagepaths.txt)')
    parser.add_argument('-o', '--output', default='public/imagepaths.json',
                        help='Output JSON file (default: public/imagepaths.json)')
    args = parser.parse_args()

    input_path = Path(args.input)
    if not input_path.exists():
        print(f'Error: {input_path} not found')
        return 1

    entries = []
    with open(input_path, 'r', encoding='utf-8-sig') as f:
        for line in f:
            line = line.strip()
            if line and not line.startswith('#'):
                path = dropbox_url_to_path(line)
                entries.append({'label': make_label(path), 'path': path})

    output_path = Path(args.output)
    output_path.parent.mkdir(parents=True, exist_ok=True)

    with open(output_path, 'w', encoding='utf-8') as f:
        json.dump(entries, f, indent=2, ensure_ascii=False)

    print(f'Wrote {len(entries)} path(s) to {output_path}')
    for e in entries:
        print(f'  {e["label"]} -> {e["path"]}')

    print(f'\nrclone copy {output_path} dropbox:/vercel')
    print(f'rclone link dropbox:/vercel/{output_path.name}')
    print(f'\n# Then update Vercel with the link (append &raw=1):')
    print(f'vercel env rm DROPBOX_IMAGES_URL production -y')
    print(f'echo "<LINK>&raw=1" | vercel env add DROPBOX_IMAGES_URL production')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
