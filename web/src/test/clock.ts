export const NOW = new Date('2026-11-03T10:00:00+01:00');

export function formatSecondsAgo(seconds: number): string {
  return new Date(NOW.getTime() - seconds * 1000).toISOString();
}
