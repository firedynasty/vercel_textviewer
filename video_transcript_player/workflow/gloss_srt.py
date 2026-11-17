#!/usr/bin/env python3
"""
Gloss a Chinese .srt word by word for the transcript player's Gloss modal (G key).

Same recipe as the Bible run (vercel_bible_current/generate_chinese_breakdown_openai.py):
segmentation + tone-marked pinyin come from natural_nlp (jieba + pypinyin + CC-CEDICT),
and OpenAI only writes a short English gloss for each fixed token, in context.

Input:  any .srt whose cues contain a Chinese line. A bilingual zh/en srt (from
        4_merge_bilingual.py) is best: the English line is sent as context and
        shown in the modal. Gloss AFTER shift_srt.py so the times match the video.
Output: <same name>.gloss.json next to the srt, e.g.
        RmL8mEMebgo_zh_en_shifted.srt -> RmL8mEMebgo_zh_en_shifted.gloss.json
        {"videoId": <youtube/<id> folder>, "src": ..., "lang": "zh", "cues": [{"start", "end", "zh", "en",
          "words": [{"zh", "pinyin", "en"}]}]}

Resumable: cues already glossed in an existing output (same start + text) are kept,
so re-running after a failure only sends the missing ones (words that fell back to
CC-CEDICT are marked "fb": 1 and retried).

Use anaconda's python3 (the one with jieba/pypinyin/opencc installed):
  export OPENAI_API_KEY=...
  python3 gloss_srt.py ../youtube/RmL8mEMebgo/RmL8mEMebgo_zh_en_shifted.srt --dry-run   # count + cost, free
  python3 gloss_srt.py <srt> --limit 20          # trial on the first 20 cues
  python3 gloss_srt.py <srt>                     # everything
  python3 gloss_srt.py <srt> --offline           # no API: CC-CEDICT meanings (free, rougher)
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
import threading
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

DEFAULT_NATURAL_NLP = Path("/Users/stanleytan/Documents/technical/ollama/natural_nlp")
DEFAULT_MODEL = "gpt-6-luna"
OPENAI_URL = "https://api.openai.com/v1/chat/completions"
INPUT_USD_PER_MILLION = 0.10
OUTPUT_USD_PER_MILLION = 0.50

CJK = re.compile(r"[㐀-鿿]")

SYSTEM_PROMPT = """You write dictionary-style English glosses for pre-segmented
Chinese from a transcribed video (often a Mandarin church sermon), for a learner
studying each word.

For each token give the meaning of THAT WORD as used in this sentence, not a
slice of the sentence translation:
- content words: the dictionary sense that fits here, e.g. 患 "to suffer from
  (an illness)", 確診 "to be diagnosed", 保守 "to keep, protect (God's care)".
- function words and particles: a short grammar label, e.g. 了 "(completed
  action)", 的 "(possessive / modifier marker)", 被 "(passive marker)",
  把 "(object marker)", 呢 "(question / continuing particle)".
- names: who or what it is, e.g. 潘牧師 "Pastor Pan".
Keep each gloss under 8 words.

The transcript comes from speech recognition, so some characters are mis-heard
homophones. If a token is almost certainly a mis-hearing, put the intended word
in "fix" (e.g. 發療 -> fix "化療", en "chemotherapy") and gloss the intended
word; otherwise "fix" is "". Use the English translation as context.

The supplied Chinese tokens are authoritative. Return exactly one entry for
each token, in the same order. Copy every zh token exactly. Never split, join,
omit, reorder, or add tokens. Return only the requested JSON object."""

GLOSS_SCHEMA = {
    "type": "object",
    "properties": {
        "cues": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "cue": {"type": "integer"},
                    "words": {
                        "type": "array",
                        "items": {
                            "type": "object",
                            "properties": {"zh": {"type": "string"}, "en": {"type": "string"},
                                           "fix": {"type": "string"}},
                            "required": ["zh", "en", "fix"],
                            "additionalProperties": False,
                        },
                    },
                },
                "required": ["cue", "words"],
                "additionalProperties": False,
            },
        }
    },
    "required": ["cues"],
    "additionalProperties": False,
}


# ── SRT ──────────────────────────────────────────────────────────────────────
def srt_time(ts: str) -> float:
    m = re.match(r"\s*(?:(\d+):)?(\d{1,2}):(\d{2})[,.](\d{1,3})", ts)
    if not m:
        return 0.0
    h, mi, s, ms = m.groups()
    return int(h or 0) * 3600 + int(mi) * 60 + int(s) + int(ms.ljust(3, "0")) / 1000


def read_srt(path: Path) -> list[dict]:
    """Cues with a Chinese line. Chinese lines -> zh, the rest -> en."""
    cues = []
    for block in re.split(r"\n\s*\n", path.read_text(encoding="utf-8-sig").replace("\r", "")):
        lines = [l.strip() for l in block.strip().split("\n") if l.strip()]
        ts = next((i for i, l in enumerate(lines) if "-->" in l), None)
        if ts is None:
            continue
        a, b = lines[ts].split("-->", 1)
        text = [re.sub(r"<[^>]+>", "", l) for l in lines[ts + 1:]]
        zh = "".join(l for l in text if CJK.search(l))
        if not zh:
            continue
        en = " ".join(l for l in text if not CJK.search(l))
        cues.append({"start": round(srt_time(a), 3), "end": round(srt_time(b), 3), "zh": zh, "en": en})
    return cues


# ── natural_nlp ──────────────────────────────────────────────────────────────
def load_natural_nlp(path: Path):
    if not (path / "chinese_csv.py").is_file():
        sys.exit(f"chinese_csv.py not found in {path}; pass --natural-nlp")
    sys.path.insert(0, str(path))
    try:
        from chinese_csv import chinese_text_to_rows, load_cedict
    except ImportError as exc:
        sys.exit(f"Could not import natural_nlp ({exc}). Use anaconda's python3, "
                 f"or pip install -r {path / 'requirements.txt'}")
    return chinese_text_to_rows, load_cedict


def segment(to_rows, text: str, cedict: dict) -> list[dict]:
    # Word rows are the ones with pinyin; header and sentence rows have none.
    return [{"zh": r[0], "pinyin": r[1], "dict": r[2] if len(r) > 2 else ""}
            for r in to_rows(text, cedict)[1:] if len(r) >= 2 and r[1].strip()]


def cedict_gloss(d: str) -> str:
    # natural_nlp joins meanings with '-': keep the first one or two, short
    parts = [p.strip() for p in d.split("-") if p.strip()]
    return "; ".join(parts[:2])[:60]


# ── OpenAI ───────────────────────────────────────────────────────────────────
def openai_json(system: str, user: str, schema: dict, model: str, timeout: int,
                max_tokens: int = 12000) -> tuple[dict, dict]:
    key = os.environ.get("OPENAI_API_KEY")
    if not key:
        raise RuntimeError("OPENAI_API_KEY is not set in this terminal.")
    body = {
        "model": model,
        "messages": [{"role": "system", "content": system}, {"role": "user", "content": user}],
        "response_format": {"type": "json_schema",
                            "json_schema": {"name": "out", "strict": True, "schema": schema}},
        "reasoning_effort": "none",
        "max_completion_tokens": max_tokens,
    }
    req = urllib.request.Request(
        OPENAI_URL, data=json.dumps(body, ensure_ascii=False).encode(),
        headers={"Authorization": f"Bearer {key}", "Content-Type": "application/json"}, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            payload = json.loads(r.read().decode())
    except urllib.error.HTTPError as exc:
        raise RuntimeError(f"OpenAI HTTP {exc.code}: {exc.read().decode(errors='replace')[:300]}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"Could not reach OpenAI: {exc}") from exc
    try:
        return json.loads(payload["choices"][0]["message"]["content"]), payload.get("usage", {})
    except (KeyError, IndexError, TypeError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"OpenAI returned invalid JSON: {exc}") from exc


def ask_openai(records: list[dict], model: str, timeout: int) -> tuple[dict, dict]:
    user = (
        "Gloss each supplied token. "
        'Return {"cues":[{"cue":N,"words":[{"zh":"token copied exactly","en":"gloss","fix":""}]}]}\n\nInput:\n'
        + json.dumps(records, ensure_ascii=False, separators=(",", ":"))
    )
    return openai_json(SYSTEM_PROMPT, user, GLOSS_SCHEMA, model, timeout)


# ── Terms pass: teach jieba the transcript's own multi-character words ───────
# jieba alone splits rarer compounds, names and church vocabulary (e.g. 膀胱癌,
# 以馬內利). Ask once per ~2500 characters for the units a learner should see
# whole, keep only spans that really occur in the text, and add them to jieba
# before segmenting. Cached next to the output so re-runs segment identically.
TERMS_SCHEMA = {
    "type": "object",
    "properties": {"names": {"type": "array", "items": {"type": "string"}},
                   "terms": {"type": "array", "items": {"type": "string"}}},
    "required": ["names", "terms"],
    "additionalProperties": False,
}
TERMS_SYSTEM = "You find word boundaries in transcribed Chinese speech for a language learner."
TERMS_USER = (
    "From the Chinese text below, list:\n"
    "- names: proper names (people, places, churches, books of the Bible, God/Jesus titles)\n"
    "- terms: multi-character words and fixed expressions a learner should learn as ONE unit "
    "(compound nouns, chengyu, Christian/Bible vocabulary, medical or technical words). "
    "Not whole phrases or clauses; not ordinary two-character words every dictionary has.\n"
    "Copy each EXACTLY as a contiguous substring of the text, 2-6 characters. Invent nothing.\n\nText:\n"
)
CJK_SPAN = re.compile(r"^[㐀-鿿]{2,6}$")


def harvest_terms(cues: list[dict], model: str, timeout: int, workers: int) -> tuple[dict, dict]:
    text = "\n".join(c["zh"] for c in cues)
    chunks, cur = [], ""
    for c in cues:
        if len(cur) + len(c["zh"]) > 2500:
            chunks.append(cur)
            cur = ""
        cur += c["zh"] + "\n"
    if cur:
        chunks.append(cur)
    names, terms, usage = set(), set(), {"prompt_tokens": 0, "completion_tokens": 0}

    def one(chunk):
        for attempt in range(3):
            try:
                return openai_json(TERMS_SYSTEM, TERMS_USER + chunk, TERMS_SCHEMA, model, timeout, 4000)
            except RuntimeError as exc:
                if attempt == 2:
                    print(f"  ! terms pass chunk failed: {exc}")
                    return {"names": [], "terms": []}, {}
                time.sleep(2 * (attempt + 1))

    with ThreadPoolExecutor(max_workers=workers) as pool:
        for res, u in pool.map(one, chunks):
            names |= {n for n in res.get("names", []) if isinstance(n, str) and CJK_SPAN.match(n) and n in text}
            terms |= {t for t in res.get("terms", []) if isinstance(t, str) and CJK_SPAN.match(t) and t in text}
            for k in usage:
                usage[k] += int(u.get(k, 0))
    return {"names": sorted(names), "terms": sorted(terms - names)}, usage


def teach_jieba(found: dict, natural_nlp_dir: Path) -> None:
    """natural_nlp segments the Simplified form, so add both forms.
    Names get a very high frequency (beat overlapping words: 約拿出城 -> 約拿 出 城, as in
    the Bible run); terms only get the frequency needed to stay in one piece."""
    sys.path.insert(0, str(natural_nlp_dir))
    import jieba
    import opencc
    t2s = opencc.OpenCC("t2s")
    for kind, freq in (("names", 100_000), ("terms", None)):
        for w in found.get(kind, []):
            for form in {w, t2s.convert(w)}:
                if freq:
                    jieba.add_word(form, freq=freq)
                else:
                    jieba.suggest_freq(form, tune=True)


def gloss_batch(records: list[dict], model: str, timeout: int, retries: int):
    last = None
    for attempt in range(retries + 1):
        try:
            result, usage = ask_openai(records, model, timeout)
            got = result.get("cues") or []
            if [g.get("cue") for g in got] != [r["cue"] for r in records]:
                raise ValueError("cue ids or order changed")
            out = {}
            for rec, g in zip(records, got):
                words = g.get("words") or []
                if [w.get("zh") for w in words] != rec["tokens"]:
                    raise ValueError(f"cue {rec['cue']} tokens changed")
                if any(not (w.get("en") or "").strip() for w in words):
                    raise ValueError(f"cue {rec['cue']} has an empty gloss")
                out[rec["cue"]] = [(w["en"].strip(), (w.get("fix") or "").strip()) for w in words]
            return out, usage
        except (ValueError, RuntimeError) as exc:
            last = exc
            if attempt < retries:
                time.sleep(2 * (attempt + 1))
    raise RuntimeError(f"batch failed: {last}")


# ── main ─────────────────────────────────────────────────────────────────────
def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("srt", type=Path)
    ap.add_argument("-o", "--out", type=Path, help="default: <srt name>.gloss.json")
    ap.add_argument("--model", default=DEFAULT_MODEL)
    ap.add_argument("--natural-nlp", type=Path, default=DEFAULT_NATURAL_NLP)
    ap.add_argument("--batch", type=int, default=25, help="cues per OpenAI call (default 25)")
    ap.add_argument("--workers", type=int, default=6)
    ap.add_argument("--limit", type=int, help="only the first N cues (trial run)")
    ap.add_argument("--timeout", type=int, default=180)
    ap.add_argument("--retries", type=int, default=2)
    ap.add_argument("--dry-run", action="store_true", help="segment and estimate cost only; write nothing")
    ap.add_argument("--offline", action="store_true", help="no API: use CC-CEDICT meanings")
    ap.add_argument("--no-terms", action="store_true", help="skip the terms pass (plain jieba segmentation)")
    ap.add_argument("--redo-terms", action="store_true", help="ignore the cached <name>.terms.json")
    args = ap.parse_args()

    src = args.srt.resolve()
    if not src.is_file():
        sys.exit(f"not found: {src}")
    out_path = (args.out or src.with_suffix(".gloss.json")).resolve()

    cues = read_srt(src)
    if args.limit:
        cues = cues[: args.limit]
    if not cues:
        sys.exit("No cues with Chinese text in that srt.")

    to_rows, load_cedict = load_natural_nlp(args.natural_nlp)
    print("Loading CC-CEDICT + jieba…", flush=True)
    cedict = load_cedict()
    to_rows("起初神創造天地", cedict)  # load jieba before adding words to it

    terms_usage = {}
    if not (args.no_terms or args.offline or args.dry_run):
        terms_path = out_path.with_name(out_path.name.replace(".gloss.json", "") + ".terms.json")
        if terms_path.exists() and not args.redo_terms:
            found = json.loads(terms_path.read_text("utf-8"))
            print(f"Terms: {len(found['names'])} names + {len(found['terms'])} terms from {terms_path.name}")
        else:
            print("Terms pass: asking for names and multi-character words…", flush=True)
            found, terms_usage = harvest_terms(cues, args.model, args.timeout, args.workers)
            terms_path.write_text(json.dumps(found, ensure_ascii=False, indent=1), encoding="utf-8")
            print(f"Terms: {len(found['names'])} names + {len(found['terms'])} terms -> {terms_path.name} "
                  "(edit it and re-run to adjust segmentation)")
        teach_jieba(found, args.natural_nlp)

    for c in cues:
        toks = segment(to_rows, c["zh"], cedict)
        c["_dict"] = [t.pop("dict") for t in toks]
        c["words"] = [{"zh": t["zh"], "pinyin": t["pinyin"], "en": ""} for t in toks]

    # Resume: reuse glosses for cues whose start + text are unchanged
    if out_path.exists() and not args.offline:
        try:
            old = {(round(c["start"], 2), c["zh"]): c for c in json.loads(out_path.read_text("utf-8"))["cues"]}
            reused = 0
            for c in cues:
                o = old.get((round(c["start"], 2), c["zh"]))
                if o and [w["zh"] for w in o["words"]] == [w["zh"] for w in c["words"]] \
                        and all(w.get("en") and not w.get("fb") for w in o["words"]):
                    c["words"] = o["words"]
                    reused += 1
            if reused:
                print(f"Resuming: {reused} cues already glossed in {out_path.name}")
        except (OSError, ValueError, KeyError):
            pass

    todo = [i for i, c in enumerate(cues) if c["words"] and not all(w["en"] for w in c["words"])]
    n_tokens = sum(len(cues[i]["words"]) for i in todo)
    batches = [todo[i:i + args.batch] for i in range(0, len(todo), args.batch)]
    # rough: ~3 input chars per token in json + prompt, ~12 output tokens per word
    est_in = sum(len(cues[i]["zh"]) * 3 + len(cues[i]["en"]) // 3 + len(cues[i]["words"]) * 6 for i in todo) + 250 * len(batches)
    est_out = n_tokens * 12
    est = est_in / 1e6 * INPUT_USD_PER_MILLION + est_out / 1e6 * OUTPUT_USD_PER_MILLION
    print(f"{len(cues)} cues, {len(todo)} to gloss, {n_tokens} words, {len(batches)} batches "
          f"-> ~${est:.3f} with {args.model}")

    def write() -> None:
        data = {
            "videoId": video_id(src),
            "src": src.name,
            "lang": "zh",
            "model": "cc-cedict" if args.offline else args.model,
            "cues": [{k: c[k] for k in ("start", "end", "zh", "en", "words")} for c in cues],
        }
        tmp = out_path.with_suffix(".tmp")
        tmp.write_text(json.dumps(data, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
        tmp.replace(out_path)

    if args.dry_run:
        c = cues[todo[0]] if todo else cues[0]
        print("Sample:", c["zh"], "->", " | ".join(f"{w['zh']} {w['pinyin']}" for w in c["words"]))
        return

    if args.offline:
        for c in cues:
            for w, d in zip(c["words"], c["_dict"]):
                w["en"] = w["en"] or cedict_gloss(d)
        write()
        print(f"Wrote {out_path} (CC-CEDICT glosses)")
        return

    from chinese_csv import to_pinyin  # natural_nlp, already on sys.path

    def fix_pinyin(word: str) -> str:
        try:
            return to_pinyin(word)
        except Exception:
            return ""

    lock = threading.Lock()
    usage_in = int(terms_usage.get("prompt_tokens", 0))
    usage_out = int(terms_usage.get("completion_tokens", 0))
    done = 0
    failed: list[int] = []

    def run(batch: list[int]):
        records = [{"cue": i, "sentence": cues[i]["zh"], "translation": cues[i]["en"],
                    "tokens": [w["zh"] for w in cues[i]["words"]]} for i in batch]
        return batch, gloss_batch(records, args.model, args.timeout, args.retries)

    with ThreadPoolExecutor(max_workers=args.workers) as pool:
        futs = [pool.submit(run, b) for b in batches]
        for f in as_completed(futs):
            try:
                batch, (gl, usage) = f.result()
            except RuntimeError as exc:
                print(f"  ! {exc}", flush=True)
                failed.append(1)
                continue
            with lock:
                for i, meanings in gl.items():
                    for w, (m, fix) in zip(cues[i]["words"], meanings):
                        w["en"] = m
                        w.pop("fb", None)
                        if good_fix(w["zh"], fix):
                            w["fix"] = fix  # likely Whisper mis-hearing: the word that was meant
                            w["fixPinyin"] = fix_pinyin(fix)
                usage_in += int(usage.get("prompt_tokens", 0))
                usage_out += int(usage.get("completion_tokens", 0))
                done += 1
                write()  # save after every batch so a crash loses little
                print(f"  {done}/{len(batches)} batches  ({fmt(cues[batch[0]]['start'])}–{fmt(cues[batch[-1]]['end'])})", flush=True)

    # Any cue still missing glosses gets CC-CEDICT meanings so the modal is never blank
    left = 0
    for c in cues:
        for w, d in zip(c["words"], c["_dict"]):
            if not w["en"]:
                w["en"] = cedict_gloss(d)
                w["fb"] = 1  # CC-CEDICT fallback: the next run retries it with OpenAI
                left += 1
    write()
    cost = usage_in / 1e6 * INPUT_USD_PER_MILLION + usage_out / 1e6 * OUTPUT_USD_PER_MILLION
    print(f"Wrote {out_path}\n  tokens in/out {usage_in}/{usage_out}  ≈ ${cost:.3f}")
    if failed:
        print(f"  {len(failed)} batch(es) failed; {left} words use CC-CEDICT for now. "
              "Run the same command again to retry just those.")


def video_id(src: Path) -> str:
    """YouTube id: the youtube/<id>/ folder name, else the filename's 11-char prefix.
    The player embeds this video when the gloss file is loaded."""
    if re.fullmatch(r"[\w-]{11}", src.parent.name):
        return src.parent.name
    m = re.match(r"([\w-]{11})(?=[_.])", src.name)
    return m[1] if m else ""


def good_fix(zh: str, fix: str) -> bool:
    """Keep real homophone corrections (發療 -> 化療); drop noise where the model just
    re-segmented (人站 -> '人 站') or shortened/lengthened the token (他 -> 他們)."""
    return bool(fix) and fix != zh and bool(re.fullmatch(r"[㐀-鿿]+", fix)) \
        and fix not in zh and zh not in fix


def fmt(s: float) -> str:
    s = int(s)
    return f"{s // 3600}:{s // 60 % 60:02d}:{s % 60:02d}"


if __name__ == "__main__":
    main()
