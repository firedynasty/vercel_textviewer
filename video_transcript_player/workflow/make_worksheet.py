#!/usr/bin/env python3
"""
Turn a video transcript into a practice worksheet that public/worksheet.html
shows next to the embedded video.

Input: a transcript .txt with the YouTube URL near the top. Time markers can be
any of: [0:00:09] / 0:09 on a line of their own (what YouTube's "Show transcript"
copies), or "0:09 text" on one line. They are normalised to [H:MM:SS] for the model.
Output: public/worksheets/<videoId>.json (and a copy in <dir>/worksheets/ for "Load worksheet file…"), plus the video's line in <dir>/catalog.jsonl
(see catalog.py) and a rebuilt public/worksheets/index.json.

The catalog folder <dir> is the parent of the "transcripts" folder holding the file,
e.g. .../prof_dave_explains_math/transcripts/08-percent_conversions.txt.

Calls the `claude -p` CLI (your logged-in account), so no API key is needed.

Usage:
  python3 make_worksheet.py ~/.../prof_dave_explains_math/transcripts/09-decimals.txt
  python3 make_worksheet.py <file> --count 20 --title "Decimals" --prereq 8
  python3 make_worksheet.py <file> --url "https://www.youtube.com/watch?v=...&index=9"   # when the file has no URL
  python3 make_worksheet.py <file> --force        # replace an existing worksheet

Answers are numbers (or a comma-separated list of numbers for ordering), so the
page checks them itself. This script re-parses every answer and drops problems
whose answer it cannot read. It cannot prove the model's maths is right: read
the solutions and check a few answers yourself.
"""

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from fractions import Fraction

import catalog

MODEL = 'claude-sonnet-5-5'
NEUTRAL_CWD = tempfile.gettempdir()
HERE = os.path.dirname(os.path.abspath(__file__))
OUT_DIR = os.path.join(HERE, '..', '..', 'public', 'worksheets')

PROMPT = """You are writing a practice worksheet for a student who just watched this video. The transcript has [H:MM:SS] time markers.

Write {count} problems in graduated difficulty (level 1 = direct recall of the video's method, level 2 = a variation, level 3 = multi-step or applied). Cover every skill the video teaches, and do not ask anything the video does not teach. The video's own practice questions are not in the transcript, so write fresh ones with different numbers.

Every answer must be a NUMBER the page can check, so ask for one numeric result, or an ordering. Never use a word answer.

Each problem is an object:
- "level": 1, 2 or 3
- "skill": 2-5 word label, e.g. "percent to decimal"
- "question": the question text. State the form wanted when it matters (e.g. "as a decimal", "as a fraction in lowest terms", "as a percent").
- "kind": "number" for a single answer, "list" for an ordering (the student types comma-separated values)
- "form": for kind "number" only: "fraction" (must be typed like 3/10), "decimal" (like 0.3), "percent" (like 30%), "money" (like $4.13), or "any"
- "answer": the correct answer as a string in that form, e.g. "3/10", "0.3", "30%", "$4.13". For kind "list", comma-separated values in correct order, written as in the question, e.g. "42/100, 0.43, 45%".
- "tolerance": 0, or the allowed absolute error when rounding is expected (e.g. 0.005 for money rounded to cents)
- "solution": the worked steps, short, in the video's own method
- "t": the second in the video where the needed skill is taught (pick the nearest [H:MM:SS] marker, converted to seconds)

Also give "title": a short worksheet title.

Return ONLY JSON, no commentary:
{{"title": "...", "problems": [ {{...}} ]}}

Transcript:
"""

# a time marker: [0:00:09], 0:09, 01:05:30 (optionally followed by text on the same line)
STAMP = re.compile(r'^\[?(?:(\d{1,2}):)?(\d{1,2}):(\d{2})\]?(?:\s+(.*))?$')
URL_LINE = re.compile(r'https?://\S*(?:youtube\.com|youtu\.be)\S*')


def parse_value(s):
    """Same rules as parseValue in public/worksheet.html. Returns a Fraction or None."""
    s = s.strip().replace('$', '').replace(',', '').replace(' ', '')
    if not s:
        return None
    pct = s.endswith('%')
    if pct:
        s = s[:-1]
    try:
        if '/' in s:
            a, b = s.split('/')
            v = Fraction(a) / Fraction(b)
        else:
            v = Fraction(s)
    except (ValueError, ZeroDivisionError):
        return None
    return v / 100 if pct else v


def read_transcript(path, url=None):
    """Return (text for the model, (videoId, playlist, index)).
    The text is the transcript with every time marker rewritten as [H:MM:SS] on its own line,
    and the URL line removed. Without any markers the text is passed through unchanged."""
    with open(path, encoding='utf-8-sig') as f:
        raw = f.read().replace('\r\n', '\n')

    found = url
    body = []
    for line in raw.split('\n'):
        line = line.strip()
        if not line:
            continue
        m = URL_LINE.search(line)
        if m and not found:
            found = m.group(0)
            continue
        if m and found:  # a second link in the file is not transcript text
            continue
        body.append(line)

    out, stamps = [], 0
    for line in body:
        m = STAMP.match(line)
        if m:
            h, mi, se, rest = m.groups()
            secs = int(h or 0) * 3600 + int(mi) * 60 + int(se)
            out.append(f'[{secs // 3600}:{secs % 3600 // 60:02d}:{secs % 60:02d}]')
            if rest:
                out.append(rest)
            stamps += 1
        else:
            out.append(line)
    if not stamps:
        print('warning: no time markers found, so "Watch this part" will point at guessed times')
    return '\n'.join(out), catalog.parse_url(found or '')


def ask_claude(transcript, count):
    r = subprocess.run(
        ['claude', '-p', '--model', MODEL, '--tools', ''],
        input=PROMPT.format(count=count) + transcript, capture_output=True, text=True,
        timeout=900, cwd=NEUTRAL_CWD,
    )
    if r.returncode != 0:
        raise RuntimeError(r.stderr.strip() or 'claude CLI failed')
    out = r.stdout.strip()
    if out.startswith('```'):
        out = out.split('\n', 1)[1].rsplit('```', 1)[0]
    return json.loads(out)


def clean(problems):
    """Keep only problems the page can check."""
    good = []
    for p in problems:
        try:
            kind = p['kind']
            if kind == 'number':
                ok = parse_value(p['answer']) is not None
            elif kind == 'list':
                parts = [x for x in p['answer'].split(',')]
                ok = len(parts) >= 2 and all(parse_value(x) is not None for x in parts)
            else:
                ok = False
            p['t'] = int(p.get('t', 0))
            p['tolerance'] = float(p.get('tolerance', 0))
            p['form'] = p.get('form', 'any')
            p['level'] = int(p.get('level', 1))
            assert p['question'].strip() and p['solution'].strip()
        except Exception:
            ok = False
        if ok:
            good.append(p)
        else:
            print(f'dropped (unreadable answer): {p.get("question", "?")[:70]}')
    for i, p in enumerate(good, 1):
        p['id'] = i
    return good


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('transcript')
    ap.add_argument('--dir', help='catalog folder (default: the parent of the transcript\'s "transcripts" folder)')
    ap.add_argument('--url', help='YouTube URL, when the file has none (include &list=...&index=N to set the order)')
    ap.add_argument('--video', help='YouTube id (default: taken from the URL)')
    ap.add_argument('--title', help='worksheet title (default: model-written)')
    ap.add_argument('--prereq', type=int, nargs='*', help='playlist indexes this lesson builds on')
    ap.add_argument('--count', type=int, default=16)
    ap.add_argument('--force', action='store_true', help='replace an existing worksheet')
    args = ap.parse_args()

    path = os.path.abspath(os.path.expanduser(args.transcript))
    text, (found, playlist, index) = read_transcript(path, args.url)
    video_id = args.video or found
    if not video_id:
        sys.exit('no video id: put the YouTube URL in the file or pass --url')

    folder = os.path.dirname(path)
    cat_dir = os.path.expanduser(args.dir) if args.dir else (
        os.path.dirname(folder) if os.path.basename(folder) == 'transcripts' else None)
    if not cat_dir:
        sys.exit('cannot tell the catalog folder: put the file in <dir>/transcripts/ or pass --dir')

    out = os.path.join(OUT_DIR, f'{video_id}.json')
    if os.path.exists(out) and not args.force:
        sys.exit(f'{os.path.relpath(out)} already exists (answers may be checked against it). Use --force to replace.')

    # record the transcript before the slow call, so the catalog is right even if it fails
    rel_transcript = os.path.relpath(path, cat_dir)
    catalog.upsert(cat_dir, video_id, playlist=playlist, index=index, transcript=rel_transcript,
                   status='transcript', prereq=args.prereq)

    print(f'asking claude for {args.count} problems...')
    data = ask_claude(text, args.count)
    problems = clean(data['problems'])
    title = args.title or data.get('title') or video_id

    os.makedirs(OUT_DIR, exist_ok=True)
    with open(out, 'w', encoding='utf-8') as f:
        json.dump({'videoId': video_id, 'title': title, 'problems': problems}, f, ensure_ascii=False, indent=1)
    # a second copy in the notes folder: the file to pick with "Load worksheet file…" on a deployed page
    bucket = os.path.join(cat_dir, 'worksheets')
    os.makedirs(bucket, exist_ok=True)
    shutil.copyfile(out, os.path.join(bucket, f'{video_id}.json'))
    catalog.upsert(cat_dir, video_id, title=title, worksheet=f'worksheets/{video_id}.json', status='worksheet')
    catalog.rebuild_public_index(cat_dir)
    print(f'{os.path.relpath(out)}: {len(problems)} problems')
    for p in problems:
        print(f'  {p["id"]:>2} L{p["level"]} t={p["t"]:>3}s  {p["answer"]:<22} {p["question"][:70]}')


if __name__ == '__main__':
    main()
