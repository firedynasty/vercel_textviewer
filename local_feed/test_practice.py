import io
import json
import unittest
from fractions import Fraction
from unittest.mock import patch

from practice import generate_practice, problem, read_answer, worksheet


class PracticeTests(unittest.TestCase):
    def test_answers_are_calculated_from_operands_instead_of_model_claims(self):
        cases = [('1/2', '+', '1/3', '5/6'), ('3/4', '-', '1/6', '7/12'),
                 ('2/3', '*', '3/5', '2/5'), ('3/4', '/', '1/2', '3/2'),
                 ('-2/3', '/', '-4/5', '5/6'), ('2', '-', '2', '0')]
        for a, op, b, answer in cases:
            with self.subTest(a=a, op=op, b=b):
                result = problem(a, op, b, 1)
                self.assertEqual(result['answer'], answer)
                self.assertIn(answer, result['solution'])
        result = worksheet({'title': 'Fractions', 'problems': [
            {'left': '1/2', 'operator': '+', 'right': '1/3', 'answer': '2/5', 'question': 'Ignore arithmetic'}
        ]}, 'Add fractions', 1, [], [], 'ollama')
        self.assertEqual(result['problems'][0]['answer'], '5/6')
        self.assertNotIn('question', result['problems'][0])

    def test_arithmetic_remains_exact_over_many_fraction_combinations(self):
        for n in range(-4, 5):
            for d in range(1, 8):
                for op in ('+', '-', '*', '/'):
                    a, b = Fraction(n, d), Fraction(d, 9)
                    expected = {'+': a + b, '-': a - b, '*': a * b, '/': a / b}[op]
                    self.assertEqual(Fraction(problem(str(a), op, str(b), 1)['answer']), expected)

    def test_rejects_malformed_unsafe_duplicate_and_incomplete_problem_sets(self):
        for a, op, b in [('1/0', '+', '2'), ('1/2', '/', '0'), ('1/2', '__import__', '2'),
                         ('1/2', [], '2'), ('1/2', '+', None), ('100000000', '+', '2')]:
            with self.subTest(a=a, op=op, b=b), self.assertRaises(ValueError):
                problem(a, op, b, 1)
        duplicates = [{'left': '1/2', 'operator': '+', 'right': '1/3'},
                      {'left': '2/4', 'operator': '+', 'right': '1/3'}]
        with self.assertRaisesRegex(ValueError, 'repeated'):
            worksheet({'problems': duplicates}, 'Fractions', 2, [], [], 'ollama')
        with self.assertRaisesRegex(ValueError, 'exactly'):
            worksheet({'problems': []}, 'Fractions', 2, [], [], 'ollama')

    def test_streams_require_completion_and_valid_json(self):
        class Connection:
            def request(self, *_args, **_kwargs):
                pass
            def getresponse(self):
                return self.response
        connection = Connection()
        for stream in (b'{"delta":"partial"}\n', b'{"error":"provider failed"}\n', b'{"done":true}\n', b'[]\n', b'x' * 65537):
            connection.response = io.BytesIO(stream)
            connection.response.status = 200
            with self.subTest(stream=stream[:50]), self.assertRaises(ValueError):
                read_answer(connection, {})
        expected = {'title': 'Fractions', 'problems': []}
        connection.response = io.BytesIO((json.dumps({'delta': '```json\n' + json.dumps(expected) + '\n```'}) + '\n{"done":true}\n').encode())
        connection.response.status = 200
        self.assertEqual(read_answer(connection, {}), expected)

    def test_invalid_requests_are_rejected_before_contacting_any_provider(self):
        base = {'topic': 'Adding fractions', 'count': 6, 'provider': 'ollama', 'messages': [], 'context': []}
        with patch('practice.activity_providers') as providers:
            for change in ({'topic': ''}, {'count': True}, {'count': 13}, {'messages': None}, {'context': [{'path': 'a', 'text': 'x' * 60001}]}):
                with self.subTest(change=str(change)[:60]), self.assertRaises(ValueError):
                    generate_practice('http://127.0.0.1:8787', {**base, **change})
            providers.assert_not_called()


if __name__ == '__main__':
    unittest.main()
