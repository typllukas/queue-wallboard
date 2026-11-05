import { useCallback, useSyncExternalStore } from 'react';

function readServerSnapshot(): null {
  return null;
}

export function useNow(intervalMilliseconds: number): Date | null {
  const subscribe = useCallback(
    (notifyChange: () => void) => {
      const intervalId = setInterval(notifyChange, intervalMilliseconds);
      return () => {
        clearInterval(intervalId);
      };
    },
    [intervalMilliseconds],
  );
  const readClientSnapshot = useCallback(
    () => Math.floor(Date.now() / intervalMilliseconds) * intervalMilliseconds,
    [intervalMilliseconds],
  );

  const currentTick = useSyncExternalStore(subscribe, readClientSnapshot, readServerSnapshot);
  return currentTick === null ? null : new Date(currentTick);
}
