// Real Chrome + Python integration. Activity Island is mocked; no paid requests.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const assert = require('node:assert/strict');
const { spawn, execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'folder-practice-browser-'));
const folder = path.join(temporary, 'Math notes');
fs.mkdirSync(folder);
fs.writeFileSync(path.join(folder, 'fractions.txt'), 'Studying fractions on Brilliant. I keep adding the denominators.');
fs.writeFileSync(path.join(folder, 'private.txt'), 'This passage must not be sent.');
let fake, chrome, ws, launcher;
const callbacks = new Map(), exceptions = [], requests = [];
let nextId = 0;
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
  await evaluate(`(() => { const element = document.querySelector(${JSON.stringify(selector)}); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(element, ${JSON.stringify(value)}); element.dispatchEvent(new Event('input', {bubbles:true})); })()`);
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
      if (req.url === '/providers') { res.end(JSON.stringify({ contextChat: 1, ollama: { ok: true, haveModel: true }, openai: { configured: true } })); return; }
      let body = ''; req.on('data', chunk => body += chunk);
      req.on('end', () => {
        const request = JSON.parse(body); requests.push(request);
        const problems = [['1/2', '+', '1/3'], ['3/4', '-', '1/6'], ['2/3', '*', '3/5'], ['3/4', '/', '1/2'], ['5/6', '+', '7/12'], ['7/8', '-', '2/3']]
          .map(([left, operator, right]) => ({ left, operator, right, answer: 'wrong model answer' }));
        const draft = { title: 'Fractions from my Brilliant notes', problems };
        res.end(JSON.stringify({ delta: JSON.stringify(draft) }) + '\n{"done":true}\n');
      });
    });
    await new Promise(resolve => fake.listen(0, '127.0.0.1', resolve));
    launcher = [path.join(root, 'local_feed/server.py'), folder, '--state-dir', path.join(temporary, 'state'), '--chat-url', `http://127.0.0.1:${fake.address().port}`, '--no-open'];
    const url = execFileSync('python3', launcher, { encoding: 'utf8' }).trim();
    const profile = path.join(temporary, 'chrome');
    chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--disable-background-networking', 'about:blank'], { stdio: 'ignore' });
    await until(() => fs.existsSync(path.join(profile, 'DevToolsActivePort')), 'Chrome');
    const port = fs.readFileSync(path.join(profile, 'DevToolsActivePort'), 'utf8').split('\n')[0];
    const targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
    ws = new WebSocket(targets.find(target => target.type === 'page').webSocketDebuggerUrl);
    ws.onmessage = event => {
      const message = JSON.parse(event.data);
      if (message.id) { callbacks.get(message.id)?.(message); callbacks.delete(message.id); }
      else if (message.method === 'Runtime.exceptionThrown') exceptions.push(message.params);
      else if (message.method === 'Page.javascriptDialogOpening') send('Page.handleJavaScriptDialog', { accept: true }).catch(() => {});
    };
    await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
    await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
    await send('Network.setBlockedURLs', { urls: ['https://cdn.rawgit.com/*', 'https://cdn.jsdelivr.net/*'] });
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1100, deviceScaleFactor: 1, mobile: false });
    await send('Page.navigate', { url });
    await until(() => evaluate('document.querySelectorAll(".feed-card").length === 2'), 'notes');
    await evaluate(`[...document.querySelectorAll('[data-feed-path="fractions.txt"] .feed-card-actions button')].find(button => button.textContent === 'Add note').click()`);
    await evaluate(`[...document.querySelectorAll('[data-feed-path="private.txt"] .feed-card-actions button')].find(button => button.textContent === 'Add note').click()`);
    await evaluate(`document.querySelectorAll('.feed-context-source input')[1].click()`);
    await click('Practise these notes ↗');
    await click('Try fractions example');
    assert.equal(requests.length, 0);
    await input('#practice-answer-1', '2/5');
    await click('Check answer');
    await until(() => evaluate('document.querySelector(".practice-feedback")?.textContent.includes("Not quite")'), 'wrong answer feedback');
    await input('#practice-answer-1', '10/12'); await click('Check answer');
    await until(() => evaluate('document.querySelector(".practice-feedback")?.textContent.includes("lowest terms")'), 'simplification feedback');
    await input('#practice-answer-1', '5/6'); await click('Check answer');
    await until(() => evaluate('document.querySelector(".practice-sheet-heading")?.textContent.includes("1 of 6 correct")'), 'correct answer');
    await until(() => evaluate(`![...document.querySelectorAll('button')].find(b => b.textContent === 'Generate practice')?.disabled`), 'AI available');
    await click('Generate practice');
    await until(() => evaluate('document.querySelector(".practice-sheet-heading")?.textContent.includes("Fractions from my Brilliant notes")'), 'generated worksheet');
    assert.equal(requests.length, 1);
    assert.deepEqual(requests[0].context, [{ path: 'fractions.txt', text: 'Studying fractions on Brilliant. I keep adding the denominators.' }]);
    assert(!JSON.stringify(requests).includes('must not be sent'));
    assert.deepEqual(fs.readdirSync(folder).sort(), ['fractions.txt', 'private.txt']);
    await input('#practice-answer-1', '5/6'); await click('Check answer'); await click('Show worked steps');
    assert((await evaluate('document.querySelector(".practice-solution").textContent')).includes('3/6 + 2/6'));
    await click('Save worksheet');
    const filename = 'Fractions from my Brilliant notes-worksheet.md';
    await until(() => fs.existsSync(path.join(folder, filename)), 'save');
    await until(() => evaluate('document.querySelector(".practice-notice")?.textContent.startsWith("Saved")'), 'save completed');
    const saved = fs.readFileSync(path.join(folder, filename), 'utf8');
    assert(saved.includes('"answer":"5/6"'));
    assert(saved.includes('"value":"5/6"'));
    assert(!saved.includes('wrong model answer'));
    await screenshot('practice-desktop.png');
    await send('Page.reload');
    await until(() => evaluate('!document.querySelector(".feed-practice") && [...document.querySelectorAll("button")].some(button => button.textContent === "Continue practice")'), 'saved card after reload');
    await click('Continue practice');
    await until(() => evaluate('document.querySelector("#practice-answer-1")?.value === "5/6"'), 'restored attempt');
    assert((await evaluate('document.querySelector(".practice-sheet-heading").textContent')).includes('1 of 6 correct'));
    await click('Save worksheet');
    await until(() => fs.existsSync(path.join(folder, 'Fractions from my Brilliant notes-worksheet-2.md')), 'new version');
    assert.equal(fs.readFileSync(path.join(folder, filename), 'utf8'), saved);
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await screenshot('practice-mobile.png');
    assert.equal(await evaluate('document.documentElement.scrollWidth <= 390 && document.querySelector(".feed-practice").scrollWidth <= 390'), true);
    assert.equal(await evaluate('document.querySelector("#practice-answer-1").getBoundingClientRect().top < 844'), true);
    assert.deepEqual(exceptions, []);
    process.stdout.write('PASS: example, exact answer checking, selected/excluded sources, generation, save, reload, progress, filename collisions, and mobile layout.\n');
    process.stdout.write(`Screenshots: ${path.join(root, 'dist/practice-desktop.png')} and practice-mobile.png\n`);
  } finally {
    ws?.close(); chrome?.kill(); fake?.closeAllConnections(); fake?.close();
    if (launcher) execFileSync('python3', [...launcher, '--stop'], { stdio: 'ignore' });
  }
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
