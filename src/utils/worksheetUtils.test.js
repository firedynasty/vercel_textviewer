import { checkPracticeAnswer, exactAnswer, exampleWorksheet, parseNumber, parseWorksheet, worksheetMarkdown } from './worksheetUtils';
import { visibleChatMarkdown } from './chatUtils';

test('compares fractions exactly and accepts mixed numbers and terminating decimals', () => {
  const sheet = exampleWorksheet();
  expect(checkPracticeAnswer(sheet.problems[0], '2/5').ok).toBe(false);
  expect(checkPracticeAnswer(sheet.problems[0], '10/12').message).toContain('lowest terms');
  expect(checkPracticeAnswer(sheet.problems[0], '5/6').ok).toBe(true);
  expect(checkPracticeAnswer(sheet.problems[3], '1 1/2').ok).toBe(true);
  expect(checkPracticeAnswer(sheet.problems[3], '1.5').ok).toBe(true);
  expect(checkPracticeAnswer(sheet.problems[3], '1 2/4').message).toContain('lowest terms');
  expect(checkPracticeAnswer(sheet.problems[0], '0.833333333333').ok).toBe(false);
  expect(checkPracticeAnswer(sheet.problems[0], '1/0').ok).toBe(false);
  expect(checkPracticeAnswer(sheet.problems[0], 'alert(1)').ok).toBe(false);
  expect(parseNumber('-1 1/2')).toEqual(parseNumber('-3/2'));
  expect(exactAnswer({ left: '-2/3', operator: '/', right: '-4/5' })).toBe('5/6');
});

test('saved readable worksheets preserve source snapshots and progress without exposing metadata in cards', () => {
  const sheet = exampleWorksheet();
  sheet.sources = [{ path: 'fractions.txt', text: 'Use equal units. <!-- -->' }];
  sheet.attempts[1] = { value: '5/6', checked: true, revealed: false };
  const text = worksheetMarkdown(sheet);
  expect(parseWorksheet(text)).toEqual(sheet);
  expect(visibleChatMarkdown(text)).toContain('My answer: 5/6');
  expect(visibleChatMarkdown(text)).not.toContain('textviewer-practice');
  expect(visibleChatMarkdown(text)).not.toContain('Use equal units.');
  expect(visibleChatMarkdown(text.slice(0, 100))).toContain('Continue practice');
});

test('reopening rejects edited answer keys and malformed worksheets', () => {
  const sheet = exampleWorksheet();
  sheet.problems[0].answer = '2/5';
  expect(() => worksheetMarkdown(sheet)).toThrow('answer key');
  expect(() => parseWorksheet('ordinary note')).toThrow('Choose');
  expect(() => exactAnswer({ left: '1/2', operator: 'eval', right: '2' })).toThrow('unsupported');
});
