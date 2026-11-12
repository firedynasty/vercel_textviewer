import { useCallback, useEffect, useRef, useState } from 'react';
import { chatMarkdown, CONTEXT_LIMIT, contextSize, contextText, emptyChat, noteFilename, parseChat, readChatStream, visibleChatMarkdown } from '../utils/chatUtils';

export default function useFolderChat(feed) {
  const [open, setOpen] = useState(false);
  const [session, setSession] = useState(emptyChat);
  const [dirty, setDirty] = useState(false);
  const [busy, setBusy] = useState(false);
  const [saving, setSaving] = useState(false);
  const [answer, setAnswer] = useState('');
  const [question, setQuestion] = useState('');
  const [filename, setFilename] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [providers, setProviders] = useState(null);
  const [connecting, setConnecting] = useState(false);
  const [selection, setSelection] = useState(null);
  const controller = useRef(null);
  const providerController = useRef(null);
  const base = window.__LOCAL_FEED__?.base;
  const folder = feed.snapshot?.folder;
  const previousFolder = useRef(folder);

  useEffect(() => () => { controller.current?.abort(); providerController.current?.abort(); }, []);
  useEffect(() => {
    if (!dirty && !busy) return;
    const warn = event => { event.preventDefault(); event.returnValue = ''; };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty, busy]);
  useEffect(() => {
    if (previousFolder.current && folder && previousFolder.current !== folder) {
      controller.current?.abort();
      setOpen(false);
      setSession(emptyChat());
      setDirty(false);
      setAnswer('');
      setQuestion('');
      setFilename('');
      setSelection(null);
      setNotice('');
      setError('');
    }
    if (folder) previousFolder.current = folder;
  }, [folder]);

  const connect = useCallback(async () => {
    if (!base) return;
    providerController.current?.abort();
    const pending = new AbortController();
    providerController.current = pending;
    setConnecting(true);
    try {
      const response = await fetch(`${base}/api/chat/providers`, { signal: pending.signal, cache: 'no-store' });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Could not connect to Activity Island.');
      setProviders(data);
      setError(data.contextChat === 1 ? '' : 'Restart Activity Island to load the folder-chat update, then click Reconnect.');
    } catch (problem) {
      if (problem.name !== 'AbortError') { setProviders(null); setError(problem.message); }
    } finally { if (!pending.signal.aborted) setConnecting(false); }
  }, [base]);
  useEffect(() => { if (open) connect(); }, [open, connect]);

  const change = updater => {
    if (busy || saving) return;
    setSession(previous => typeof updater === 'function' ? updater(previous) : updater);
    setDirty(true);
    setNotice('');
  };
  const add = (item, text = visibleChatMarkdown(item.text || '')) => {
    if (busy || saving || !text.trim()) return;
    setOpen(true);
    change(previous => {
      const duplicate = previous.context.find(source => source.path === item.path && source.text === text);
      const context = duplicate
        ? previous.context.map(source => source.id === duplicate.id ? { ...source, enabled: true } : source)
        : [...previous.context, { id: `${Date.now()}-${Math.random().toString(36).slice(2)}`, path: item.path, text, enabled: true, truncated: Boolean(item.truncated) }];
      return { ...previous, context };
    });
    setSelection(null);
  };
  const capture = (event, item) => {
    const selected = window.getSelection();
    if (!selected || selected.isCollapsed || !event.currentTarget.contains(selected.anchorNode) || !event.currentTarget.contains(selected.focusNode)) {
      setSelection(null);
      return;
    }
    const text = selected.toString();
    setSelection(text.trim() ? { path: item.path, text } : null);
  };

  const ask = async event => {
    event?.preventDefault();
    if (busy || saving || !question.trim()) return;
    setError('');
    setNotice('');
    if (!base) { setError('Open this folder using the Finder Quick Action to chat with Activity Island. You can still collect, copy, and save context here.'); return; }
    if (feed.stopped) { setError('Reopen the folder to reconnect.'); return; }
    if (contextSize(session.context) > CONTEXT_LIMIT) { setError('Selected context exceeds 60,000 characters. Shorten a passage or uncheck a source.'); return; }
    const sources = session.context.filter(item => item.enabled !== false).map(({ path, text }) => ({ path, text }));
    const user = { role: 'user', content: question.trim(), sources };
    const messages = [...session.messages, user];
    const request = new AbortController();
    controller.current = request;
    setBusy(true);
    setAnswer('');
    try {
      const response = await fetch(`${base}/api/chat/ask`, {
        method: 'POST', signal: request.signal, headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ provider: session.provider, web: session.web, context: sources, messages: messages.map(({ role, content }) => ({ role, content })) }),
      });
      const content = await readChatStream(response, setAnswer);
      setSession(previous => ({ ...previous, messages: [...messages, { role: 'assistant', content, provider: session.provider }] }));
      setQuestion('');
      setDirty(true);
    } catch (problem) {
      if (problem.name !== 'AbortError') setError(problem.message);
      else setNotice('Stopped. Your question is ready to edit or retry.');
    } finally { setBusy(false); setAnswer(''); }
  };

  const save = async (message = null) => {
    if (saving || busy) return;
    setSaving(true);
    setError('');
    try {
      const name = message ? noteFilename(session.title, 'answer') : (filename.trim() || noteFilename(session.title));
      const text = message
        ? `# ${session.title.replace(/[\r\n]/g, ' ')}\n\n${message.content}\n\n## Source filenames\n\n${(session.messages[session.messages.indexOf(message) - 1]?.sources || []).map(source => `- ${source.path}`).join('\n')}\n${session.notes.trim() ? `\n## My notes\n\n${session.notes}\n` : ''}`
        : chatMarkdown(session);
      const path = await feed.saveNote(name, text);
      setNotice(`Saved ${path} in ${folder}.`);
      if (!message) setDirty(false);
    } catch (problem) { setError(problem.message); }
    finally { setSaving(false); }
  };
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(contextText(session.context));
      setNotice('Selected context copied. Paste it into your chat.');
    } catch { setError('Could not copy. Check browser clipboard access.'); }
  };
  const load = async item => {
    if (busy || saving || (dirty && !window.confirm('Open this conversation and discard the current unsaved changes?'))) return;
    setOpen(true);
    try {
      const next = parseChat(await feed.readChat(item));
      setSession(next);
      setDirty(false);
      setAnswer('');
      setQuestion('');
      setFilename(noteFilename(next.title));
      setNotice(`Opened ${item.path}. Saving creates a new version.`);
      setError('');
    } catch (problem) { setError(problem.message); }
  };
  const reset = () => {
    if (busy || saving || (dirty && !window.confirm('Start a new chat and discard the current unsaved changes?'))) return;
    setSession(emptyChat()); setDirty(false); setQuestion(''); setAnswer(''); setFilename(''); setError(''); setNotice('');
  };

  return { open, setOpen, session, change, dirty, busy, saving, answer, question, setQuestion, filename, setFilename,
    error, notice, providers, connecting, connect, selection, capture, add, ask, save, copy, load, reset,
    stop: () => controller.current?.abort(), isLocal: Boolean(base) };
}
