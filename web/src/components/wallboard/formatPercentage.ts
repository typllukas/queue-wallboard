export function formatPercentage(ratio: number | null): string {
  return ratio === null ? '–' : `${Math.round(ratio * 100)} %`;
}
