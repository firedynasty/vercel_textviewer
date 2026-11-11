#!/usr/bin/env python3
"""Serve the React viewer and a separate, read-only media folder. No dependencies."""

import argparse
import fcntl
import hashlib
import hmac
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import mimetypes
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys
import threading
import time
from urllib.parse import parse_qs, quote, unquote, urlsplit
from urllib.request import ProxyHandler, Request, build_opener

REPO = Path(__file__).resolve().parent.parent
NOTE_LIMIT = 256 * 1024
TYPES = {
    **dict.fromkeys(('.txt', '.md', '.markdown', '.docx'), 'note'),
    '.pdf': 'pdf',
    **dict.fromkeys(('.jpg', '.jpeg', '.png', '.gif', '.webp', '.avif', '.bmp', '.svg'), 'image'),
    **dict.fromkeys(('.mp4', '.webm', '.mov', '.m4v', '.ogv'), 'video'),
}
IGNORED = {'node_modules', '__pycache__'}


def visible(relative):
    return all(not part.startswith('.') and part not in IGNORED for part in relative.parts)


def content_path(folder, relative):
    """Reject traversal, hidden files, unsupported files, and symlinks."""
    path = Path(relative)
    if path.is_absolute() or '..' in path.parts or not visible(path) or path.suffix.lower() not in TYPES:
        raise PermissionError('File is outside the feed.')
    candidate = folder / path
    current = folder
    for part in path.parts:
        current = current / part
        if current.is_symlink():
            raise PermissionError('Symlinks are excluded from the feed.')
    resolved = candidate.resolve(strict=True)
    if not resolved.is_relative_to(folder) or resolved.suffix.lower() not in TYPES:
        raise PermissionError('File is outside the feed.')
    if not resolved.is_file():
        raise FileNotFoundError(relative)
    return resolved


class Feed:
    def __init__(self, folder, base):
        self.folder, self.base = folder.resolve(), base
        self.notes = {}
        self.lock = threading.Lock()

    def snapshot(self, recursive=False):
        with self.lock:
            items, notes = [], {}
            if not self.folder.is_dir():
                raise FileNotFoundError('The folder is no longer available.')
            for directory, subdirs, files in os.walk(self.folder, followlinks=False):
                parent = Path(directory)
                subdirs[:] = [name for name in subdirs if visible(Path(name)) and not (parent / name).is_symlink()] if recursive else []
                for name in files:
                    kind = TYPES.get(Path(name).suffix.lower())
                    if not kind or not visible(Path(name)):
                        continue
                    relative = (parent / name).relative_to(self.folder).as_posix()
                    try:
                        path = content_path(self.folder, relative)
                        stat = path.stat()
                        version = f'{stat.st_mtime_ns}:{stat.st_size}'
                        item = {
                            'path': relative, 'name': name, 'type': kind,
                            'modified': stat.st_mtime_ns / 1_000_000,
                            'size': stat.st_size,
                            'url': f'{self.base}/files/{quote(relative)}?v={version}',
                        }
                        # DOCX archives are extracted by the browser's existing parser.
                        if kind == 'note' and path.suffix.lower() != '.docx':
                            cached = self.notes.get(relative)
                            if cached and cached[0] == version:
                                text = cached[1]
                            else:
                                with path.open('rb') as source:
                                    text = source.read(NOTE_LIMIT).decode('utf-8-sig', errors='replace')
                            notes[relative] = (version, text)
                            item.update(text=text, truncated=stat.st_size > NOTE_LIMIT)
                        items.append(item)
                    except FileNotFoundError:
                        continue  # Editors can replace a file while scanning.
                    except PermissionError:
                        continue
                    except OSError:
                        continue
            self.notes = notes
            return {'folder': str(self.folder), 'items': sorted(items, key=lambda item: (-item['modified'], item['path']))}


def byte_range(header, size):
    if not header:
        return 0, size - 1, False
    match = re.fullmatch(r'bytes=(\d*)-(\d*)', header)
    if not match or not any(match.groups()) or size == 0:
        raise ValueError('Unsatisfiable range')
    first, last = match.groups()
    if not first:
        length = int(last)
        if length <= 0:
            raise ValueError('Unsatisfiable range')
        start, end = max(0, size - length), size - 1
    else:
        start = int(first)
        end = min(int(last), size - 1) if last else size - 1
    if start >= size or start > end:
        raise ValueError('Unsatisfiable range')
    return start, end, True


class Handler(BaseHTTPRequestHandler):
    server_version = 'FolderFeed/1.0'

    def log_message(self, *_args):
        pass  # Keep tokens and filenames out of access logs.

    def end_headers(self):
        self.send_header('X-Content-Type-Options', 'nosniff')
        self.send_header('Referrer-Policy', 'no-referrer')
        self.send_header('Cross-Origin-Resource-Policy', 'same-origin')
        self.send_header('Cache-Control', 'no-store')
        super().end_headers()

    def reply(self, status, data, mime='application/json; charset=utf-8'):
        body = json.dumps(data).encode() if not isinstance(data, bytes) else data
        self.send_response(status)
        self.send_header('Content-Type', mime)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        if self.command != 'HEAD':
            self.wfile.write(body)

    def check_host(self):
        allowed = {f'127.0.0.1:{self.server.server_port}', f'localhost:{self.server.server_port}'}
        if self.headers.get('Host') not in allowed:
            raise PermissionError('Open the URL printed by the launcher.')
        origin = self.headers.get('Origin')
        if origin and origin not in {f'http://{host}' for host in allowed}:
            raise PermissionError('Cross-origin requests are not allowed.')
        if self.headers.get('Sec-Fetch-Site') == 'cross-site':
            raise PermissionError('Cross-site requests are not allowed.')

    def route(self):
        self.check_host()
        route = urlsplit(self.path)
        parts = route.path.split('/')
        if len(parts) < 4 or parts[1] != '_feed' or not hmac.compare_digest(parts[2], self.server.token):
            raise PermissionError('Open the URL printed by the launcher.')
        return '/' + '/'.join(parts[3:]), parse_qs(route.query)

    def send_file(self, path, content=False):
        with path.open('rb') as source:
            size = os.fstat(source.fileno()).st_size
            try:
                start, end, partial = byte_range(self.headers.get('Range'), size)
            except ValueError:
                self.send_response(416)
                self.send_header('Content-Range', f'bytes */{size}')
                self.send_header('Content-Length', '0')
                self.end_headers()
                return
            self.send_response(206 if partial else 200)
            mime = mimetypes.guess_type(path.name)[0] or 'application/octet-stream'
            if content and TYPES.get(path.suffix.lower()) == 'note' and path.suffix.lower() != '.docx':
                mime = 'text/plain; charset=utf-8'
            self.send_header('Content-Type', mime)
            self.send_header('Accept-Ranges', 'bytes')
            self.send_header('Content-Length', str(max(0, end - start + 1)))
            if partial:
                self.send_header('Content-Range', f'bytes {start}-{end}/{size}')
            if content and path.suffix.lower() != '.pdf':
                # SVGs opened directly cannot execute scripts in the viewer origin.
                # PDFs use Chrome's native viewer, which needs its own page policy.
                self.send_header('Content-Security-Policy', "sandbox; default-src 'none'; style-src 'unsafe-inline'")
            self.end_headers()
            if self.command == 'HEAD':
                return
            source.seek(start)
            remaining = end - start + 1
            while remaining > 0:
                chunk = source.read(min(256 * 1024, remaining))
                if not chunk:
                    break
                self.wfile.write(chunk)
                remaining -= len(chunk)

    def do_HEAD(self):
        self.do_GET()

    def do_GET(self):
        try:
            self.check_host()
            request_path = unquote(urlsplit(self.path).path)
            # React's compiled assets use root-relative URLs; they contain no folder data.
            if request_path.startswith('/static/') or request_path in ('/favicon.ico', '/manifest.json', '/logo192.png', '/logo512.png'):
                path = (self.server.build / request_path.lstrip('/')).resolve()
                if not path.is_relative_to(self.server.build):
                    raise PermissionError('Invalid asset path.')
                return self.send_file(path)
            route, query = self.route()
            if route == '/':
                config = json.dumps({'base': self.server.base}).replace('<', '\\u003c')
                html = (self.server.build / 'index.html').read_text()
                html = html.replace('</head>', f'<script>window.__LOCAL_FEED__={config};</script></head>')
                return self.reply(200, html.encode(), 'text/html; charset=utf-8')
            if route == '/api/ping':
                return self.reply(200, {'folder': str(self.server.feed.folder), 'pid': os.getpid(), 'version': 1})
            if route == '/api/feed':
                return self.reply(200, self.server.feed.snapshot(query.get('recursive') == ['1']))
            if route.startswith('/files/'):
                path = content_path(self.server.feed.folder, unquote(route[len('/files/'):]))
                return self.send_file(path, content=True)
            self.reply(404, {'error': 'Not found'})
        except PermissionError as problem:
            self.reply(403, {'error': str(problem)})
        except (FileNotFoundError, NotADirectoryError):
            self.reply(404, {'error': 'The file or folder is no longer available.'})
        except (BrokenPipeError, ConnectionResetError):
            pass
        except OSError:
            self.reply(503, {'error': 'The file or folder could not be read.'})

    def do_POST(self):
        try:
            route, _query = self.route()
            if route != '/api/stop':
                return self.reply(404, {'error': 'Not found'})
            self.reply(200, {'stopped': True})
            threading.Thread(target=self.server.shutdown, daemon=True).start()
        except PermissionError as problem:
            self.reply(403, {'error': str(problem)})
        except (BrokenPipeError, ConnectionResetError):
            pass


def make_server(folder, build, port=0, token=None):
    server = ThreadingHTTPServer(('127.0.0.1', port), Handler)
    server.daemon_threads = True
    server.token = token or secrets.token_urlsafe(32)
    server.base = f'/_feed/{server.token}'
    server.feed = Feed(folder, server.base)
    server.build = build.resolve()
    return server


def request(state, endpoint='ping', method='GET'):
    url = state['url'].rstrip('/') + '/api/' + endpoint
    opener = build_opener(ProxyHandler({}))
    with opener.open(Request(url, method=method), timeout=2) as response:
        return json.load(response)


def running(path, folder):
    try:
        state = json.loads(path.read_text())
        if request(state)['folder'] == str(folder):
            return state
    except (OSError, ValueError, KeyError):
        pass
    return None


def serve(args, folder, state_path, on_ready=None):
    server = make_server(folder, args.build_dir, args.port)
    state = {'url': f'http://127.0.0.1:{server.server_port}{server.base}/', 'pid': os.getpid(), 'folder': str(folder)}
    temporary = state_path.with_suffix(f'.{os.getpid()}.tmp')
    temporary.write_text(json.dumps(state))
    temporary.chmod(0o600)
    temporary.replace(state_path)
    if on_ready:
        on_ready()
    if args.foreground:
        print(state['url'], flush=True)
    try:
        server.serve_forever(poll_interval=0.2)
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
        try:
            if json.loads(state_path.read_text()).get('pid') == os.getpid():
                state_path.unlink()
        except (OSError, ValueError):
            pass


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('folder', nargs='?', default='.', type=Path)
    parser.add_argument('--stop', action='store_true', help='stop only this folder server')
    parser.add_argument('--status', action='store_true')
    parser.add_argument('--no-open', action='store_true', help='print the URL without opening Chrome')
    parser.add_argument('--foreground', action='store_true', help='stay in the terminal; Ctrl-C stops serving')
    parser.add_argument('--port', type=int, default=0, help='0 chooses a free port (default)')
    parser.add_argument('--build-dir', type=Path, default=REPO / 'dist' / 'local-feed')
    parser.add_argument('--state-dir', type=Path, default=Path(os.environ.get('TEXTVIEWER_FEED_STATE_DIR', Path.home() / '.cache' / 'textviewer-feed')))
    parser.add_argument('--_serve', action='store_true', help=argparse.SUPPRESS)
    args = parser.parse_args()
    if not 0 <= args.port <= 65535:
        parser.error('port must be between 0 and 65535')
    folder = args.folder.expanduser().resolve()
    args.build_dir = args.build_dir.expanduser().resolve()
    args.state_dir = args.state_dir.expanduser().resolve()
    args.state_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
    key = hashlib.sha256(str(folder).encode()).hexdigest()[:24]
    state_path = args.state_dir / f'{key}.json'
    if args._serve:
        serve(args, folder, state_path)
        return
    with (args.state_dir / f'{key}.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        state = running(state_path, folder)
        if args.stop:
            if state:
                request(state, 'stop', 'POST')
                # Wait for this process's state to clear before releasing the launch lock.
                for _ in range(50):
                    if not state_path.exists():
                        break
                    time.sleep(0.1)
            print('Folder feed stopped.' if state else 'No feed is running for this folder.')
            return
        if args.status:
            print(state['url'] if state else 'No feed is running for this folder.')
            return
        if not folder.is_dir():
            parser.error(f'not a directory: {folder}')
        if not (args.build_dir / 'index.html').is_file():
            parser.error('Build the viewer first: npm run build:feed')
        if args.foreground and not state:
            serve(args, folder, state_path, lambda: fcntl.flock(lock, fcntl.LOCK_UN))
            return
        if not state:
            command = [sys.executable, str(Path(__file__).resolve()), str(folder), '--_serve', '--port', str(args.port), '--build-dir', str(args.build_dir), '--state-dir', str(args.state_dir)]
            with (args.state_dir / f'{key}.log').open('ab') as log:
                process = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=log, stderr=log, start_new_session=True, close_fds=True)
            for _ in range(100):
                state = running(state_path, folder)
                if state or process.poll() is not None:
                    break
                time.sleep(0.05)
            if not state:
                if process.poll() is None:
                    process.terminate()
                raise SystemExit(f'Could not start the feed. See {args.state_dir / (key + ".log")}')
    print(state['url'])
    if not args.no_open:
        if sys.platform == 'darwin':
            result = subprocess.run(['open', '-a', 'Google Chrome', state['url']], check=False, capture_output=True)
            if result.returncode:
                subprocess.run(['open', state['url']], check=False)
        else:
            import webbrowser
            webbrowser.open(state['url'])


if __name__ == '__main__':
    main()
