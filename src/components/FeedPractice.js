import React, { useEffect, useRef } from 'react';
import { checkPracticeAnswer, problemText } from '../utils/worksheetUtils';
import './FeedPractice.css';

function Problem({ problem, attempt = {}, onChange, disabled }) {
  const feedback = attempt.checked ? checkPracticeAnswer(problem, attempt.value || '') : null;
  return <article className={`practice-problem${feedback?.ok ? ' practice-problem--correct' : ''}`}>
    <div className="practice-problem-number">PROBLEM {problem.id}</div>
    <h3>{problemText(problem)} <span>= ?</span></h3>
    <form onSubmit={event => { event.preventDefault(); onChange({ checked: true }); }}>
      <label htmlFor={`practice-answer-${problem.id}`}>Your answer</label>
      <div className="practice-answer-row"><input id={`practice-answer-${problem.id}`} value={attempt.value || ''} maxLength={80} disabled={disabled} autoComplete="off" placeholder="e.g. 5/6" onChange={event => onChange({ value: event.target.value, checked: false })} />
        <button className="practice-primary" disabled={disabled || !attempt.value?.trim()} type="submit">Check answer</button></div>
    </form>
    {feedback && <p className={`practice-feedback${feedback.ok ? ' practice-feedback--correct' : ''}`} role="status">{feedback.message}</p>}
    <button className="practice-solution-toggle" disabled={disabled} onClick={() => onChange({ revealed: !attempt.revealed })} aria-expanded={Boolean(attempt.revealed)}>{attempt.revealed ? 'Hide worked steps' : 'Show worked steps'}</button>
    {attempt.revealed && <div className="practice-solution"><strong>Answer: {problem.answer}</strong><p>{problem.solution}</p></div>}
  </article>;
}

export default function FeedPractice({ practice, chat, folder, stopped }) {
  const dialog = useRef(null);
  const layout = useRef(null);
  const settings = useRef(null);
  const work = useRef(null);
  const sheetId = practice.sheet?.id;
  useEffect(() => {
    if (!practice.open) return;
    const element = dialog.current, previous = document.activeElement;
    element.showModal();
    return () => { element.close(); previous?.focus(); };
  }, [practice.open]);
  useEffect(() => {
    if (practice.open && sheetId) { layout.current?.scrollTo(0, 0); work.current?.scrollTo(0, 0); }
  }, [practice.open, sheetId]);
  if (!practice.open) return null;
  const locked = practice.busy || practice.saving;
  const sources = chat.session.context.filter(source => source.enabled !== false);
  const available = chat.providers?.contextChat === 1 && (practice.provider === 'openai'
    ? chat.providers?.openai?.configured : chat.providers?.ollama?.ok && chat.providers?.ollama?.haveModel);
  const sheet = practice.sheet;
  const correct = sheet?.problems.filter(p => sheet.attempts[p.id]?.checked && checkPracticeAnswer(p, sheet.attempts[p.id].value).ok).length || 0;
  return <dialog className="feed-practice" ref={dialog} aria-labelledby="practice-title" onCancel={event => { event.preventDefault(); practice.setOpen(false); }}>
    <header className="practice-header"><div><p>YOUR STUDY SPACE</p><h2 id="practice-title">A little practice, a little progress.</h2></div><button onClick={() => practice.setOpen(false)} aria-label="Close practice">×</button></header>
    <div className={`practice-layout${sheet ? ' practice-layout--sheet' : ''}`} ref={layout}>
      <aside className="practice-settings" ref={settings}>
        <h3>Make it yours</h3><p className="practice-muted">Bring a topic, your notes, or a conversation. Start with fraction arithmetic.</p>
        <form onSubmit={practice.generate}>
          <label className="practice-field">What are you practising?<textarea value={practice.topic} maxLength={2000} rows={4} disabled={locked} onChange={event => practice.setTopic(event.target.value)} /></label>
          <div className="practice-fields"><label className="practice-field">Problems<select value={practice.count} disabled={locked} onChange={event => practice.setCount(Number(event.target.value))}>{Array.from({ length: 12 }, (_, index) => index + 1).map(n => <option key={n} value={n}>{n}</option>)}</select></label>
            <label className="practice-field">Generate with<select value={practice.provider} disabled={locked} onChange={event => practice.setProvider(event.target.value)}><option value="ollama">Ollama</option><option value="openai">OpenAI</option></select></label></div>
          <div className="practice-context"><strong>{sources.length} selected {sources.length === 1 ? 'passage' : 'passages'}</strong>
            {sources.length ? <ul>{sources.map(source => <li key={source.id}>{source.path}{source.truncated ? ' (limited preview)' : ''}</li>)}</ul> : <p>Topic only. To use notes, close this view and use Add note or Add selection on a card.</p>}
            <label className="practice-chat-option"><input type="checkbox" checked={practice.includeChat} disabled={locked} onChange={event => practice.setIncludeChat(event.target.checked)} /> Include current chat ({chat.session.messages.length} messages)</label>
          </div>
          <button className="practice-primary practice-generate" type="submit" disabled={locked || chat.busy || !practice.topic.trim() || !practice.isLocal || stopped || !available}>{practice.busy ? 'Creating your practice…' : 'Generate practice'}</button>
        </form>
        {!practice.isLocal ? <p className="practice-muted">Use the Finder launcher to generate with AI. You can try the example here.</p> : <><p className="practice-muted">{chat.connecting ? 'Checking AI connection…' : !available ? 'Connect your AI provider to generate practice.' : practice.provider === 'openai' ? 'Sends selected passages, the included chat, and your topic to OpenAI.' : 'Uses your local Ollama model.'}</p><button disabled={locked || chat.connecting} onClick={chat.connect}>Reconnect AI</button></>}
        <div className="practice-example"><span>JUST EXPLORING?</span><button disabled={locked} onClick={practice.example}>Try fractions example</button><p className="practice-muted">Six ready-made problems. No AI connection needed.</p></div>
        <p className="practice-muted">This prototype supports +, −, ×, and ÷ with integers and fractions. Every answer is calculated exactly.</p>
      </aside>
      <section className="practice-work" aria-label="Worksheet" ref={work}>
        {practice.error && <p className="practice-error" role="alert">{practice.error}</p>}
        {practice.notice && <p className="practice-notice" role="status">{practice.notice}</p>}
        {practice.busy && <div className="practice-progress" role="status"><span className="practice-spinner" />Choosing problems and calculating the answer key…</div>}
        {sheet ? <><header className="practice-sheet-heading"><div><p>{sheet.provider === 'example' ? 'EXAMPLE WORKSHEET' : 'YOUR WORKSHEET'} · {practice.dirty ? 'UNSAVED CHANGES' : 'SAVED'}</p><h2>{sheet.title}</h2><span>{correct} of {sheet.problems.length} correct</span></div>
          <div className="practice-sheet-actions"><button className="practice-edit-settings" onClick={() => settings.current?.scrollIntoView({ block: 'start' })}>Edit practice</button><button className="practice-primary" onClick={practice.save} disabled={locked || stopped || !folder}>{practice.saving ? 'Saving…' : 'Save worksheet'}</button></div></header>
          <p className="practice-instructions">Work it out on paper, then check your answer here. Give fractions in lowest terms; whole numbers, mixed numbers, and exact decimals work too.</p>
          {sheet.sources.length > 0 && <p className="practice-muted">Created using: {[...new Set(sheet.sources.map(source => source.path))].join(', ')}</p>}
          <div className="practice-problems">{sheet.problems.map(p => <Problem key={`${sheet.id}-${p.id}`} problem={p} attempt={sheet.attempts[p.id]} disabled={locked} onChange={change => practice.changeAttempt(p.id, change)} />)}</div>
          <footer className="practice-save-info">{folder ? `Save worksheet writes a Markdown file, including your attempts, to ${folder}. Use Continue practice on its card to resume. Each save creates a new version.` : 'Open a folder in the feed to save this worksheet and your progress.'}</footer>
        </> : <div className="practice-empty"><span aria-hidden="true">½</span><h2>Understand it. Try it. Come back to it.</h2><p>Create a small set of problems around what you are learning, or try the fractions example to see how it feels.</p><div>Choose a topic → Solve a few problems → Save your progress</div></div>}
      </section>
    </div>
  </dialog>;
}
