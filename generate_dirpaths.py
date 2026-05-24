#!/usr/bin/env python3
"""Convert dirpaths.txt to public/dirpaths.json.

Usage:
  python generate_dirpaths.py
  python generate_dirpaths.py -i dirpaths.txt -o public/dirpaths.json

After generating, upload to Dropbox and set the Vercel env var:
  rclone copy public/dirpaths.json dropbox:/vercel
  rclone link dropbox:/vercel/dirpaths.json
  vercel env rm DROPBOX_TEXTEDIT_URL production -y
  echo "<LINK>&raw=1" | vercel env add DROPBOX_TEXTEDIT_URL production
"""

import argparse
import json
from pathlib import Path


def main():
    parser = argparse.ArgumentParser(description='Convert dirpaths.txt to JSON')
    parser.add_argument('-i', '--input', default='dirpaths.txt',
                        help='Input text file (default: dirpaths.txt)')
    parser.add_argument('-o', '--output', default='public/dirpaths.json',
                        help='Output JSON file (default: public/dirpaths.json)')
    args = parser.parse_args()

    input_path = Path(args.input)
    if not input_path.exists():
        print(f'Error: {input_path} not found')
        return 1

    paths = []
    with open(input_path, 'r', encoding='utf-8-sig') as f:
        for line in f:
            line = line.strip()
            if line and not line.startswith('#'):
                paths.append(line)

    output_path = Path(args.output)
    output_path.parent.mkdir(parents=True, exist_ok=True)

    with open(output_path, 'w', encoding='utf-8') as f:
        json.dump(paths, f, indent=2, ensure_ascii=False)

    print(f'Wrote {len(paths)} path(s) to {output_path}')
    for p in paths:
        parts = p.rstrip('/').split('/')
        short = '/'.join(parts[-2:])
        print(f'  {short}')

    print(f'\nrclone copy {output_path.resolve()} dropbox:/vercel')
    print(f'rclone link dropbox:/vercel/{output_path.name}')
    print(f'\n# Then update Vercel with the link (append &raw=1):')
    print(f'vercel env rm DROPBOX_TEXTEDIT_URL production -y')
    print(f'echo "<LINK>&raw=1" | vercel env add DROPBOX_TEXTEDIT_URL production')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
