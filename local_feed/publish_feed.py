#!/usr/bin/env python3
"""Copy chosen note folders into the Dropbox app folder the online feed reads.

The app folder is an ordinary synced folder, so this is a local copy: no rclone
remote, no API token, nothing published that was not named in the topics file.
Videos are left behind because the online feed skips them and they are most of the bytes.
"""

import argparse
import json
from pathlib import Path
import shutil
import subprocess
import sys

from server import IGNORED, TYPES

HERE = Path(__file__).resolve().parent
TOPICS = HERE / 'feed_topics.json'
APP_FOLDER = Path.home() / 'Dropbox' / 'Apps' / 'NotesFeed'
# The feed reads these; videos are excluded on purpose.
PUBLISHED = sorted({suffix for suffix, kind in TYPES.items() if kind != 'video'})


def load_topics(path):
    if not path.is_file():
        raise SystemExit(f'No topics file at {path}. Create one, for example:\n'
                         '{\n  "recipes": "~/Documents/notes/recipes"\n}')
    try:
        topics = json.loads(path.read_text())
    except json.JSONDecodeError as problem:
        raise SystemExit(f'{path} is not valid JSON: {problem}')
    if not isinstance(topics, dict) or not topics:
        raise SystemExit(f'{path} should be an object of "label": "source folder".')
    for label in topics:
        if not label or '/' in label or label.startswith('.'):
            raise SystemExit(f'Topic name {label!r} should be a plain folder name.')
    return topics


def rsync_command(source, destination, mirror, dry_run):
    command = ['rsync', '-a', '--prune-empty-dirs', '--human-readable', '--stats']
    if dry_run:
        # Without -v a dry run prints nothing, which reads as "no work to do".
        command += ['--dry-run', '-v']
    if mirror:
        command.append('--delete')
    command += ['--exclude', '.*']
    for name in sorted(IGNORED):
        command += ['--exclude', name]
    command += ['--include', '*/']
    for suffix in PUBLISHED:
        command += ['--include', f'*{suffix}', '--include', f'*{suffix.upper()}']
    command += ['--exclude', '*']
    # Trailing slashes copy the contents of source into destination.
    command += [f'{source}/', f'{destination}/']
    return command


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('topics', nargs='*', help='publish only these topics (default: all in the topics file)')
    parser.add_argument('--topics-file', type=Path, default=TOPICS)
    parser.add_argument('--app-folder', type=Path, default=APP_FOLDER, help=f'default: {APP_FOLDER}')
    parser.add_argument('--dry-run', action='store_true', help='list what would be copied, change nothing')
    parser.add_argument('--mirror', action='store_true',
                        help='also delete published files whose source is gone (off by default)')
    args = parser.parse_args()

    if not shutil.which('rsync'):
        raise SystemExit('rsync is not installed.')
    destination_root = args.app_folder.expanduser()
    if not destination_root.is_dir():
        raise SystemExit(f'{destination_root} does not exist yet.\n'
                         'Sign in to Dropbox from the feed once so Dropbox creates the app folder, '
                         'let it sync, then run this again. Do not create it by hand: a name that '
                         'differs even slightly becomes a second folder the app cannot see.')

    topics = load_topics(args.topics_file.expanduser())
    chosen = args.topics or sorted(topics)
    unknown = [name for name in chosen if name not in topics]
    if unknown:
        raise SystemExit(f'Not in {args.topics_file}: {", ".join(unknown)}')

    failures = 0
    for label in chosen:
        source = Path(topics[label]).expanduser()
        if not source.is_dir():
            print(f'! {label}: {source} is not a folder, skipped', file=sys.stderr)
            failures += 1
            continue
        destination = destination_root / label
        destination.mkdir(parents=True, exist_ok=True)
        print(f'\n== {label}: {source} -> {destination}')
        result = subprocess.run(rsync_command(source, destination, args.mirror, args.dry_run))
        if result.returncode != 0:
            print(f'! {label}: rsync exited {result.returncode}', file=sys.stderr)
            failures += 1

    if args.dry_run:
        print('\nDry run: nothing was copied.')
    return 1 if failures else 0


if __name__ == '__main__':
    raise SystemExit(main())
