import { expect, test } from 'vitest';
import {
  colourAvailable,
  colourServiceLevel,
  colourWaiting,
  findQueueColour,
  findWorstColour,
  isWaitingTooLong,
  type ThresholdColour,
} from '@/domain/thresholdColour';
import { NOW } from '@/test/clock';

const THRESHOLD_CALLS = 5;
const THRESHOLD_USERS = 1;

test.each([
  { waitingCount: 2, expected: 'green' },
  { waitingCount: 3, expected: 'orange' },
  { waitingCount: 5, expected: 'orange' },
  { waitingCount: 6, expected: 'red' },
] satisfies { waitingCount: number; expected: ThresholdColour }[])('$waitingCount waiting calls on a threshold of 5 are $expected', ({ waitingCount, expected }) => {
  expect(colourWaiting(waitingCount, THRESHOLD_CALLS)).toBe(expected);
});

test.each([
  { availableCount: 0, expected: 'red' },
  { availableCount: 1, expected: 'green' },
  { availableCount: 2, expected: 'orange' },
] satisfies { availableCount: number; expected: ThresholdColour }[])('$availableCount available agents on a threshold of 1 are $expected', ({ availableCount, expected }) => {
  expect(colourAvailable(availableCount, THRESHOLD_USERS)).toBe(expected);
});

test.each([
  { ratio: 0.79, expected: 'red' },
  { ratio: 0.85, expected: 'orange' },
  { ratio: 0.9, expected: 'green' },
  { ratio: null, expected: null },
] satisfies { ratio: number | null; expected: ThresholdColour | null }[])('service level $ratio is $expected', ({ ratio, expected }) => {
  expect(colourServiceLevel(ratio)).toBe(expected);
});

test.each([
  { waitingSince: '2026-11-03T09:58:59+01:00', expected: true },
  { waitingSince: '2026-11-03T09:59:00+01:00', expected: false },
] satisfies { waitingSince: string; expected: boolean }[])('a call waiting since $waitingSince on a 60 s threshold is too long: $expected', ({ waitingSince, expected }) => {
  expect(isWaitingTooLong(waitingSince, 60, NOW)).toBe(expected);
});

test.each([
  { colours: ['green', 'orange'], expected: 'orange' },
  { colours: ['orange', 'red', 'green'], expected: 'red' },
  { colours: ['green', 'green'], expected: 'green' },
  { colours: [], expected: 'green' },
] satisfies { colours: ThresholdColour[]; expected: ThresholdColour }[])('the worst of $colours is $expected', ({ colours, expected }) => {
  expect(findWorstColour(colours)).toBe(expected);
});

test.each([
  [1, 1, 'green'],
  [3, 1, 'orange'],
  [6, 1, 'red'],
  [1, 0, 'red'],
  [1, 2, 'orange'],
] satisfies [number, number, ThresholdColour][])('a queue with %i waiting and %i available takes %s, the worse of its two colours', (waitingCount, availableCount, expectedColour) => {
  const waitingCalls = [...Array(waitingCount).keys()].map((callIndex) => ({
    id: `call-${callIndex}`,
    waiting_since: '2026-11-03T10:00:00+01:00',
  }));

  expect(
    findQueueColour({
      waiting_calls: waitingCalls,
      threshold_calls: THRESHOLD_CALLS,
      available: availableCount,
      threshold_users: THRESHOLD_USERS,
    }),
  ).toBe(expectedColour);
});
