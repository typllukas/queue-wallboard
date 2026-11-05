import type { Queue } from '@/api/wireSchema';
import { colourServiceLevel, findWorstColour } from '@/domain/thresholdColour';
import { HighlightedValue } from '@/components/HighlightedValue';
import { describeCountingStart } from './describeCountingStart';
import { formatPercentage } from './formatPercentage';
import styles from './ServiceAvailability.module.css';
import { Widget } from './Widget';

type ServiceAvailabilityProps = {
  queues: Queue[];
  slaThresholdSeconds: number;
};

type ServiceLevelProps = {
  label: 'SLA' | 'SVL';
  ratio: number | null;
};

function ServiceLevel({ label, ratio }: ServiceLevelProps) {
  return (
    <div role="group" aria-label={label} className={styles.serviceLevel} data-colour={colourServiceLevel(ratio) ?? undefined}>
      <b>
        <HighlightedValue value={ratio} worseWhen="lower">
          {formatPercentage(ratio)}
        </HighlightedValue>
      </b>
      <span className={styles.label}>{label}</span>
    </div>
  );
}

export function ServiceAvailability({ queues, slaThresholdSeconds }: ServiceAvailabilityProps) {
  const firstQueue = queues[0];
  const slaThreshold = `SLA threshold ${slaThresholdSeconds} s`;
  return (
    <Widget
      title="Service availability"
      subtitle={firstQueue === undefined ? slaThreshold : `since ${describeCountingStart(firstQueue.counted_since)} · ${slaThreshold}`}
    >
      <div className={styles.queues}>
        {queues.map((queue) => {
          const colours = [colourServiceLevel(queue.sla), colourServiceLevel(queue.svl)].filter((colour) => colour !== null);
          return (
            <div key={queue.name} role="group" aria-label={queue.title} className={styles.queue}>
              <span className={styles.queueName} data-colour={colours.length === 0 ? undefined : findWorstColour(colours)}>
                {queue.title}
              </span>
              <ServiceLevel label="SLA" ratio={queue.sla} />
              <ServiceLevel label="SVL" ratio={queue.svl} />
            </div>
          );
        })}
      </div>
    </Widget>
  );
}
