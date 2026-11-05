import type { Queue } from '@/api/wireSchema';
import { colourAvailable, colourWaiting, findQueueColour } from '@/domain/thresholdColour';
import { HighlightedValue } from '@/components/HighlightedValue';
import { formatPercentage } from './formatPercentage';
import styles from './StatsTable.module.css';
import { Widget } from './Widget';

type QueuesStatsProps = {
  queues: Queue[];
};

export function QueuesStats({ queues }: QueuesStatsProps) {
  return (
    <Widget title="Queues stats">
      <table className={styles.table}>
        <thead>
          <tr>
            <th>Queue</th>
            <th>Waiting</th>
            <th>Ongoing</th>
            <th>Available</th>
            <th>Answered</th>
            <th>Unanswered</th>
            <th>Total</th>
            <th>ABN</th>
          </tr>
        </thead>
        <tbody>
          {queues.map((queue) => {
            const waitingColour = colourWaiting(queue.waiting_calls.length, queue.threshold_calls);
            const availableColour = colourAvailable(queue.available, queue.threshold_users);
            return (
              <tr key={queue.name}>
                <th scope="row" className={styles.queueName} data-colour={findQueueColour(queue)}>
                  {queue.title}
                </th>
                <td data-colour={waitingColour}>
                  <HighlightedValue value={queue.waiting_calls.length} worseWhen="higher">
                    {queue.waiting_calls.length}
                  </HighlightedValue>
                </td>
                <td>
                  <HighlightedValue value={queue.ongoing} worseWhen={null}>
                    {queue.ongoing}
                  </HighlightedValue>
                </td>
                <td data-colour={availableColour}>
                  <HighlightedValue value={queue.available} worseWhen="lower">
                    {queue.available}
                  </HighlightedValue>
                </td>
                <td>
                  <HighlightedValue value={queue.answered} worseWhen="lower">
                    {queue.answered}
                  </HighlightedValue>
                </td>
                <td>
                  <HighlightedValue value={queue.unanswered} worseWhen="higher">
                    {queue.unanswered}
                  </HighlightedValue>
                </td>
                <td>
                  <HighlightedValue value={queue.total} worseWhen={null}>
                    {queue.total}
                  </HighlightedValue>
                </td>
                <td>
                  <HighlightedValue value={queue.abandon_rate} worseWhen="higher">
                    {formatPercentage(queue.abandon_rate)}
                  </HighlightedValue>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </Widget>
  );
}
