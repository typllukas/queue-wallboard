import type { ReactNode } from 'react';
import { useChangeHighlight, type WorseWhen } from '@/hooks/useChangeHighlight';
import styles from './HighlightedValue.module.css';

type HighlightedValueProps = {
  value: number | null;
  worseWhen: WorseWhen;
  children: ReactNode;
};

export function HighlightedValue({ value, worseWhen, children }: HighlightedValueProps) {
  const highlight = useChangeHighlight(value, worseWhen);
  return (
    // a new key remounts the span so the CSS animation restarts on every change
    <span key={value} className={styles.value} data-change={highlight ?? undefined}>
      {children}
    </span>
  );
}
