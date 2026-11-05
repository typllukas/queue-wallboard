import { PAUSE_REASONS, type Queue } from '@/api/wireSchema';
import { HighlightedValue } from '@/components/HighlightedValue';
import { PAUSE_LABELS } from '@/domain/pauseLabels';
import styles from './StatsTable.module.css';
import { Widget } from './Widget';

type PausesProps = {
  queues: Queue[];
};

export function Pauses({ queues }: PausesProps) {
  return (
    <Widget title="Pauses">
      <table className={styles.table}>
        <thead>
          <tr>
            <th>Queue</th>
            {PAUSE_REASONS.map((pauseReason) => (
              <th key={pauseReason}>{PAUSE_LABELS[pauseReason]}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {queues.map((queue) => (
            <tr key={queue.name}>
              <th scope="row" className={styles.plainRowHeader}>
                {queue.title}
              </th>
              {PAUSE_REASONS.map((pauseReason) => (
                <td key={pauseReason}>
                  <HighlightedValue value={queue.users_on_pause[pauseReason]} worseWhen={null}>
                    {queue.users_on_pause[pauseReason]}
                  </HighlightedValue>
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </Widget>
  );
}
