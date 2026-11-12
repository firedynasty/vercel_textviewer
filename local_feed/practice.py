"""A bounded worksheet task: AI selects arithmetic, Python computes the key."""
from datetime import datetime, timezone
from fractions import Fraction
import json
from math import gcd
import re
import time
from uuid import uuid4

from chat import activity_connection, activity_providers, validate_chat

MARKER = '<!-- textviewer-practice:v1\n'
OPERATORS = {'+': '+', '-': '−', '*': '×', '/': '÷'}


def operand(value):
    if not isinstance(value, str) or not re.fullmatch(r'-?\d{1,4}(?:/\d{1,4})?', value):
        raise ValueError('Each operand must be a small integer or fraction, such as 2 or 3/4.')
    try:
        return Fraction(value)
    except (ValueError, ZeroDivisionError):
        raise ValueError('Fractions must have a nonzero denominator.') from None


def problem(left, operator, right, number):
    a, b = operand(left), operand(right)
    if not isinstance(operator, str) or operator not in OPERATORS or (operator == '/' and b == 0):
        raise ValueError('Use +, -, *, or /, without division by zero.')
    # No eval and no model-written answer key or question text.
    if operator in ('+', '-'):
        denominator = a.denominator * b.denominator // gcd(a.denominator, b.denominator)
        first = a.numerator * (denominator // a.denominator)
        second = b.numerator * (denominator // b.denominator)
        answer = a + b if operator == '+' else a - b
        steps = f'Use denominator {denominator}: {first}/{denominator} {OPERATORS[operator]} {second}/{denominator}. '
        steps += f'Combine the numerators, then simplify: {answer}.'
    elif operator == '*':
        answer = a * b
        steps = f'Multiply numerators and denominators: ({a.numerator} × {b.numerator})/({a.denominator} × {b.denominator}). Simplify: {answer}.'
    else:
        answer = a / b
        steps = f'Multiply by the reciprocal: ({a}) × ({1 / b}). Simplify: {answer}.'
    return {'id': number, 'left': left, 'operator': operator, 'right': right,
            'answer': str(answer), 'solution': steps}


def worksheet(data, topic, count, context, messages, provider):
    if not isinstance(data, dict) or not isinstance(data.get('problems'), list) or len(data['problems']) != count:
        raise ValueError(f'The AI must return exactly {count} problems. Try generating again.')
    problems = []
    seen = set()
    for item in data['problems']:
        if not isinstance(item, dict):
            raise ValueError('The AI returned an invalid problem. Try generating again.')
        p = problem(item.get('left'), item.get('operator'), item.get('right'), len(problems) + 1)
        key = (operand(p['left']), p['operator'], operand(p['right']))
        if key in seen:
            raise ValueError('The AI repeated a problem. Try generating again.')
        seen.add(key)
        problems.append(p)
    title = data.get('title', topic)
    if not isinstance(title, str) or not title.strip() or len(title) > 160:
        raise ValueError('The AI returned an invalid worksheet title. Try generating again.')
    return {'version': 1, 'id': str(uuid4()), 'title': title.strip(), 'request': topic,
            'createdAt': datetime.now(timezone.utc).isoformat(), 'provider': provider,
            'sources': context, 'conversation': messages, 'problems': problems, 'attempts': {}}


def read_answer(connection, payload):
    """Consume Activity Island's completed NDJSON answer; partial output is rejected."""
    connection.request('POST', '/ask', body=json.dumps(payload).encode(), headers={'Content-Type': 'application/json'})
    response = connection.getresponse()
    if response.status != 200:
        raise OSError('The AI could not generate practice. Check the provider in Context & chat.')
    chunks, total, done = [], 0, False
    deadline = time.monotonic() + 190
    while True:
        if time.monotonic() > deadline:
            raise OSError('Worksheet generation timed out. Try again.')
        line = response.readline(65537)
        if not line:
            break
        total += len(line)
        if len(line) > 65536 or total > 512 * 1024:
            raise ValueError('The AI response is too large. Try fewer problems.')
        if not line.strip():
            continue
        try:
            event = json.loads(line)
        except (ValueError, UnicodeError):
            raise ValueError('The AI response was interrupted. Try again.') from None
        if not isinstance(event, dict) or event.get('error'):
            raise ValueError('The AI could not finish the worksheet. Try again.')
        if isinstance(event.get('delta'), str):
            chunks.append(event['delta'])
        if event.get('done') is True:
            done = True
    if not done:
        raise ValueError('Worksheet generation was interrupted. Try again.')
    text = ''.join(chunks).strip()
    if text.startswith('```') and text.endswith('```'):
        text = text.split('\n', 1)[-1].rsplit('```', 1)[0].strip()
    try:
        return json.loads(text)
    except ValueError:
        raise ValueError('The AI did not return a worksheet in the expected format. Try again.') from None


def generate_practice(url, request):
    if not isinstance(request, dict):
        raise ValueError('Expected a practice request.')
    topic, count = request.get('topic'), request.get('count', 6)
    if not isinstance(topic, str) or not topic.strip() or len(topic) > 2000:
        raise ValueError('Describe what to practise in 1–2,000 characters.')
    if type(count) is not int or not 1 <= count <= 12:
        raise ValueError('Choose between 1 and 12 problems.')
    messages = request.get('messages', [])
    if not isinstance(messages, list):
        raise ValueError('Expected conversation messages.')
    instruction = (
        f'Create exactly {count} graduated arithmetic practice problems for this study request: {topic.strip()}\n'
        'Use the selected reference passages and conversation to choose the appropriate skills and difficulty. '
        'This prototype supports integer/fraction arithmetic only: addition, subtraction, multiplication, division. '
        'Do not claim to cover unsupported topics (algebra, geometry, word problems). '
        'Return ONLY a JSON object with a short title and problems array. Each problem has exactly '
        'left, operator, right. Operands are strings containing integers or fractions, each numerator/denominator '
        'has at most 4 digits. Operators are +, -, *, /. No zero denominators or division by zero. '
        'Do not repeat problems. Do not write answers or explanations; the server calculates them. '
        'Example shape: {"title":"Adding fractions","problems":[{"left":"1/2","operator":"+","right":"1/3"}]}.'
    )
    payload = validate_chat({'provider': request.get('provider'), 'context': request.get('context', []),
                             'messages': [*messages, {'role': 'user', 'content': instruction}]})
    if activity_providers(url).get('contextChat') != 1:
        raise ValueError('Restart Activity Island with the context-chat update, then reconnect.')
    connection = activity_connection(url, timeout=190)
    try:
        data = read_answer(connection, payload)
    finally:
        connection.close()
    return worksheet(data, topic.strip(), count, payload['context'], payload['messages'][:-1], payload['provider'])


def example_practice():
    pairs = [('1/2', '+', '1/3'), ('3/4', '-', '1/6'), ('2/3', '*', '3/5'),
             ('3/4', '/', '1/2'), ('5/6', '+', '7/12'), ('7/8', '-', '2/3')]
    return worksheet({'title': 'A little fraction practice', 'problems': [
        {'left': a, 'operator': op, 'right': b} for a, op, b in pairs
    ]}, 'A mixture of fraction arithmetic', 6, [], [], 'example')
