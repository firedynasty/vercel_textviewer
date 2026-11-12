import { useEffect, useRef, useState } from 'react';
import { noteFilename } from '../utils/chatUtils';
import { exampleWorksheet, parseWorksheet, validateWorksheet, worksheetMarkdown } from '../utils/worksheetUtils';

export default function useFolderPractice(feed, chat) {
  const [open, setOpen] = useState(false);
  const [topic, setTopic] = useState('Adding fractions with unlike denominators');
  const [count, setCount] = useState(6);
  const [provider, setProvider] = useState('ollama');
  const [includeChat, setIncludeChat] = useState(true);
  const [sheet, setSheet] = useState(null);
  const [dirty, setDirty] = useState(false);
  const [busy, setBusy] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const pending = useRef(null);
  const folder = feed.snapshot?.folder;
  const previousFolder = useRef(folder);
  const base = window.__LOCAL_FEED__?.base;
  const { connect } = chat;

  useEffect(() => { if (open && base) connect(); }, [open, base, connect]);
  useEffect(() => () => pending.current?.abort(), []);
  useEffect(() => {
    if (previousFolder.current && folder && previousFolder.current !== folder) {
      pending.current?.abort();
      setOpen(false); setSheet(null); setDirty(false); setBusy(false); setSaving(false); setError(''); setNotice('');
    }
    if (folder) previousFolder.current = folder;
  }, [folder]);
  useEffect(() => {
    if (!dirty && !busy && !saving) return;
    const warn = event => { event.preventDefault(); event.returnValue = ''; };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty, busy, saving]);

  const canReplace = () => !busy && !saving && (!dirty || window.confirm('Replace this worksheet and discard its unsaved changes?'));
  const generate = async event => {
    event?.preventDefault();
    if (!canReplace()) return;
    setError(''); setNotice('');
    if (!base || feed.stopped) { setError('Open your folder with the Finder launcher to generate with AI. The example works here too.'); return; }
    const controller = new AbortController();
    pending.current = controller;
    setBusy(true);
    try {
      const response = await fetch(`${base}/api/practice/generate`, {
        method: 'POST', signal: controller.signal, headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ topic, count, provider,
          context: chat.session.context.filter(source => source.enabled !== false).map(({ path, text }) => ({ path, text })),
          messages: includeChat ? chat.session.messages.map(({ role, content }) => ({ role, content })) : [],
        }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || 'Could not generate a worksheet.');
      if (controller.signal.aborted) return;
      setSheet(validateWorksheet(result)); setDirty(true);
      setNotice('Your practice is ready. Answers were calculated with exact fraction arithmetic.');
    } catch (problem) { if (!controller.signal.aborted) setError(problem.message); }
    finally { if (!controller.signal.aborted) setBusy(false); }
  };
  const example = () => {
    if (!canReplace()) return;
    setSheet(exampleWorksheet()); setDirty(true); setError('');
    setNotice('Example worksheet. No AI request was made.');
  };
  const changeAttempt = (id, change) => {
    if (busy || saving) return;
    setSheet(previous => ({ ...previous, attempts: { ...previous.attempts,
      [id]: { value: '', checked: false, revealed: false, ...previous.attempts[id], ...change },
    } }));
    setDirty(true); setNotice('');
  };
  const save = async () => {
    if (!sheet || busy || saving) return;
    setSaving(true); setError(''); setNotice('');
    try {
      const path = await feed.saveNote(noteFilename(sheet.title, 'worksheet'), worksheetMarkdown(sheet));
      setDirty(false); setNotice(`Saved ${path} in ${folder}. Use Continue practice on its card to resume.`);
    } catch (problem) { setError(problem.message); }
    finally { setSaving(false); }
  };
  const load = async item => {
    if (!canReplace()) return;
    setOpen(true); setSaving(true); setError(''); setNotice('');
    try {
      const next = parseWorksheet(await feed.readWorksheet(item));
      setSheet(next); setTopic(next.request); setCount(next.problems.length); setDirty(false);
      if (['ollama', 'openai'].includes(next.provider)) setProvider(next.provider);
      setNotice(`Opened ${item.path}. Saving creates a new version with your progress.`);
    } catch (problem) { setError(problem.message); }
    finally { setSaving(false); }
  };
  return { open, setOpen, topic, setTopic, count, setCount, provider, setProvider, includeChat, setIncludeChat,
    sheet, dirty, busy, saving, error, notice, generate, example, changeAttempt, save, load,
    launch: () => { setOpen(true); setProvider(chat.session.provider); }, isLocal: Boolean(base),
  };
}
