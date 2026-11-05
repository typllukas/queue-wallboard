import { useEffect, useState } from 'react';

type ChangeHighlight = 'better' | 'worse' | 'neutral';

export type WorseWhen = 'higher' | 'lower' | null;

const CHANGE_HIGHLIGHT_MILLISECONDS = 1000;

export function useChangeHighlight(value: number | null, worseWhen: WorseWhen): ChangeHighlight | null {
  const [previousValue, setPreviousValue] = useState(value);
  const [highlight, setHighlight] = useState<ChangeHighlight | null>(null);

  if (value !== previousValue) {
    setPreviousValue(value);
    if (value === null || previousValue === null) {
      setHighlight(null);
    } else {
      const isHigher = value > previousValue;
      setHighlight(worseWhen === null ? 'neutral' : isHigher === (worseWhen === 'higher') ? 'worse' : 'better');
    }
  }

  useEffect(() => {
    if (highlight === null) {
      return;
    }
    const timeoutId = setTimeout(() => {
      setHighlight(null);
    }, CHANGE_HIGHLIGHT_MILLISECONDS);
    return () => {
      clearTimeout(timeoutId);
    };
    // value restarts the timer when the number changes again while the same highlight shows
  }, [highlight, value]);

  return highlight;
}
