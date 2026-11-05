import { useId, type ReactNode } from 'react';
import styles from './Widget.module.css';

type WidgetProps = {
  title: string;
  subtitle?: string;
  children: ReactNode;
};

export function Widget({ title, subtitle, children }: WidgetProps) {
  const headingId = useId();
  return (
    <section aria-labelledby={headingId} className={styles.widget}>
      <h2 id={headingId} className={styles.title}>
        {title}
      </h2>
      {subtitle !== undefined && <p className={styles.subtitle}>{subtitle}</p>}
      {children}
    </section>
  );
}
