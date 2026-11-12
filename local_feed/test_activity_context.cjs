const { test } = require('node:test');
const assert = require('node:assert/strict');
const { EventEmitter } = require('node:events');
const { contextMessages, handleContextChat } = require('./activity_context.cjs');

const payload = () => ({ provider: 'ollama', context: [{ path: 'source.txt', text: 'A selected passage ' + 'x'.repeat(5000) }], messages: [{ role: 'user', content: 'Explain this' }] });
const config = { ollama: 'http://127.0.0.1:11434', model: 'local-test', openaiKey: 'test-only-key', openaiModel: 'gpt-4.1', openaiUrl: 'https://api.openai.com/v1', searchTool: 'web_search_preview' };
function response() {
  const res = new EventEmitter(); res.data = ''; res.destroyed = false; res.headersSent = false;
  res.writeHead = status => { res.status = status; res.headersSent = true; };
  res.write = text => { res.data += text; };
  res.end = text => { if (text) res.data += text; };
  return res;
}

test('explicit context preserves long sources and excludes project/activity prompts', () => {
  const messages = contextMessages(payload());
  assert.equal(JSON.parse(messages[1].content.split('\n')[1])[0].text.length, 5019);
  assert.equal(messages.at(-1).content, 'Explain this');
  assert(!messages[0].content.includes('Scout'));
  const tooLong = payload(); tooLong.context[0].text = 'x'.repeat(60001);
  assert.throws(() => contextMessages(tooLong), /60,000/);
});

test('Ollama and OpenAI streams report completion and preserve selected context', async () => {
  const original = global.fetch;
  try {
    for (const provider of ['ollama', 'openai']) {
      const q = { ...payload(), provider };
      let request;
      global.fetch = async (url, options) => {
        request = { url, body: JSON.parse(options.body), headers: options.headers };
        return new Response(provider === 'ollama'
          ? '{"message":{"content":"你好"}}\n{"message":{"content":" answer"},"done":true}\n'
          : 'data: {"choices":[{"delta":{"content":"你好 answer"}}]}\n\ndata: [DONE]\n\n');
      };
      const res = response(); await handleContextChat(q, res, config);
      const events = res.data.trim().split('\n').map(JSON.parse);
      assert.equal(events.filter(e => e.delta).map(e => e.delta).join(''), '你好 answer');
      assert.deepEqual(events.at(-1), { done: true });
      assert(request.body.messages[1].content.includes(q.context[0].text));
      if (provider === 'ollama') assert.equal(request.headers.Authorization, undefined);
    }
  } finally { global.fetch = original; }
});

test('web answers include sources; provider failures and missing completion are explicit errors', async () => {
  const original = global.fetch;
  try {
    global.fetch = async () => new Response('data: {"type":"response.output_text.delta","delta":"Answer"}\n\ndata: {"type":"response.completed","response":{"output":[{"content":[{"annotations":[{"type":"url_citation","url":"https://example.com","title":"Source"}]}]}]}}\n\n');
    const web = response(); await handleContextChat({ ...payload(), provider: 'openai', web: true }, web, config);
    assert(web.data.includes('https://example.com'));
    assert(web.data.includes('"done":true'));
    global.fetch = async () => new Response('{"message":{"content":"Partial"}}\n');
    const partial = response(); await handleContextChat(payload(), partial, config);
    assert(partial.data.includes('"error"'));
    assert(!partial.data.includes('"done":true'));
    global.fetch = async () => new Response('secret upstream detail', { status: 401 });
    const failed = response(); await handleContextChat(payload(), failed, config);
    assert.equal(failed.status, 502);
    assert(!failed.data.includes('secret'));
  } finally { global.fetch = original; }
});
