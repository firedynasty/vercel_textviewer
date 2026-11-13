import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import threading
import unittest
from concurrent.futures import ThreadPoolExecutor

from chat import SAVE_LIMIT, activity_connection, save_markdown, validate_chat
from server import make_server, request


class SaveTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.folder = Path(self.temp.name)

    def test_existing_files_and_symlinks_are_never_overwritten(self):
        (self.folder / 'notes.md').write_text('original')
        (self.folder / 'notes-2.md').symlink_to(self.folder / 'notes.md')
        self.assertEqual(save_markdown(self.folder, 'notes.md', 'new'), 'notes-3.md')
        self.assertEqual((self.folder / 'notes.md').read_text(), 'original')
        self.assertEqual((self.folder / 'notes-3.md').read_text(), 'new')

    def test_rejects_paths_hidden_files_and_large_empty_or_non_markdown_notes(self):
        for name in ('../outside.md', '/outside.md', 'sub/note.md', 'sub\\note.md', '.hidden.md', 'note.txt', 'bad\n.md'):
            with self.subTest(name=name), self.assertRaises(ValueError):
                save_markdown(self.folder, name, 'content')
        for text in (' ', None, 'x' * (SAVE_LIMIT + 1)):
            with self.assertRaises(ValueError):
                save_markdown(self.folder, 'note.md', text)
        self.assertEqual(list(self.folder.iterdir()), [])

    def test_simultaneous_saves_create_distinct_complete_unicode_notes(self):
        with ThreadPoolExecutor(max_workers=8) as pool:
            names = list(pool.map(lambda n: save_markdown(self.folder, '研究.md', f'Note {n} 你好'), range(8)))
        self.assertEqual(len(set(names)), 8)
        self.assertEqual({path.read_text() for path in self.folder.iterdir()}, {f'Note {n} 你好' for n in range(8)})

    def test_chat_context_is_preserved_and_limits_reject_instead_of_truncate(self):
        payload = {'provider': 'ollama', 'context': [{'path': 'long.txt', 'text': 'x' * 5000}], 'messages': [{'role': 'user', 'content': 'question'}]}
        self.assertEqual(len(validate_chat(payload)['context'][0]['text']), 5000)
        payload['context'][0]['text'] = 'x' * 60001
        with self.assertRaises(ValueError):
            validate_chat(payload)

    def test_upstream_is_restricted_to_loopback(self):
        for url in ('https://api.example.com', 'http://example.com', 'http://127.0.0.1@evil.com', 'http://user@127.0.0.1', 'http://127.0.0.1/private'):
            with self.subTest(url=url), self.assertRaises(ValueError):
                activity_connection(url)


class FakeActivity(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def do_GET(self):
        self.send_response(200)
        self.end_headers()
        self.wfile.write(json.dumps({'contextChat': self.server.context_version, 'ollama': {'ok': True, 'haveModel': True, 'model': 'test'}}).encode())

    def do_POST(self):
        self.server.last_request = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        self.send_response(self.server.chat_status)
        self.end_headers()
        self.wfile.write(getattr(self.server, 'chat_response', b'{"delta":"Answer"}\n{"done":true}\n') if self.server.chat_status == 200 else b'Provider unavailable')


class ChatHTTPTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.folder = Path(self.temp.name)
        self.activity = ThreadingHTTPServer(('127.0.0.1', 0), FakeActivity)
        self.activity.context_version = 1
        self.activity.chat_status = 200
        self.activity.last_request = None
        self.viewer = make_server(self.folder, self.folder, chat_url=f'http://127.0.0.1:{self.activity.server_port}')
        for server in (self.activity, self.viewer):
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            self.addCleanup(server.server_close)
            self.addCleanup(server.shutdown)

    def request(self, method, route, data=None, headers=None):
        connection = http.client.HTTPConnection('127.0.0.1', self.viewer.server_port, timeout=5)
        connection.request(method, self.viewer.base + route, body=json.dumps(data) if data is not None else None,
                           headers=headers if headers is not None else {'Content-Type': 'application/json'})
        response = connection.getresponse()
        result = response.status, response.read()
        connection.close()
        return result

    def test_save_is_visible_in_feed_and_full_conversation_can_be_reopened(self):
        text = '<!-- textviewer-chat:v1\n{}\n-->\n# Chat\n' + 'x' * (256 * 1024)
        status, body = self.request('POST', '/api/notes', {'filename': 'chat.md', 'text': text})
        self.assertEqual((status, json.loads(body)['path']), (201, 'chat.md'))
        status, body = self.request('GET', '/api/feed')
        item = json.loads(body)['items'][0]
        self.assertTrue(item['chat'])
        self.assertTrue(item['truncated'])
        status, body = self.request('GET', '/api/chat/load?path=chat.md')
        self.assertEqual(json.loads(body)['text'], text)

    def test_write_and_chat_endpoints_reject_cross_origin_and_non_json(self):
        data = {'filename': 'safe.md', 'text': 'content'}
        for route in ('/api/notes', '/api/chat/ask'):
            status, _ = self.request('POST', route, data, {'Content-Type': 'application/json', 'Origin': 'https://evil.example'})
            self.assertEqual(status, 403)
            status, _ = self.request('POST', route, data, {'Content-Type': 'text/plain'})
            self.assertEqual(status, 400)
        self.assertEqual(list(self.folder.iterdir()), [])

    def test_only_explicit_context_and_history_are_forwarded(self):
        payload = {'provider': 'ollama', 'context': [{'path': 'notes.txt', 'text': 'selected passage'}],
                   'messages': [{'role': 'user', 'content': 'Explain'}], 'unrelated': 'never forwarded'}
        status, body = self.request('POST', '/api/chat/ask', payload)
        self.assertEqual(status, 200)
        self.assertIn(b'"done":true', body)
        self.assertEqual(self.activity.last_request['context'], payload['context'])
        self.assertNotIn('unrelated', self.activity.last_request)
        self.assertEqual(self.activity.last_request['id'], 'folder-reader')

    def test_old_backend_and_upstream_errors_are_reported(self):
        payload = {'provider': 'ollama', 'context': [], 'messages': [{'role': 'user', 'content': 'Explain'}]}
        self.activity.context_version = 0
        self.assertEqual(self.request('POST', '/api/chat/ask', payload)[0], 409)
        self.assertIsNone(self.activity.last_request)
        self.activity.context_version = 1
        self.activity.chat_status = 502
        status, body = self.request('POST', '/api/chat/ask', payload)
        self.assertEqual(status, 502)
        self.assertEqual(json.loads(body)['error'], 'Provider unavailable')

    def test_practice_generation_uses_selected_context_and_can_be_saved_and_reopened(self):
        draft = {'title': 'Adding fractions', 'problems': [{'left': '1/2', 'operator': '+', 'right': '1/3', 'answer': '2/5'}]}
        self.activity.chat_response = (json.dumps({'delta': json.dumps(draft)}) + '\n{"done":true}\n').encode()
        payload = {'topic': 'Fractions from Brilliant', 'count': 1, 'provider': 'ollama',
                   'context': [{'path': 'fractions.txt', 'text': 'I keep adding denominators.'}],
                   'messages': [{'role': 'user', 'content': 'I got 2/5.'}, {'role': 'assistant', 'content': 'Use common units.'}]}
        status, body = self.request('POST', '/api/practice/generate', payload)
        self.assertEqual(status, 200, body)
        sheet = json.loads(body)
        self.assertEqual(sheet['problems'][0]['answer'], '5/6')
        self.assertEqual(sheet['sources'], payload['context'])
        self.assertEqual(sheet['conversation'], payload['messages'])
        self.assertEqual(self.activity.last_request['context'], payload['context'])
        self.assertEqual(self.activity.last_request['messages'][:2], payload['messages'])
        self.assertFalse(self.activity.last_request['web'])
        self.assertEqual(list(self.folder.iterdir()), [])  # Only Save writes.
        sheet['attempts'] = {'1': {'value': '5/6', 'checked': True}}
        saved = '<!-- textviewer-practice:v1\n' + json.dumps(sheet) + '\n-->\n# Adding fractions\n'
        self.assertEqual(self.request('POST', '/api/notes', {'filename': 'practice.md', 'text': saved})[0], 201)
        status, body = self.request('GET', '/api/practice/load?path=practice.md')
        self.assertEqual(json.loads(body)['text'], saved)
        items = json.loads(self.request('GET', '/api/feed')[1])['items']
        self.assertTrue(items[0]['worksheet'])
        self.assertFalse(items[0]['chat'])

    def test_practice_rejects_cross_origin_invalid_counts_and_incomplete_model_output(self):
        payload = {'topic': 'Fractions', 'count': 1, 'provider': 'ollama'}
        self.assertEqual(self.request('POST', '/api/practice/generate', payload, {'Content-Type': 'application/json', 'Origin': 'https://evil.example'})[0], 403)
        self.assertEqual(self.request('POST', '/api/practice/generate', {**payload, 'count': 13})[0], 400)
        self.assertIsNone(self.activity.last_request)
        self.activity.chat_response = b'{"delta":"{}"}\n'
        status, body = self.request('POST', '/api/practice/generate', payload)
        self.assertEqual(status, 400)
        self.assertIn('interrupted', json.loads(body)['error'])
        self.assertEqual(list(self.folder.iterdir()), [])


class LauncherUpgradeTests(unittest.TestCase):
    def test_relaunch_replaces_an_older_protocol_server(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = root / 'notes'
            folder.mkdir()
            build = root / 'build'
            build.mkdir()
            (build / 'index.html').write_text('<html><head></head></html>')
            current = Path(__file__).with_name('server.py')
            # Downgrade whichever API version this checkout serves, so the test survives later bumps.
            source = re.sub(r"('pid': os\.getpid\(\), 'version': )\d+", r"\g<1>1", current.read_text())
            source = source.replace('from chat import ', f'sys.path.insert(0, {str(current.parent)!r})\nfrom chat import ', 1)
            old = root / 'old_server.py'
            old.write_text(source)
            options = [str(folder), '--build-dir', str(build), '--state-dir', str(root / 'state'), '--no-open']
            first = subprocess.check_output([sys.executable, str(old), *options], text=True, timeout=10).strip()
            try:
                before = request({'url': first})
                self.assertEqual(before['version'], 1)
                second = subprocess.check_output([sys.executable, str(current), *options], text=True, timeout=10).strip()
                after = request({'url': second})
                self.assertEqual(after['version'], int(re.search(r"'version': (\d+)\}", current.read_text()).group(1)))
                self.assertNotEqual(before['pid'], after['pid'])
            finally:
                subprocess.run([sys.executable, str(current), *options, '--stop'], check=True, capture_output=True, timeout=10)


if __name__ == '__main__':
    unittest.main()
