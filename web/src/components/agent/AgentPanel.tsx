'use client';

import { useEffect, useId, useReducer, useRef, useState } from 'react';
import { requestablePauseReasonSchema, type Agent, type RequestablePauseReason } from '@/api/wireSchema';
import { ExchangeBanner } from '@/components/ExchangeBanner';
import { HighlightedValue } from '@/components/HighlightedValue';
import { LiveIndicator } from '@/components/LiveIndicator';
import { MissingFloorState } from '@/components/MissingFloorState';
import { findAgentByExtension, floorReducer, type FloorState } from '@/domain/floorReducer';
import { formatElapsedTime } from '@/domain/formatElapsedTime';
import { PAUSE_LABELS } from '@/domain/pauseLabels';
import { colourWaiting, findQueueColour } from '@/domain/thresholdColour';
import { useDaemonWebSocket } from '@/hooks/useDaemonWebSocket';
import { useNow } from '@/hooks/useNow';
import styles from './AgentPanel.module.css';

type AgentPanelProps = {
  extension: string;
  initialState: FloorState | null;
};

type StateBlock = {
  heading: string;
  startTime: string;
  tone: 'available' | 'speaking' | 'paused' | 'inactive';
  explanation: string | null;
};

const INACTIVE_EXPLANATION = 'you did not answer a call in time, or this page stopped responding';

function describeState(agent: Agent): StateBlock {
  if (agent.id_pause !== null) {
    return {
      heading: `On pause: ${PAUSE_LABELS[agent.id_pause]}`,
      startTime: agent.onpause ?? agent.statetime,
      tone: agent.id_pause === 'inactive' ? 'inactive' : 'paused',
      explanation: agent.id_pause === 'inactive' ? INACTIVE_EXPLANATION : null,
    };
  }
  if (agent.exten_status === 'Busy') {
    return { heading: 'Speaking', startTime: agent.statetime, tone: 'speaking', explanation: null };
  }
  return { heading: 'Available', startTime: agent.statetime, tone: 'available', explanation: null };
}

export function AgentPanel({ extension, initialState }: AgentPanelProps) {
  const [floorState, dispatchFloorMessage] = useReducer(floorReducer, initialState);
  const [requestedPause, setRequestedPause] = useState<RequestablePauseReason | null>(null);
  const { connectionStatus, reconnectInSeconds, send, lastError, clearError } = useDaemonWebSocket({
    agentExtension: extension,
    onMessage: (message) => {
      if (message.action === 'snapshot') {
        setRequestedPause(null);
      }
      if (message.action === 'exchangeConnection' && message.connected) {
        clearError();
      }
      dispatchFloorMessage(message);
    },
  });
  const now = useNow(1000);
  const queuesHeadingId = useId();
  const buttonsRef = useRef<HTMLDivElement>(null);
  const isButtonFocusOwedRef = useRef(false);

  const agent = floorState === null ? undefined : findAgentByExtension(floorState, extension);
  const isAgentPaused = agent !== undefined && agent.id_pause !== null;

  useEffect(() => {
    if (!isButtonFocusOwedRef.current) {
      return;
    }
    isButtonFocusOwedRef.current = false;
    if (document.activeElement === null || document.activeElement === document.body) {
      buttonsRef.current?.querySelector('button')?.focus();
    }
  }, [isAgentPaused]);
  useEffect(() => {
    if (lastError !== null) {
      isButtonFocusOwedRef.current = false;
    }
  }, [lastError]);
  if (requestedPause !== null && (connectionStatus !== 'live' || isAgentPaused || lastError !== null)) {
    setRequestedPause(null);
  }

  const header = (
    <header className={styles.header}>
      <h1 className={styles.title}>{agent === undefined ? `Agent ${extension}` : `${agent.title} · ${agent.exten}`}</h1>
      <LiveIndicator connectionStatus={connectionStatus} reconnectInSeconds={reconnectInSeconds} />
    </header>
  );

  if (connectionStatus === 'refused') {
    return (
      <div className={styles.panel}>
        {header}
        <p>No agent with extension {extension} is known to the server.</p>
      </div>
    );
  }
  if (floorState === null) {
    return (
      <div className={styles.panel}>
        {header}
        <MissingFloorState connectionStatus={connectionStatus} />
      </div>
    );
  }
  if (Object.keys(floorState.agents).length === 0) {
    return (
      <div className={styles.panel}>
        {header}
        <ExchangeBanner exchangeConnected={floorState.exchangeConnected} />
        <p>Waiting for the PBX to list the agents…</p>
      </div>
    );
  }
  if (agent === undefined) {
    return (
      <div className={styles.panel}>
        {header}
        <p>No agent with extension {extension} is known to the server.</p>
      </div>
    );
  }

  const isConnectionLost = connectionStatus === 'reconnecting';
  const isDimmed = isConnectionLost || !floorState.exchangeConnected;
  const isPausing = requestedPause !== null && agent.id_pause === null;
  const areButtonsDisabled = isPausing || connectionStatus !== 'live' || !floorState.exchangeConnected;
  const stateBlock = describeState(agent);
  const elapsedText = now === null ? null : `for ${formatElapsedTime(stateBlock.startTime, now)}`;
  const stateDetail = isPausing
    ? `Pausing for ${PAUSE_LABELS[requestedPause]}…`
    : [elapsedText, stateBlock.explanation].filter((part) => part !== null).join(' · ');
  const agentQueues = agent.queues.flatMap((queueName) => floorState.queues[queueName] ?? []);

  return (
    <div className={styles.panel}>
      {header}
      {isConnectionLost && <p className={styles.banner}>Connection to the server lost. Showing the last known state.</p>}
      <ExchangeBanner exchangeConnected={floorState.exchangeConnected} />
      {lastError !== null && (
        <p role="alert" className={styles.error}>
          {lastError}
        </p>
      )}
      <main className={styles.content} data-dimmed={isDimmed ? 'true' : undefined}>
        <section aria-label="State" className={styles.state} data-tone={stateBlock.tone}>
          <b className={styles.stateHeading}>{stateBlock.heading}</b>
          <span>{stateDetail}</span>
        </section>
        <div ref={buttonsRef} className={styles.buttons}>
          {agent.id_pause === null ? (
            requestablePauseReasonSchema.options.map((pauseReason) => (
              <button
                key={pauseReason}
                type="button"
                disabled={areButtonsDisabled}
                onClick={() => {
                  clearError();
                  isButtonFocusOwedRef.current = true;
                  setRequestedPause(pauseReason);
                  send({ action: 'pause', pause: pauseReason });
                }}
              >
                {PAUSE_LABELS[pauseReason]}
              </button>
            ))
          ) : (
            <button
              type="button"
              className={styles.primary}
              disabled={areButtonsDisabled}
              onClick={() => {
                clearError();
                isButtonFocusOwedRef.current = true;
                send({ action: 'unpause' });
              }}
            >
              End pause
            </button>
          )}
        </div>
        <section aria-labelledby={queuesHeadingId}>
          <div className={styles.queuesHeading}>
            <h2 id={queuesHeadingId}>My queues</h2>
            <span>Waiting</span>
          </div>
          <ul className={styles.queueList}>
            {agentQueues.map((queue) => (
              <li key={queue.name}>
                <span data-colour={findQueueColour(queue)}>{queue.title}</span>
                <span data-colour={colourWaiting(queue.waiting_calls.length, queue.threshold_calls)}>
                  <HighlightedValue value={queue.waiting_calls.length} worseWhen="higher">
                    {queue.waiting_calls.length}
                  </HighlightedValue>
                </span>
              </li>
            ))}
          </ul>
        </section>
      </main>
    </div>
  );
}
