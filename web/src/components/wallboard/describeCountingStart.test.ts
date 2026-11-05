import { expect, test } from 'vitest';
import { describeCountingStart } from './describeCountingStart';

test('the counting start reads in the floor time the daemon sent, whatever the viewer time zone', () => {
  expect(describeCountingStart('2026-11-04T00:00:00+00:00')).toBe('midnight');
  expect(describeCountingStart('2026-11-04T00:00:00+01:00')).toBe('midnight');
  expect(describeCountingStart('2026-11-04T10:21:26+05:30')).toBe('10:21');
});
