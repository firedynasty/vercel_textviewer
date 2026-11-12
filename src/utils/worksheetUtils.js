/* global BigInt */
export const WORKSHEET_MARKER = '<!-- textviewer-practice:v1\n';
const signs = { '+': '+', '-': '−', '*': '×', '/': '÷' };
const zero = BigInt(0), one = BigInt(1);
const absolute = n => n < zero ? -n : n;
function gcd(a, b) { return b === zero ? absolute(a) : gcd(b, a % b); }
function fraction(n, d = one) {
  if (d === zero) throw new Error('A denominator cannot be zero.');
  if (d < zero) { n = -n; d = -d; }
  const divisor = gcd(n, d);
  return { n: n / divisor, d: d / divisor };
}
function format({ n, d }) { return d === one ? String(n) : `${n}/${d}`; }

export function parseNumber(input) {
  const text = String(input).trim();
  let match = text.match(/^(-?\d{1,12})\s+(\d{1,12})\s*\/\s*(\d{1,12})$/);
  if (match) {
    const whole = BigInt(match[1]), numerator = BigInt(match[2]), denominator = BigInt(match[3]);
    return fraction((whole * denominator) + (match[1].startsWith('-') ? -numerator : numerator), denominator);
  }
  match = text.match(/^(-?\d{1,12})\s*\/\s*(\d{1,12})$/);
  if (match) return fraction(BigInt(match[1]), BigInt(match[2]));
  if (/^-?\d{1,12}$/.test(text)) return fraction(BigInt(text));
  match = text.match(/^(-?)(\d{0,12})\.(\d{1,12})$/);
  if (match) {
    const numerator = BigInt((match[2] || '0') + match[3]);
    return fraction(match[1] ? -numerator : numerator, BigInt(10) ** BigInt(match[3].length));
  }
  throw new Error('Enter an exact fraction, whole number, mixed number, or decimal, such as 5/6.');
}

export function exactAnswer(p) {
  if (!p || !Object.prototype.hasOwnProperty.call(signs, p.operator)
    || ![p.left, p.right].every(value => typeof value === 'string' && /^-?\d{1,4}(?:\/\d{1,4})?$/.test(value))) {
    throw new Error('This worksheet contains unsupported arithmetic.');
  }
  const a = parseNumber(p.left), b = parseNumber(p.right);
  switch (p.operator) {
    case '+': return format(fraction(a.n * b.d + b.n * a.d, a.d * b.d));
    case '-': return format(fraction(a.n * b.d - b.n * a.d, a.d * b.d));
    case '*': return format(fraction(a.n * b.n, a.d * b.d));
    default: return format(fraction(a.n * b.d, a.d * b.n));
  }
}

export function problemText(p) {
  const display = value => value.startsWith('-') ? `(${value})` : value;
  return `${display(p.left)} ${signs[p.operator]} ${display(p.right)}`;
}

export function checkPracticeAnswer(problem, input) {
  try {
    const got = parseNumber(input), expected = parseNumber(exactAnswer(problem));
    if (got.n !== expected.n || got.d !== expected.d) return { ok: false, message: 'Not quite. Try again, or look at the worked steps.' };
    const parts = String(input).trim().match(/(-?\d+)\s*\/\s*(\d+)$/);
    if (parts && gcd(BigInt(parts[1]), BigInt(parts[2])) !== one) return { ok: false, message: 'Right value. Simplify the fraction to lowest terms.' };
    return { ok: true, message: 'Correct. Nicely done.' };
  } catch (error) { return { ok: false, message: error.message }; }
}

export function validateWorksheet(value) {
  if (!value || value.version !== 1 || typeof value.id !== 'string' || !value.id || typeof value.title !== 'string'
    || typeof value.request !== 'string' || !Array.isArray(value.problems) || !value.problems.length || value.problems.length > 12
    || !Array.isArray(value.sources) || value.sources.some(source => !source || typeof source.path !== 'string' || typeof source.text !== 'string')
    || !Array.isArray(value.conversation) || value.conversation.some(message => !message || !['user', 'assistant'].includes(message.role) || typeof message.content !== 'string')) {
    throw new Error('This file does not contain a supported practice worksheet.');
  }
  const problems = value.problems.map((p, index) => {
    if (!p || p.id !== index + 1 || typeof p.solution !== 'string' || p.answer !== exactAnswer(p)) {
      throw new Error('This worksheet has an invalid answer key.');
    }
    return { id: p.id, left: p.left, right: p.right, operator: p.operator, answer: p.answer, solution: p.solution };
  });
  const attempts = {};
  for (const p of problems) {
    const attempt = value.attempts?.[p.id];
    if (attempt && typeof attempt.value === 'string' && attempt.value.length <= 80) {
      attempts[p.id] = { value: attempt.value, checked: attempt.checked === true, revealed: attempt.revealed === true };
    }
  }
  return { ...value, problems, attempts };
}

export function parseWorksheet(text) {
  if (!text.startsWith(WORKSHEET_MARKER)) throw new Error('Choose a saved practice worksheet.');
  const end = text.indexOf('\n-->', WORKSHEET_MARKER.length);
  if (end < 0) throw new Error('This worksheet is incomplete. Open the complete saved file.');
  try { return validateWorksheet(JSON.parse(text.slice(WORKSHEET_MARKER.length, end))); }
  catch (error) { throw new Error(`Could not open this worksheet. ${error.message}`); }
}

export function worksheetMarkdown(value) {
  const sheet = validateWorksheet(value);
  const metadata = JSON.stringify(sheet).replace(/</g, '\\u003c').replace(/>/g, '\\u003e');
  const problems = sheet.problems.map(p => {
    const attempt = sheet.attempts[p.id];
    const result = attempt?.checked ? checkPracticeAnswer(p, attempt.value).message : 'Not checked';
    return `${p.id}. ${problemText(p)} = ?${attempt ? `\n\n   My answer: ${attempt.value.replace(/[\r\n]/g, ' ')} (${result})` : ''}`;
  }).join('\n\n');
  const solutions = sheet.problems.map(p => `${p.id}. ${problemText(p)} = **${p.answer}**\n\n   ${p.solution}`).join('\n\n');
  return `${WORKSHEET_MARKER}${metadata}\n-->\n\n# ${sheet.title.replace(/[\r\n]/g, ' ')}\n\nPractice: ${sheet.request}\n\nGive fractions in lowest terms.\n\n## Problems\n\n${problems}\n\n## Answer key\n\n${solutions}\n\n## Sources\n\n${sheet.sources.map(source => `- ${source.path}`).join('\n') || 'Topic only; no source passages.'}\n\nGenerated with: ${sheet.provider || 'unknown'}. Answers calculated with exact fraction arithmetic.\n`;
}

export function exampleWorksheet() {
  const pairs = [['1/2', '+', '1/3'], ['3/4', '-', '1/6'], ['2/3', '*', '3/5'], ['3/4', '/', '1/2'], ['5/6', '+', '7/12'], ['7/8', '-', '2/3']];
  const solutions = [
    'Use denominator 6: 3/6 + 2/6 = 5/6.',
    'Use denominator 12: 9/12 − 2/12 = 7/12.',
    'Multiply: (2 × 3)/(3 × 5) = 6/15. Simplify to 2/5.',
    'Multiply by the reciprocal: 3/4 × 2/1 = 6/4. Simplify to 3/2.',
    'Use denominator 12: 10/12 + 7/12 = 17/12.',
    'Use denominator 24: 21/24 − 16/24 = 5/24.',
  ];
  return {
    version: 1, id: `example-${Date.now()}`, title: 'A little fraction practice', request: 'A mixture of fraction arithmetic',
    createdAt: new Date().toISOString(), provider: 'example', sources: [], conversation: [], attempts: {},
    problems: pairs.map(([left, operator, right], index) => {
      const p = { id: index + 1, left, operator, right, solution: solutions[index] };
      return { ...p, answer: exactAnswer(p) };
    }),
  };
}
