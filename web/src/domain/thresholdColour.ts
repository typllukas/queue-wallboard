import type { Queue } from '@/api/wireSchema';

const WAITING_ORANGE_ABOVE_FRACTION = 0.5;
const AVAILABLE_ORANGE_ABOVE_FACTOR = 1.5;
const SERVICE_LEVEL_RED_BELOW = 0.8;
const SERVICE_LEVEL_ORANGE_BELOW = 0.9;
const COLOUR_SEVERITY = ['green', 'orange', 'red'] as const;

export type ThresholdColour = (typeof COLOUR_SEVERITY)[number];

export function colourWaiting(waitingCount: number, thresholdCalls: number): ThresholdColour {
  if (waitingCount > thresholdCalls) {
    return 'red';
  }
  return waitingCount > thresholdCalls * WAITING_ORANGE_ABOVE_FRACTION ? 'orange' : 'green';
}

export function colourAvailable(availableCount: number, thresholdUsers: number): ThresholdColour {
  if (availableCount < thresholdUsers) {
    return 'red';
  }
  return availableCount > thresholdUsers * AVAILABLE_ORANGE_ABOVE_FACTOR ? 'orange' : 'green';
}

export function colourServiceLevel(ratio: number | null): ThresholdColour | null {
  if (ratio === null) {
    return null;
  }
  if (ratio < SERVICE_LEVEL_RED_BELOW) {
    return 'red';
  }
  return ratio < SERVICE_LEVEL_ORANGE_BELOW ? 'orange' : 'green';
}

export function isWaitingTooLong(waitingSince: string, thresholdTime: number, now: Date): boolean {
  return now.getTime() - new Date(waitingSince).getTime() > thresholdTime * 1000;
}

export function findWorstColour(colours: ThresholdColour[]): ThresholdColour {
  return COLOUR_SEVERITY[Math.max(0, ...colours.map((colour) => COLOUR_SEVERITY.indexOf(colour)))] ?? 'green';
}

export function findQueueColour(queue: Pick<Queue, 'waiting_calls' | 'threshold_calls' | 'available' | 'threshold_users'>): ThresholdColour {
  return findWorstColour([
    colourWaiting(queue.waiting_calls.length, queue.threshold_calls),
    colourAvailable(queue.available, queue.threshold_users),
  ]);
}
