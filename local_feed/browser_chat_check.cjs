// Integration check with real Chrome, Python, and Activity Island; AI providers are mocked.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const assert = require('node:assert/strict');
const { spawn, execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'folder-chat-browser-'));
const folder = path.join(temporary, 'Research notes');
fs.mkdirSync(folder);
fs.writeFileSync(path.join(folder, 'first.txt'), 'Selected research passage. Connect this idea to practice.');
fs.writeFileSync(path.join(folder, 'exclude.txt'), 'This passage should stay excluded.');
let fake, hooks, chrome, ws, launcher;
let nextId = 0;
const callbacks = new Map(), exceptions = [], requests = [];
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
async function until(check, label, milliseconds = 20000) {
  const start = Date.now();
  while (Date.now() - start < milliseconds) { if (await check()) return; await delay(100); }
  throw new Error('Timed out: ' + label);
}
function send(method, params = {}) {
  const id = ++nextId;
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => { callbacks.delete(id); reject(new Error('CDP timeout: ' + method)); }, 15000);
    callbacks.set(id, result => { clearTimeout(timer); result.error ? reject(new Error(JSON.stringify(result.error))) : resolve(result.result); });
    ws.send(JSON.stringify({ id, method, params }));
  });
}
async function evaluate(expression) {
  const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
  if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
  return result.result.value;
}
async function input(selector, value) {
  await evaluate(`(() => { const element = document.querySelector(${JSON.stringify(selector)}); Object.getOwnPropertyDescriptor(element.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype, 'value').set.call(element, ${JSON.stringify(value)}); element.dispatchEvent(new Event('input', {bubbles:true})); })()`);
}
async function click(text) {
  await evaluate(`(() => { const button = [...document.querySelectorAll('button')].find(button => button.textContent === ${JSON.stringify(text)}); if (!button || button.disabled) throw new Error('Unavailable button: ' + ${JSON.stringify(text)}); button.click(); })()`);
}
async function screenshot(name) {
  const result = await send('Page.captureScreenshot', { format: 'png' });
  fs.writeFileSync(path.join(root, 'dist', name), Buffer.from(result.data, 'base64'));
}

(async () => {
  try {
    fake = http.createServer((req, res) => {
      if (req.url === '/api/tags') { res.end(JSON.stringify({ models: [{ name: 'test-model' }] })); return; }
      let body = ''; req.on('data', chunk => body += chunk);
      req.on('end', () => {
        requests.push({ url: req.url, body: JSON.parse(body) });
        if (req.url === '/api/chat') res.end('{"message":{"content":"Useful answer from the selected passage."},"done":true}\n');
        else res.end('data: {"choices":[{"delta":{"content":"OpenAI mock answer."}}]}\n\ndata: [DONE]\n\n');
      });
    });
    await new Promise(resolve => fake.listen(0, '127.0.0.1', resolve));
    const upstream = `http://127.0.0.1:${fake.address().port}`;
    const reservation = http.createServer();
    await new Promise(resolve => reservation.listen(0, '127.0.0.1', resolve));
    const port = reservation.address().port;
    await new Promise(resolve => reservation.close(resolve));
    hooks = spawn(process.execPath, [path.join(root, 'dist/activity-island-update/server.js')], {
      env: { ...process.env, PORT: String(port), OLLAMA_URL: upstream, OLLAMA_MODEL: 'test-model', OPENAI_API_KEY: 'browser-test-only', OPENAI_BASE_URL: upstream + '/v1', ACTIVITY_LOG: path.join(temporary, 'missing.jsonl'), PROJECTS_DIR: path.join(temporary, 'missing-projects'), ACTIVITY_QUIET: '1' }, stdio: 'ignore',
    });
    await until(async () => { try { return (await fetch(`http://127.0.0.1:${port}/providers`)).ok; } catch { return false; } }, 'staged Activity Island');
    launcher = [path.join(root, 'local_feed/server.py'), folder, '--state-dir', path.join(temporary, 'state'), '--chat-url', `http://127.0.0.1:${port}`, '--no-open'];
    const url = execFileSync('python3', launcher, { encoding: 'utf8' }).trim();
    const profile = path.join(temporary, 'chrome');
    chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--disable-background-networking', 'about:blank'], { stdio: 'ignore' });
    await until(() => fs.existsSync(path.join(profile, 'DevToolsActivePort')), 'Chrome');
    const debugPort = fs.readFileSync(path.join(profile, 'DevToolsActivePort'), 'utf8').split('\n')[0];
    const targets = await (await fetch(`http://127.0.0.1:${debugPort}/json/list`)).json();
    ws = new WebSocket(targets.find(target => target.type === 'page').webSocketDebuggerUrl);
    ws.onmessage = event => { const message = JSON.parse(event.data); if (message.id) { callbacks.get(message.id)?.(message); callbacks.delete(message.id); } else if (message.method === 'Runtime.exceptionThrown') exceptions.push(message.params); };
    await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
    await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
    await send('Network.setBlockedURLs', { urls: ['https://cdn.rawgit.com/*', 'https://cdn.jsdelivr.net/*'] });
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1100, deviceScaleFactor: 1, mobile: false });
    await send('Page.navigate', { url });
    await until(() => evaluate('document.querySelectorAll(".feed-card").length === 2'), 'folder contents');
    await evaluate(`(() => { const note = document.querySelector('[data-feed-path="first.txt"] .feed-note'); const text = note.querySelector('p').firstChild; const range = document.createRange(); range.setStart(text, 0); range.setEnd(text, 25); window.getSelection().addRange(range); note.dispatchEvent(new MouseEvent('mouseup', {bubbles:true})); })()`);
    await click('Add selection');
    await evaluate(`document.querySelector('[data-feed-path="exclude.txt"] .feed-card-actions button:last-child').click()`);
    await evaluate(`document.querySelectorAll('.feed-context-source input')[1].click()`);
    await input('.feed-chat-label input', 'Research connections');
    await input('.feed-chat-composer textarea', 'Explain this selected passage.');
    await until(() => evaluate(`![...document.querySelectorAll('button')].find(b => b.textContent === 'Ask')?.disabled`), 'ready to ask');
    await click('Ask');
    await until(() => evaluate('document.querySelectorAll(".feed-chat-message").length === 2 && document.querySelector(".feed-chat-history").textContent.includes("Useful answer")'), 'streamed answer');
    assert.equal(requests.length, 1);
    const context = JSON.parse(requests[0].body.messages[1].content.split('\n')[1]);
    assert.deepEqual(context, [{ path: 'first.txt', text: 'Selected research passage' }]);
    assert(!JSON.stringify(requests[0]).includes('stay excluded'));
    await click('Save chat');
    await until(() => fs.existsSync(path.join(folder, 'Research connections-chat.md')), 'saved chat');
    const saved = fs.readFileSync(path.join(folder, 'Research connections-chat.md'), 'utf8');
    assert(saved.includes('Useful answer'));
    await until(() => evaluate('document.querySelector(".feed-chat-notice")?.textContent.includes("Saved")'), 'save complete');
    await click('Save chat');
    await until(() => fs.existsSync(path.join(folder, 'Research connections-chat-2.md')), 'collision-safe save');
    assert.equal(fs.readFileSync(path.join(folder, 'Research connections-chat.md'), 'utf8'), saved);
    await until(() => evaluate('!document.querySelector(".feed-chat-save button").disabled'), 'save unlocked');
    await click('Save answer as note');
    await until(() => fs.existsSync(path.join(folder, 'Research connections-answer.md')), 'answer note');
    await screenshot('chat-desktop.png');
    await send('Page.reload');
    await until(() => evaluate('document.querySelectorAll(".feed-card").length === 5'), 'saved notes in feed after reload');
    await click('Continue chat');
    await until(() => evaluate('document.querySelectorAll(".feed-chat-message").length === 2'), 'restored conversation');
    assert.equal(await evaluate('document.querySelectorAll(".feed-context-source").length'), 2);
    assert.equal(await evaluate('document.querySelectorAll(".feed-context-source input")[1].checked'), false);
    await input('.feed-chat-composer textarea', 'Continue from the earlier answer.');
    await until(() => evaluate(`![...document.querySelectorAll('button')].find(b => b.textContent === 'Ask')?.disabled`), 'restored provider');
    await click('Ask');
    await until(() => evaluate('document.querySelectorAll(".feed-chat-message").length === 4 && document.querySelector(".feed-chat-composer textarea").value === ""'), 'continued chat');
    assert.equal(requests[1].body.messages.length, 5); // system, context, earlier pair, new question
    await evaluate(`(() => { const select = document.querySelector('.feed-chat-provider select'); select.value = 'openai'; select.dispatchEvent(new Event('change', {bubbles:true})); })()`);
    await input('.feed-chat-composer textarea', 'Explain using OpenAI.');
    await click('Ask');
    await until(() => evaluate('document.querySelector(".feed-chat-history").textContent.includes("OpenAI mock answer")'), 'OpenAI route');
    assert.equal(requests.at(-1).url, '/v1/chat/completions');
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await screenshot('chat-mobile.png');
    assert.equal(await evaluate('document.documentElement.scrollWidth <= 390'), true);
    assert.deepEqual(exceptions, []);
    process.stdout.write('PASS: passage selection, excluded sources, Ollama/OpenAI routing, save, filename collisions, answer notes, reload, continuation, and mobile layout.\n');
    process.stdout.write(`Screenshots: ${path.join(root, 'dist/chat-desktop.png')} and chat-mobile.png\n`);
  } finally {
    ws?.close(); chrome?.kill(); hooks?.kill(); fake?.closeAllConnections(); fake?.close();
    if (launcher) execFileSync('python3', [...launcher, '--stop'], { stdio: 'ignore' });
  }
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
