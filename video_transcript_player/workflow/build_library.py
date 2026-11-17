#!/usr/bin/env python3
"""Scan video_transcript_player/youtube/<id>/ and build ONE json for the player's Library dropdown.

Subtitle text is embedded in the json (srt files are tiny), so there is a single file to publish.
Titles come from YouTube oEmbed (no API key) and are kept from the previous run.

Usage:
  python3 build_library.py            # writes public/video_library.json (works with a local http.server)
  python3 build_library.py --push     # also: rclone copy -> dropbox:vercel/textviewer/ and print the link

In the player, click "Load from DB" (Dropbox popup login) to read dropbox:vercel/textviewer/video_library.json.
The Dropbox app needs the player page URL in its Redirect URIs.
"""
import json, re, subprocess, sys, urllib.request, urllib.parse
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "video_transcript_player" / "youtube"
OUT = ROOT / "public" / "video_library.json"
REMOTE = "dropbox:vercel/textviewer"

old = {}
if OUT.exists():
    old = {e["id"]: e for e in json.loads(OUT.read_text(encoding="utf-8"))}


def fetch_title(vid):
    url = "https://www.youtube.com/oembed?format=json&url=" + urllib.parse.quote(
        f"https://www.youtube.com/watch?v={vid}", safe="")
    try:
        with urllib.request.urlopen(url, timeout=10) as r:
            return json.load(r)["title"]
    except Exception as e:
        print(f"  no title for {vid}: {e}")
        return vid


def read(d, name):
    return {"name": name, "text": (d / name).read_text(encoding="utf-8")} if name else None


entries = []
for d in sorted(p for p in SRC.iterdir() if p.is_dir()):
    vid = d.name
    srts = {f.name for f in d.glob("*.srt")}
    if not srts:
        continue
    # <id>_zh_en.srt marks a translated pair; prefer the _shifted set (shift_srt.py) when present
    pairs = [m for f in srts if (m := re.fullmatch(re.escape(vid) + r"_([a-z]{2})_([a-z]{2})(_shifted)?\.srt", f))]
    merged = max(pairs, key=lambda m: bool(m[3]), default=None)
    if merged:
        suf = merged[3] or ""
        sub1, sub2, lang = f"{vid}_{merged[1]}{suf}.srt", f"{vid}_{merged[2]}{suf}.srt", merged[1]
        if sub1 not in srts or sub2 not in srts:  # only the merged file was shifted
            sub1, sub2 = merged[0], None
    elif f"{vid}.srt" in srts:
        sub1, sub2, lang = f"{vid}.srt", None, None
    else:
        sub1, sub2, lang = sorted(srts)[0], None, None
    title = old.get(vid, {}).get("title") or fetch_title(vid)
    # Word-by-word gloss from gloss_srt.py (the player's G modal); prefer the one made from sub1's set
    glosses = sorted(d.glob("*.gloss.json"), key=lambda f: ("_shifted" in sub1) != ("_shifted" in f.name))
    gloss = {"name": glosses[0].name, "data": json.loads(glosses[0].read_text(encoding="utf-8"))} if glosses else None
    entries.append({"id": vid, "title": title, "lang": lang, "sub1": read(d, sub1), "sub2": read(d, sub2),
                    **({"gloss": gloss} if gloss else {})})
    print(f"{vid}  {sub1}{' + ' + sub2 if sub2 else ''}{' + ' + gloss['name'] if gloss else ''}  {title}")

OUT.write_text(json.dumps(entries, ensure_ascii=False), encoding="utf-8")
print(f"{len(entries)} videos -> {OUT} ({OUT.stat().st_size // 1024} KB)")

if "--push" in sys.argv:
    subprocess.run(["rclone", "copy", str(OUT), REMOTE], check=True)
    link = subprocess.run(["rclone", "link", f"{REMOTE}/{OUT.name}"], check=True,
                          capture_output=True, text=True).stdout.strip()
    link = link.replace("dl=0", "raw=1") if "dl=0" in link else link + ("&raw=1" if "?" in link else "?raw=1")
    print(f"Pushed to {REMOTE}/{OUT.name}\nDROPBOX_VIDEOLIB_URL = {link}")
