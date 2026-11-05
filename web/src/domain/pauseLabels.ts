import type { PauseReason } from '@/api/wireSchema';

export const PAUSE_LABELS = {
  lunch: 'Lunch',
  training: 'Training',
  inactive: 'Inactive',
  other: 'Other',
} as const satisfies Record<PauseReason, string>;
