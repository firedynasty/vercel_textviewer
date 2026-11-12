import React, { useEffect, useRef } from 'react';
import FeedMarkdown from './FeedMarkdown';
import { CONTEXT_LIMIT, contextSize, noteFilename } from '../utils/chatUtils';
import './FeedChat.css';

export default function FeedChat({ chat, folder, stopped, onPractice }) {
  const { session, busy, saving } = chat;
  const locked = busy || saving;
  const history = useRef(null);
  useEffect(() => { history.current?.scrollTo({ top: history.current.scrollHeight }); }, [session.messages.length, chat.answer]);
  if (!chat.open) return null;
  const size = contextSize(session.context);
  const update = (field, value) => chat.change(previous => ({ ...previous, [field]: value }));
  const sourceChange = (id, fields) => chat.change(previous => ({ ...previous, context: previous.context.map(item => item.id === id ? { ...item, ...fields } : item) }));
  const unavailable = session.provider === 'openai' ? !chat.providers?.openai?.configured : !(chat.providers?.ollama?.ok && chat.providers?.ollama?.haveModel);
  return <aside className="feed-chat" aria-label="Context and chat">
    <header className="feed-chat-header"><div><h2>Context & chat</h2><small>{chat.dirty ? 'Unsaved changes' : 'Collect notes and build on them'}</small></div><button onClick={() => chat.setOpen(false)} aria-label="Close context and chat">×</button></header>
    <div className="feed-chat-body">
      <label className="feed-chat-label">Topic<input value={session.title} onChange={event => update('title', event.target.value)} disabled={locked} /></label>
      <details className="feed-context" open>
        <summary>Selected context · {session.context.filter(item => item.enabled !== false).length} {session.context.filter(item => item.enabled !== false).length === 1 ? 'source' : 'sources'} <span className={size > CONTEXT_LIMIT ? 'feed-chat-over' : ''}>{size.toLocaleString()} / {CONTEXT_LIMIT.toLocaleString()} chars</span></summary>
        {!session.context.length && <p className="feed-chat-hint">Use Add note on a card, or highlight a passage and choose Add selection. Only checked passages go to the AI.</p>}
        {session.context.map(item => <div key={item.id} className={`feed-context-source${item.enabled === false ? ' is-excluded' : ''}`}>
          <label><input type="checkbox" checked={item.enabled !== false} disabled={locked} onChange={event => sourceChange(item.id, { enabled: event.target.checked })} /><span title={item.path}>{item.path}</span></label>
          <button aria-label={`Remove ${item.path} from context`} disabled={locked} onClick={() => chat.change(previous => ({ ...previous, context: previous.context.filter(source => source.id !== item.id) }))}>Remove</button>
          <textarea aria-label={`Context from ${item.path}`} value={item.text} rows={3} disabled={locked} onChange={event => sourceChange(item.id, { text: event.target.value })} />
          {item.truncated && <small>This source was collected from a limited preview.</small>}
        </div>)}
        <div className="feed-chat-actions"><button disabled={!size} onClick={chat.copy}>Copy context</button><button disabled={locked || !session.context.length} onClick={() => update('context', [])}>Clear context</button></div>
      </details>
      {onPractice && <button className="feed-practice-launch" disabled={locked} onClick={onPractice}>Practise these notes ↗</button>}
      <div className="feed-chat-provider"><label>Chat with <select value={session.provider} disabled={locked} onChange={event => update('provider', event.target.value)}><option value="ollama">Ollama</option><option value="openai">OpenAI</option></select></label><button disabled={chat.connecting || !chat.isLocal} onClick={chat.connect}>{chat.connecting ? 'Connecting…' : 'Reconnect'}</button></div>
      <p className="feed-chat-hint">{!chat.isLocal ? 'Use the Finder launcher for chat. Collection and saving work here.' : session.provider === 'openai' ? `Selected context and conversation are sent to OpenAI${chat.providers?.openai?.model ? ` (${chat.providers.openai.model})` : ''}.` : `Uses your local Ollama model${chat.providers?.ollama?.model ? ` (${chat.providers.ollama.model})` : ''}.`}{chat.providers && unavailable ? ' This provider is currently unavailable.' : ''}</p>
      {session.provider === 'openai' && <label className="feed-chat-web"><input type="checkbox" checked={session.web} disabled={locked} onChange={event => update('web', event.target.checked)} /> Web search (additional API usage)</label>}
      <div className="feed-chat-history" ref={history} role="log" aria-label="Conversation">
        {!session.messages.length && !busy && <p className="feed-chat-hint">Ask how your notes connect, explore an idea, or turn them into a plan.</p>}
        {session.messages.map((message, index) => <article key={index} className={`feed-chat-message feed-chat-message--${message.role}`}><strong>{message.role === 'user' ? 'You' : `Assistant${message.provider ? ` · ${message.provider === 'openai' ? 'OpenAI' : 'Ollama'}` : ''}`}</strong><FeedMarkdown text={message.content} />{message.role === 'user' && <small>{message.sources?.length || 0} sources included</small>}{message.role === 'assistant' && <button disabled={locked || stopped} onClick={() => chat.save(message)}>Save answer as note</button>}</article>)}
        {busy && <><article className="feed-chat-message feed-chat-message--user"><strong>You</strong><p>{chat.question}</p></article><article className="feed-chat-message" aria-live="polite"><strong>Assistant</strong><FeedMarkdown text={chat.answer || 'Thinking…'} /></article></>}
      </div>
      {chat.error && <p className="feed-chat-error" role="alert">{chat.error}</p>}
      {chat.notice && <p className="feed-chat-notice" role="status">{chat.notice}</p>}
      <form className="feed-chat-composer" onSubmit={chat.ask}><label className="feed-chat-label">Question<textarea value={chat.question} disabled={locked} onChange={event => chat.setQuestion(event.target.value)} rows={3} placeholder="Ask about the selected notes…" onKeyDown={event => { if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) { event.preventDefault(); chat.ask(); } }} /></label>{busy ? <button type="button" onClick={chat.stop}>Stop answer</button> : <button type="submit" className="feed-primary" disabled={locked || stopped || !chat.isLocal || !chat.question.trim() || size > CONTEXT_LIMIT || !chat.providers || unavailable || chat.providers.contextChat !== 1}>Ask</button>}</form>
      <details className="feed-chat-save" open><summary>Save in this folder</summary><p className="feed-chat-hint" title={folder}>{folder || 'Choose a folder first'}</p><label className="feed-chat-label">My notes<textarea value={session.notes} disabled={locked} onChange={event => update('notes', event.target.value)} rows={2} placeholder="Add your own thoughts…" /></label><label className="feed-chat-label">Filename<input value={chat.filename} placeholder={noteFilename(session.title)} disabled={locked} onChange={event => chat.setFilename(event.target.value)} /></label><div className="feed-chat-actions"><button className="feed-primary" disabled={locked || stopped || !folder || (!session.messages.length && !session.context.length && !session.notes.trim())} onClick={() => chat.save()}>{saving ? 'Saving…' : 'Save chat'}</button><button disabled={locked} onClick={chat.reset}>New chat</button></div><p className="feed-chat-hint">Creates a Markdown file. Existing names receive a number. Reopen it with Continue chat.</p></details>
    </div>
  </aside>;
}
