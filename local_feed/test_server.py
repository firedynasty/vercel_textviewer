import http.client
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import threading
import unittest
from urllib.parse import quote, urlsplit

from server import Feed, NOTE_LIMIT, byte_range, make_server


class FeedTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.folder = self.root / 'notes'
        self.folder.mkdir()
        self.feed = Feed(self.folder, '/_feed/test')

    def test_updates_additions_deletions_and_stable_content_cache(self):
        note = self.folder / 'daily.txt'
        note.write_text('before #notes')
        first = self.feed.snapshot()['items'][0]
        old_version = self.feed.notes['daily.txt'][0]
        note.write_text('after! #notes')
        os.utime(note, ns=(note.stat().st_atime_ns, note.stat().st_mtime_ns + 1_000_000))
        second = self.feed.snapshot()['items'][0]
        self.assertEqual(second['text'], 'after! #notes')
        self.assertNotEqual(first['url'], second['url'])
        self.assertNotEqual(old_version, self.feed.notes['daily.txt'][0])
        (self.folder / 'new.png').write_bytes(b'image')
        self.assertEqual(len(self.feed.snapshot()['items']), 2)
        note.unlink()
        self.assertEqual([item['name'] for item in self.feed.snapshot()['items']], ['new.png'])
        self.assertEqual(self.feed.notes, {})

    def test_recursive_hidden_unsupported_and_symlinks(self):
        (self.folder / 'nested').mkdir()
        (self.folder / 'nested' / 'note.md').write_text('# Hello')
        (self.folder / '.hidden.txt').write_text('secret')
        (self.folder / 'code.py').write_text('code')
        (self.folder / '.secret').mkdir()
        (self.folder / '.secret' / 'note.txt').write_text('secret')
        (self.folder / 'alias').symlink_to(self.root, target_is_directory=True)
        (self.folder / 'linked.txt').symlink_to(self.folder / 'nested' / 'note.md')
        self.assertEqual(self.feed.snapshot()['items'], [])
        self.assertEqual([item['path'] for item in self.feed.snapshot(True)['items']], ['nested/note.md'])

    def test_empty_large_and_unicode_notes_do_not_change_folder(self):
        (self.folder / '你好 #1?.txt').write_text('こんにちは')
        (self.folder / 'empty.txt').touch()
        (self.folder / 'large.md').write_text('x' * (NOTE_LIMIT + 20))
        (self.folder / 'index.php').write_text('user file')
        before = {path.name: path.read_bytes() for path in self.folder.iterdir()}
        items = {item['name']: item for item in self.feed.snapshot()['items']}
        self.assertEqual(items['empty.txt']['text'], '')
        self.assertEqual(items['你好 #1?.txt']['text'], 'こんにちは')
        self.assertIn('%23', items['你好 #1?.txt']['url'])
        self.assertTrue(items['large.md']['truncated'])
        self.assertEqual(len(items['large.md']['text']), NOTE_LIMIT)
        self.assertEqual(before, {path.name: path.read_bytes() for path in self.folder.iterdir()})

    def test_docx_is_a_note_with_binary_url_for_browser_extraction(self):
        contents = b'PK\x03\x04\xff\x00archive'
        (self.folder / 'Practice.DOCX').write_bytes(contents)
        item = self.feed.snapshot()['items'][0]
        self.assertEqual(item['type'], 'note')
        self.assertEqual(item['name'], 'Practice.DOCX')
        self.assertIn('/files/Practice.DOCX?v=', item['url'])
        self.assertNotIn('text', item)
        self.assertEqual(self.feed.notes, {})
        self.assertEqual((self.folder / 'Practice.DOCX').read_bytes(), contents)

    def test_pdf_metadata_is_listed_without_extracting_text(self):
        (self.folder / 'Guide.PDF').write_bytes(b'%PDF-1.4\nexample')
        item = self.feed.snapshot()['items'][0]
        self.assertEqual(item['type'], 'pdf')
        self.assertIn('/files/Guide.PDF?v=', item['url'])
        self.assertNotIn('text', item)
        self.assertEqual(self.feed.notes, {})


class HTTPTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.folder = self.root / 'folder'
        self.folder.mkdir()
        self.build = self.root / 'build'
        (self.build / 'static').mkdir(parents=True)
        (self.build / 'index.html').write_text('<html><head></head><body>viewer</body></html>')
        (self.build / 'static' / 'app.js').write_text('/* app */')
        (self.folder / 'clip.mp4').write_bytes(b'0123456789')
        self.server = make_server(self.folder, self.build)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.base = self.server.base
        self.addCleanup(self.close_server)

    def close_server(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=3)

    def get(self, path, headers=None, method='GET'):
        connection = http.client.HTTPConnection('127.0.0.1', self.server.server_port, timeout=3)
        connection.request(method, path, headers=headers or {})
        response = connection.getresponse()
        result = response.status, dict(response.getheaders()), response.read()
        connection.close()
        return result

    def post(self, path, payload):
        connection = http.client.HTTPConnection('127.0.0.1', self.server.server_port, timeout=3)
        connection.request('POST', path, body=json.dumps(payload), headers={'Content-Type': 'application/json'})
        response = connection.getresponse()
        result = response.status, response.read()
        connection.close()
        return result

    def test_rename_keeps_the_folder_and_type_and_never_replaces_a_file(self):
        (self.folder / 'notes').mkdir()
        (self.folder / 'notes' / 'first.md').write_text('hello')
        (self.folder / 'notes' / 'taken.md').write_text('other')
        status, body = self.post(self.base + '/api/rename', {'path': 'notes/first.md', 'name': ' Bits and bytes '})
        self.assertEqual((status, json.loads(body)['path']), (200, 'notes/Bits and bytes.md'))
        self.assertEqual((self.folder / 'notes' / 'Bits and bytes.md').read_text(), 'hello')
        self.assertFalse((self.folder / 'notes' / 'first.md').exists())
        for payload, expected in [
            ({'path': 'notes/Bits and bytes.md', 'name': 'taken.md'}, 400),
            ({'path': 'notes/Bits and bytes.md', 'name': 'renamed.txt'}, 400),
            ({'path': 'notes/Bits and bytes.md', 'name': '../escaped.md'}, 400),
            ({'path': 'notes/Bits and bytes.md', 'name': '.hidden.md'}, 400),
            ({'path': 'notes/Bits and bytes.md', 'name': 'line\nbreak.md'}, 400),
            ({'path': 'notes/Bits and bytes.md', 'name': ''}, 400),
            ({'path': '../private.md', 'name': 'escaped.md'}, 403),
            ({'path': 'notes/missing.md', 'name': 'anything.md'}, 404),
        ]:
            with self.subTest(payload=payload):
                self.assertEqual(self.post(self.base + '/api/rename', payload)[0], expected)
        self.assertEqual((self.folder / 'notes' / 'taken.md').read_text(), 'other')
        self.assertEqual(sorted(path.name for path in (self.folder / 'notes').iterdir()), ['Bits and bytes.md', 'taken.md'])

    def test_bootstrap_api_and_public_assets(self):
        status, headers, body = self.get(self.base + '/')
        self.assertEqual(status, 200)
        self.assertIn(b'window.__LOCAL_FEED__=', body)
        self.assertIn(self.base.encode(), body)
        self.assertEqual(headers['Cache-Control'], 'no-store')
        self.assertEqual(self.get('/static/app.js')[0], 200)
        status, _, body = self.get(self.base + '/api/feed')
        self.assertEqual(status, 200)
        self.assertEqual(json.loads(body)['items'][0]['name'], 'clip.mp4')

    def test_access_controls(self):
        for path, headers in [
            ('/_feed/wrong/api/feed', {}),
            (self.base + '/api/feed', {'Host': 'attacker.example'}),
            (self.base + '/api/feed', {'Origin': 'https://attacker.example'}),
            (self.base + '/api/feed', {'Sec-Fetch-Site': 'cross-site'}),
            (self.base + '/files/%2e%2e/private.txt', {}),
            (self.base + '/files/.secret.txt', {}),
            (self.base + '/files/script.py', {}),
            ('/static/../../private.txt', {}),
        ]:
            with self.subTest(path=path, headers=headers):
                self.assertEqual(self.get(path, headers)[0], 403)
        (self.folder / 'linked.txt').symlink_to(self.build / 'index.html')
        self.assertEqual(self.get(self.base + '/files/linked.txt')[0], 403)

    def test_video_ranges_and_head(self):
        path = self.base + '/files/clip.mp4'
        for header, expected, content_range in [
            ('bytes=0-2', b'012', 'bytes 0-2/10'),
            ('bytes=7-', b'789', 'bytes 7-9/10'),
            ('bytes=-4', b'6789', 'bytes 6-9/10'),
            ('bytes=8-999', b'89', 'bytes 8-9/10'),
        ]:
            with self.subTest(header=header):
                status, headers, body = self.get(path, {'Range': header})
                self.assertEqual((status, body), (206, expected))
                self.assertEqual(headers['Content-Range'], content_range)
                self.assertEqual(headers['Content-Length'], str(len(expected)))
                self.assertEqual(headers['Accept-Ranges'], 'bytes')
        status, headers, body = self.get(path, method='HEAD')
        self.assertEqual((status, body, headers['Content-Length']), (200, b'', '10'))
        for invalid in ['bytes=20-', 'bytes=5-2', 'bytes=-0', 'bytes=0-1,4-5', 'invalid']:
            self.assertEqual(self.get(path, {'Range': invalid})[0], 416)

    def test_unicode_paths_and_plaintext_mime(self):
        name = '你好 #1?.md'
        (self.folder / name).write_text('<script>alert(1)</script>')
        status, headers, body = self.get(self.base + '/files/' + quote(name))
        self.assertEqual(status, 200)
        self.assertEqual(headers['Content-Type'], 'text/plain; charset=utf-8')
        self.assertIn('sandbox', headers['Content-Security-Policy'])
        self.assertIn(b'<script>', body)

    def test_docx_download_preserves_binary_and_word_mime(self):
        contents = b'PK\x03\x04\xff\x00archive'
        name = '你好 #notes.DOCX'
        (self.folder / name).write_bytes(contents)
        status, headers, body = self.get(self.base + '/files/' + quote(name))
        self.assertEqual((status, body), (200, contents))
        self.assertEqual(headers['Content-Type'], 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')

    def test_pdf_link_serves_original_for_the_native_browser_viewer(self):
        contents = b'%PDF-1.4\nexample'
        (self.folder / 'Guide #1.PDF').write_bytes(contents)
        status, headers, body = self.get(self.base + '/files/' + quote('Guide #1.PDF'))
        self.assertEqual((status, body), (200, contents))
        self.assertEqual(headers['Content-Type'], 'application/pdf')
        self.assertNotIn('Content-Security-Policy', headers)

    def test_stop_endpoint(self):
        self.assertEqual(self.get(self.base + '/api/stop', method='POST')[0], 200)
        self.thread.join(timeout=3)
        self.assertFalse(self.thread.is_alive())

    def test_launcher_reuses_and_stops_folder_server(self):
        command = [sys.executable, str(Path(__file__).with_name('server.py')), str(self.folder), '--build-dir', str(self.build), '--state-dir', str(self.root / 'state'), '--no-open']
        first = subprocess.check_output(command, text=True, timeout=10).strip()
        try:
            second = subprocess.check_output(command, text=True, timeout=10).strip()
            self.assertEqual(first, second)
            parsed = urlsplit(first)
            self.assertNotEqual(parsed.port, 0)
            self.assertEqual(subprocess.check_output(command + ['--status'], text=True, timeout=10).strip(), first)
        finally:
            subprocess.run(command + ['--stop'], check=True, capture_output=True, timeout=10)
        self.assertIn('No feed', subprocess.check_output(command + ['--status'], text=True, timeout=10))


class RangeTests(unittest.TestCase):
    def test_empty_files(self):
        self.assertEqual(byte_range(None, 0), (0, -1, False))
        with self.assertRaises(ValueError):
            byte_range('bytes=0-', 0)


if __name__ == '__main__':
    unittest.main()
