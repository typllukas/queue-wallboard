export function formatElapsedTime(startTime: string, now: Date): string {
  const totalSeconds = Math.max(0, Math.floor((now.getTime() - new Date(startTime).getTime()) / 1000));
  const seconds = String(totalSeconds % 60).padStart(2, '0');
  if (totalSeconds < 3600) {
    return `${Math.floor(totalSeconds / 60)}:${seconds}`;
  }
  const minutes = String(Math.floor(totalSeconds / 60) % 60).padStart(2, '0');
  return `${Math.floor(totalSeconds / 3600)}:${minutes}:${seconds}`;
}
