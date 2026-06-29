#!/usr/bin/env python3
"""Scan ./content/ and write manifest.json. Run before serving."""
import os, json

content_dir = './content'
manifest = []

for root, dirs, files in os.walk(content_dir):
    dirs.sort()
    files.sort()
    rel_root = os.path.relpath(root, content_dir)
    if rel_root == '.':
        rel_root = ''

    for f in sorted(files, key=lambda x: x.lower()):
        path = (rel_root + '/' + f) if rel_root else f
        manifest.append(path)

with open('manifest.json', 'w') as out:
    json.dump(manifest, out, indent=2)

print(f"manifest.json written with {len(manifest)} files")
