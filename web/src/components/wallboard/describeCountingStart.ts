const FLOOR_TIME_PATTERN = /T(\d{2}):(\d{2})/;

export function describeCountingStart(countedSince: string): string {
  const floorTime = FLOOR_TIME_PATTERN.exec(countedSince);
  if (floorTime === null) {
    return countedSince;
  }
  const [, hours, minutes] = floorTime;
  return hours === '00' && minutes === '00' ? 'midnight' : `${hours}:${minutes}`;
}
