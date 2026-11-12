"""Prepare a reviewable Activity Island patch without changing its checkout."""
import argparse
import difflib
from pathlib import Path


def updated_server(source):
    if "require('./folder-chat.cjs')" in source:
        return source
    replacements = [
        ('readBody(req, 64 * 1024, (body) => {', 'readBody(req, 1024 * 1024, (body) => {'),
        ("    const id = String(q.id || ''), name = String(q.name || 'Islander').slice(0, 20);",
         """    if (!q || typeof q !== 'object') { res.writeHead(400); return res.end('bad json'); }
    if (Object.prototype.hasOwnProperty.call(q, 'context')) {
      return require('./folder-chat.cjs').handleContextChat(q, res, {
        ollama: OLLAMA, model: MODEL, openaiKey: OPENAI_KEY, openaiModel: OPENAI_MODEL,
        openaiUrl: OPENAI_URL, searchTool: SEARCH_TOOL,
      });
    }
    const id = String(q.id || ''), name = String(q.name || 'Islander').slice(0, 20);"""),
        ('  fetch(OLLAMA + \'/api/tags\')', "  out.contextChat = 1;\n  fetch(OLLAMA + '/api/tags')"),
    ]
    for before, after in replacements:
        if source.count(before) != 1:
            raise ValueError('Activity Island has changed. Review server.js before applying the context update.')
        source = source.replace(before, after, 1)
    return source


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('checkout', type=Path)
    parser.add_argument('--output-dir', type=Path, default=Path('dist/activity-island-update'))
    args = parser.parse_args()
    original = (args.checkout / 'server.js').read_text()
    updated = updated_server(original)
    args.output_dir.mkdir(parents=True, exist_ok=True)
    (args.output_dir / 'server.js').write_text(updated)
    extension = Path(__file__).with_name('activity_context.cjs').read_text()
    (args.output_dir / 'folder-chat.cjs').write_text(extension)
    patch = ''.join(difflib.unified_diff(original.splitlines(True), updated.splitlines(True), fromfile='a/server.js', tofile='b/server.js'))
    existing = args.checkout / 'folder-chat.cjs'
    patch += ''.join(difflib.unified_diff(existing.read_text().splitlines(True) if existing.exists() else [], extension.splitlines(True), fromfile='a/folder-chat.cjs' if existing.exists() else '/dev/null', tofile='b/folder-chat.cjs'))
    (args.output_dir / 'context-chat.patch').write_text(patch)
    print(f'Prepared {args.output_dir / "context-chat.patch"}')
