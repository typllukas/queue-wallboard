import type { Queue } from '@/api/wireSchema';
import { formatElapsedTime } from '@/domain/formatElapsedTime';
import { isWaitingTooLong } from '@/domain/thresholdColour';
import styles from './WaitingActivities.module.css';
import { Widget } from './Widget';

const SHOWN_WAITING_CALL_LIMIT = 6;

type WaitingActivitiesProps = {
  queues: Queue[];
  now: Date | null;
};

export function WaitingActivities({ queues, now }: WaitingActivitiesProps) {
  const waitingCalls = queues
    .flatMap((queue) => queue.waiting_calls.map((call) => ({ ...call, queue })))
    .sort((firstWaitingCall, secondWaitingCall) => Date.parse(firstWaitingCall.waiting_since) - Date.parse(secondWaitingCall.waiting_since));

  return (
    <Widget title="Waiting activities">
      {waitingCalls.length === 0 ? (
        <p className={styles.empty}>No calls waiting</p>
      ) : (
        <ul className={styles.list}>
          {waitingCalls.slice(0, SHOWN_WAITING_CALL_LIMIT).map((call) => (
            <li
              key={call.id}
              className={styles.call}
              data-colour={now !== null && isWaitingTooLong(call.waiting_since, call.queue.threshold_time, now) ? 'red' : undefined}
            >
              <span>{call.queue.title}</span>
              <b>{now === null ? '' : formatElapsedTime(call.waiting_since, now)}</b>
            </li>
          ))}
        </ul>
      )}
      {waitingCalls.length > SHOWN_WAITING_CALL_LIMIT && (
        <p className={styles.more}>and {waitingCalls.length - SHOWN_WAITING_CALL_LIMIT} more waiting</p>
      )}
    </Widget>
  );
}
