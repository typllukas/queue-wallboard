'use client';

import { useReducer } from 'react';
import { ExchangeBanner } from '@/components/ExchangeBanner';
import { LiveIndicator } from '@/components/LiveIndicator';
import { MissingFloorState } from '@/components/MissingFloorState';
import { floorReducer, type FloorState } from '@/domain/floorReducer';
import { useDaemonWebSocket } from '@/hooks/useDaemonWebSocket';
import { useNow } from '@/hooks/useNow';
import { Pauses } from './Pauses';
import { QueuesStats } from './QueuesStats';
import { ServiceAvailability } from './ServiceAvailability';
import styles from './Wallboard.module.css';
import { WaitingActivities } from './WaitingActivities';

type WallboardProps = {
  initialState: FloorState | null;
};

export function Wallboard({ initialState }: WallboardProps) {
  const [floorState, dispatchFloorMessage] = useReducer(floorReducer, initialState);
  const { connectionStatus, reconnectInSeconds } = useDaemonWebSocket({
    agentExtension: null,
    onMessage: dispatchFloorMessage,
  });
  const now = useNow(1000);

  const queues = floorState === null ? [] : Object.values(floorState.queues);
  const isDimmed = connectionStatus === 'reconnecting' || floorState?.exchangeConnected === false;

  return (
    <div className={styles.wallboard}>
      <header className={styles.header}>
        <h1 className={styles.title}>Wallboard</h1>
        {floorState !== null && <ExchangeBanner exchangeConnected={floorState.exchangeConnected} />}
        <LiveIndicator connectionStatus={connectionStatus} reconnectInSeconds={reconnectInSeconds} />
      </header>
      <main className={styles.content} data-dimmed={isDimmed ? 'true' : undefined}>
        {floorState === null ? (
          <MissingFloorState connectionStatus={connectionStatus} />
        ) : (
          <>
            <ServiceAvailability queues={queues} slaThresholdSeconds={floorState.slaThresholdSeconds} />
            <div className={styles.middleRow}>
              <QueuesStats queues={queues} />
              <WaitingActivities queues={queues} now={now} />
            </div>
            <Pauses queues={queues} />
          </>
        )}
      </main>
    </div>
  );
}
