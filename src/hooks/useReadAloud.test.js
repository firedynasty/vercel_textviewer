import { bestVoice, speechPieces } from './useReadAloud';

test('speech pieces follow sentences and cut very long ones at commas, then spaces', () => {
  expect(speechPieces('  Grace and peace.  Be still!\nAnd know ')).toEqual(['Grace and peace.', 'Be still!', 'And know']);
  const long = `${'word '.repeat(30)}then, ${'more '.repeat(40)}end.`;
  const pieces = speechPieces(long);
  expect(pieces.length).toBeGreaterThan(1);
  expect(pieces.every(piece => piece.length <= 220)).toBe(true);
  expect(pieces.join(' ').replace(/\s+/g, ' ')).toBe(long.replace(/\s+/g, ' ').trim());
  expect(speechPieces('   ')).toEqual([]);
});

test('the best voice is an Enhanced or Premium English one, then Google English, then any English', () => {
  const voices = [{ name: 'Thomas', lang: 'fr-FR' }, { name: 'Alex', lang: 'en-US' }, { name: 'Google US English', lang: 'en-US' }, { name: 'Ava (Premium)', lang: 'en-US' }];
  expect(bestVoice(voices).name).toBe('Ava (Premium)');
  expect(bestVoice(voices.slice(0, 3)).name).toBe('Google US English');
  expect(bestVoice(voices.slice(0, 2)).name).toBe('Alex');
  expect(bestVoice([])).toBe(null);
});
