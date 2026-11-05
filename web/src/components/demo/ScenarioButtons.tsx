'use client';

import { useEffect, useRef, useState } from 'react';
import { z } from 'zod';
import { getSimulatorControlUrl } from '@/config';
import styles from './ScenarioButtons.module.css';

export const SCENARIO_POLL_MILLISECONDS = 5000;

const SCENARIO_IDS = ['quiet', 'normal', 'peak'] as const;

type ScenarioId = (typeof SCENARIO_IDS)[number];

const SCENARIO_TEXTS: Record<ScenarioId, { label: string; expectation: string }> = {
  quiet: {
    label: 'Quiet',
    expectation: 'Watch Queues stats: nothing turns red. Available may turn orange, because an idle floor counts as overstaffed.',
  },
  normal: {
    label: 'Normal',
    expectation: 'Watch Queues stats: now and then callers queue up, so Waiting turns orange, and Available turns red while every agent is busy.',
  },
  peak: {
    label: 'Peak',
    expectation:
      "Watch Queues stats: within about a minute Support's Waiting turns red and keeps climbing, and Unanswered grows as callers hang up. Service availability: Support's SLA falls.",
  },
};

const runningScenarioSchema = z.object({ scenario: z.enum(SCENARIO_IDS) });

export function ScenarioButtons() {
  const [activeScenario, setActiveScenario] = useState<ScenarioId | null>(null);
  const [failedScenario, setFailedScenario] = useState<ScenarioId | null>(null);
  const switchCountRef = useRef(0);

  useEffect(() => {
    let isMounted = true;
    const readRunningScenario = async () => {
      const switchCountAtStart = switchCountRef.current;
      try {
        const response = await fetch(`${getSimulatorControlUrl()}/scenario`);
        const parsed = runningScenarioSchema.safeParse(await response.json());
        if (!parsed.success) {
          console.error('Dropped a scenario answer the schema rejects', parsed.error.issues);
          return;
        }
        if (isMounted && switchCountRef.current === switchCountAtStart) {
          setActiveScenario(parsed.data.scenario);
        }
      } catch {
        return;
      }
    };
    void readRunningScenario();
    const pollIntervalId = setInterval(() => void readRunningScenario(), SCENARIO_POLL_MILLISECONDS);
    return () => {
      isMounted = false;
      clearInterval(pollIntervalId);
    };
  }, []);

  async function switchScenario(scenario: ScenarioId) {
    setFailedScenario(null);
    try {
      const response = await fetch(`${getSimulatorControlUrl()}/scenario/${scenario}`, { method: 'POST' });
      if (!response.ok) {
        setFailedScenario(scenario);
        return;
      }
      switchCountRef.current += 1;
      setActiveScenario(scenario);
    } catch {
      setFailedScenario(scenario);
    }
  }

  return (
    <div className={styles.scenarios}>
      <div className={styles.buttons}>
        {SCENARIO_IDS.map((scenario) => (
          <button
            key={scenario}
            type="button"
            aria-pressed={scenario === activeScenario}
            className={styles.button}
            onClick={() => void switchScenario(scenario)}
          >
            {SCENARIO_TEXTS[scenario].label}
          </button>
        ))}
      </div>
      <p className={styles.expectation}>{activeScenario === null ? 'Pick a scenario.' : SCENARIO_TEXTS[activeScenario].expectation}</p>
      {failedScenario !== null && (
        <p role="alert" className={styles.error}>
          Could not switch to {SCENARIO_TEXTS[failedScenario].label}. Is the simulator running?
        </p>
      )}
    </div>
  );
}
