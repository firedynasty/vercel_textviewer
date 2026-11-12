"""Folder-scoped Markdown saves and a loopback-only Activity Island connection."""
import http.client
import json
import os
from pathlib import Path
import re
from urllib.parse import urlsplit

SAVE_LIMIT = 2 * 1024 * 1024
CHAT_LIMIT = 1024 * 1024


def save_markdown(folder, filename, text):
    if not isinstance(filename, str) or not re.fullmatch(r'[^/\\\x00-\x1f\x7f]+\.md', filename, re.IGNORECASE):
        raise ValueError('Choose a Markdown filename ending in .md, without folders.')
    if filename.startswith('.') or len(filename.encode('utf-8')) > 220:
        raise ValueError('Choose a shorter filename that does not start with a dot.')
    if not isinstance(text, str) or not text.strip():
        raise ValueError('There is nothing to save.')
    data = text.encode('utf-8')
    if len(data) > SAVE_LIMIT:
        raise ValueError('This note is too large to save (maximum 2 MiB).')
    # Pin the directory and create exclusively: never follow or replace a file/symlink.
    directory = os.open(folder, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        for number in range(1, 10001):
            name = filename if number == 1 else f'{Path(filename).stem}-{number}.md'
            try:
                fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=directory)
            except FileExistsError:
                continue
            try:
                with os.fdopen(fd, 'wb') as output:
                    output.write(data)
                    output.flush()
                    os.fsync(output.fileno())
            except BaseException:
                os.unlink(name, dir_fd=directory)
                raise
            return name
    finally:
        os.close(directory)
    raise ValueError('Too many notes with this filename. Choose another name.')


def activity_connection(url, timeout=120):
    target = urlsplit(url)
    if target.scheme != 'http' or target.hostname not in ('127.0.0.1', 'localhost', '::1') or target.username or target.password or target.path not in ('', '/') or target.query or target.fragment:
        raise ValueError('Activity Island must use a local http://127.0.0.1:port address.')
    return http.client.HTTPConnection(target.hostname, target.port or 8787, timeout=timeout)


def activity_providers(url):
    connection = activity_connection(url, timeout=5)
    try:
        connection.request('GET', '/providers')
        response = connection.getresponse()
        if response.status != 200:
            raise OSError('Activity Island could not report its providers.')
        data = response.read(65537)
        if len(data) > 65536:
            raise OSError('Unexpected provider response from Activity Island.')
        return json.loads(data)
    finally:
        connection.close()


def validate_chat(payload):
    if not isinstance(payload, dict):
        raise ValueError('Expected a chat object.')
    context, messages = payload.get('context'), payload.get('messages')
    if not isinstance(context, list) or len(context) > 200:
        raise ValueError('Select up to 200 source passages.')
    if any(not isinstance(item, dict) or not isinstance(item.get('path'), str) or not isinstance(item.get('text'), str) for item in context):
        raise ValueError('Each source needs a filename and text.')
    if sum(len(item['path']) + len(item['text']) for item in context) > 60000:
        raise ValueError('Selected context exceeds 60,000 characters. Remove or shorten a passage.')
    if not isinstance(messages, list) or not 1 <= len(messages) <= 100:
        raise ValueError('Save this conversation and start a new chat (maximum 100 messages).')
    if any(not isinstance(item, dict) or item.get('role') not in ('user', 'assistant') or not isinstance(item.get('content'), str) for item in messages):
        raise ValueError('Invalid conversation messages.')
    if messages[-1]['role'] != 'user' or not messages[-1]['content'].strip():
        raise ValueError('Enter a question first.')
    if sum(len(item['content']) for item in messages) > 240000:
        raise ValueError('This conversation is too long. Save it and start a new chat.')
    if payload.get('provider') not in ('ollama', 'openai'):
        raise ValueError('Choose Ollama or OpenAI.')
    return {
        'context': [{'path': item['path'], 'text': item['text']} for item in context],
        'messages': [{'role': item['role'], 'content': item['content']} for item in messages],
        'provider': payload['provider'], 'web': payload.get('web') is True,
        'id': 'folder-reader', 'name': 'Notes teacher',
    }
