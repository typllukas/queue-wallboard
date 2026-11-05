import { expect, test } from 'vitest';
import { formatElapsedTime } from '@/domain/formatElapsedTime';
import { formatSecondsAgo, NOW } from '@/test/clock';

test.each([
  [0, '0:00'],
  [12, '0:12'],
  [3599, '59:59'],
  [3600, '1:00:00'],
  [32825, '9:07:05'],
])('%i seconds read %s', (seconds, expectedText) => {
  expect(formatElapsedTime(formatSecondsAgo(seconds), NOW)).toBe(expectedText);
});

test('a start in the future reads as no time at all', () => {
  expect(formatElapsedTime(formatSecondsAgo(-5), NOW)).toBe('0:00');
});
