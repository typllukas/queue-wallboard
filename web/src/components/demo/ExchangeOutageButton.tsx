'use client';

import { useState } from 'react';
import { getSimulatorControlUrl } from '@/config';
import styles from './DemoPage.module.css';

type OutageRequest = { state: 'idle' } | { state: 'started'; startedAt: Date } | { state: 'failed' };

export function ExchangeOutageButton() {
  const [outageRequest, setOutageRequest] = useState<OutageRequest>({ state: 'idle' });

  async function startOutage() {
    try {
      const response = await fetch(`${getSimulatorControlUrl()}/exchange-outage`, { method: 'POST' });
      setOutageRequest(response.ok ? { state: 'started', startedAt: new Date() } : { state: 'failed' });
    } catch {
      setOutageRequest({ state: 'failed' });
    }
  }

  return (
    <div>
      <button type="button" className={styles.action} onClick={() => void startOutage()}>
        Drop the PBX connection
      </button>
      {outageRequest.state === 'started' && (
        <p className={styles.result}>
          Dropped at {outageRequest.startedAt.toLocaleTimeString('en-GB')}. The daemon reconnects about 8 s later.
        </p>
      )}
      {outageRequest.state === 'failed' && (
        <p role="alert" className={styles.error}>
          Could not reach the simulator. Is it running?
        </p>
      )}
    </div>
  );
}
