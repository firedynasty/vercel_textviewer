#!/usr/bin/env python3
"""
Catalog of a playlist's lessons: <dir>/catalog.jsonl, one JSON line per video.
The catalog is the source of truth; public/worksheets/index.json (the list the
worksheet page shows) is rebuilt from it.

Fields: index (playlist position), videoId, playlist, title, status,
        transcript (path relative to <dir>), worksheet (path under public/), prereq [indexes]
Status moves: todo -> transcript -> worksheet -> reviewed -> done

Usage (DIR defaults to the Professor Dave notes folder; override with --dir):
  python3 catalog.py list
  python3 catalog.py add "https://www.youtube.com/watch?v=...&list=...&index=9" --prereq 8
  python3 catalog.py status 9 reviewed        # or: done 9
  python3 catalog.py rebuild                  # regenerate public/worksheets/index.json
"""

import argparse
import json
import os
import re
import sys

DEFAULT_DIR = os.path.expanduser('~/Documents/notes/learning/math/youtube/prof_dave_explains_math')
PUBLIC_WS = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'public', 'worksheets')
STATUSES = ['todo', 'transcript', 'worksheet', 'reviewed', 'done']


def parse_url(url):
    """Return (videoId, playlist, index) from a YouTube watch URL; missing parts are None."""
    vid = re.search(r'(?:v=|youtu\.be/)([\w-]{11})', url)
    pl = re.search(r'[?&]list=([\w-]+)', url)
    ix = re.search(r'[?&]index=(\d+)', url)
    return (vid.group(1) if vid else None, pl.group(1) if pl else None, int(ix.group(1)) if ix else None)


def catalog_path(d):
    return os.path.join(d, 'catalog.jsonl')


def load(d):
    try:
        with open(catalog_path(d), encoding='utf-8') as f:
            return [json.loads(line) for line in f if line.strip()]
    except FileNotFoundError:
        return []


def save(d, entries):
    entries.sort(key=lambda e: (e.get('index') is None, e.get('index') or 0))
    with open(catalog_path(d), 'w', encoding='utf-8') as f:
        for e in entries:
            f.write(json.dumps(e, ensure_ascii=False) + '\n')


def upsert(d, video_id, **fields):
    """Add or update the entry for video_id. Fields set to None never erase existing values,
    and status never moves backwards."""
    entries = load(d)
    entry = next((e for e in entries if e['videoId'] == video_id), None)
    if entry is None:
        entry = {'index': None, 'videoId': video_id, 'playlist': None, 'title': None,
                 'status': 'todo', 'transcript': None, 'worksheet': None, 'prereq': []}
        entries.append(entry)
    for k, v in fields.items():
        if v is None:
            continue
        if k == 'status' and k in entry and STATUSES.index(v) < STATUSES.index(entry['status']):
            continue
        entry[k] = v
    save(d, entries)
    return entry


def rebuild_public_index(d):
    """Write the worksheet page's list from the catalog (entries that have a worksheet)."""
    out = []
    for e in load(d):
        if not e.get('worksheet'):
            continue
        path = os.path.join(PUBLIC_WS, os.path.basename(e['worksheet']))
        try:
            with open(path, encoding='utf-8') as f:
                n = len(json.load(f)['problems'])
        except (OSError, ValueError, KeyError):
            continue  # catalog says there is a worksheet but the file is gone
        out.append({'index': e['index'], 'videoId': e['videoId'], 'title': e['title'] or e['videoId'],
                    'status': e['status'], 'problems': n})
    os.makedirs(PUBLIC_WS, exist_ok=True)
    with open(os.path.join(PUBLIC_WS, 'index.json'), 'w', encoding='utf-8') as f:
        json.dump(out, f, ensure_ascii=False, indent=1)
    return out


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--dir', default=DEFAULT_DIR)
    sub = ap.add_subparsers(dest='cmd', required=True)
    sub.add_parser('list')
    sub.add_parser('rebuild')
    a = sub.add_parser('add'); a.add_argument('url'); a.add_argument('--prereq', type=int, nargs='*', default=None)
    s = sub.add_parser('status'); s.add_argument('index', type=int); s.add_argument('value', choices=STATUSES)
    dn = sub.add_parser('done'); dn.add_argument('index', type=int)
    args = ap.parse_args()

    if args.cmd == 'list':
        for e in load(args.dir):
            print(f'{e["index"]!s:>3}  {e["status"]:<10} {e["videoId"]}  {e["title"] or "(no title yet)"}')
    elif args.cmd == 'rebuild':
        print(f'{len(rebuild_public_index(args.dir))} worksheet(s) in public/worksheets/index.json')
    elif args.cmd == 'add':
        vid, pl, ix = parse_url(args.url)
        if not vid:
            sys.exit('no video id in that URL')
        e = upsert(args.dir, vid, playlist=pl, index=ix, prereq=args.prereq)
        print(json.dumps(e, ensure_ascii=False))
    else:  # status / done
        value = 'done' if args.cmd == 'done' else args.value
        entries = load(args.dir)
        e = next((e for e in entries if e['index'] == args.index), None)
        if e is None:
            sys.exit(f'no entry with index {args.index}')
        e['status'] = value  # an explicit command may move status backwards
        save(args.dir, entries)
        rebuild_public_index(args.dir)
        print(f'{args.index}: {value}')


if __name__ == '__main__':
    main()
