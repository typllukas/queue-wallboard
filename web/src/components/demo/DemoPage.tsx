'use client';

import { useRef, useState } from 'react';
import { ExchangeOutageButton } from '@/components/demo/ExchangeOutageButton';
import { ScenarioButtons } from '@/components/demo/ScenarioButtons';
import styles from './DemoPage.module.css';

type AgentPageState = 'open' | 'frozen' | 'closed';

export function hasWebSocket(frameWindow: Window): frameWindow is Window & { WebSocket: typeof WebSocket } {
  return 'WebSocket' in frameWindow;
}

export function DemoPage() {
  const [agentPageState, setAgentPageState] = useState<AgentPageState>('open');
  const [agentPageLoadCount, setAgentPageLoadCount] = useState(0);
  const agentFrameRef = useRef<HTMLIFrameElement>(null);

  function freezeAgentPage() {
    const frameWindow = agentFrameRef.current?.contentWindow;
    if (frameWindow === null || frameWindow === undefined || !hasWebSocket(frameWindow)) {
      return;
    }
    frameWindow.WebSocket.prototype.send = () => undefined;
    setAgentPageState('frozen');
  }

  function reloadAgentPage() {
    setAgentPageLoadCount(agentPageLoadCount + 1);
    setAgentPageState('open');
  }

  return (
    <main className={styles.page}>
      <aside className={styles.guide}>
        <h1 className={styles.title}>Queue wallboard: live demo</h1>
        <p className={styles.intro}>
          A simulated Asterisk PBX runs two queues, Sales and Support, with six agents. A ReactPHP daemon reads its AMI events
          and pushes the live queue and agent state to every page over a WebSocket.
        </p>
        <p className={styles.intro}>
          On the right: the <b>wallboard</b> for the floor screen, and below it the pages of the two agents you play,{' '}
          <b>Petr Svoboda</b> (201, Sales) and <b>Lucie Dvořáková</b> (203, Sales and Support). The simulator plays the other four.
        </p>
        <p className={styles.intro}>
          Each step starts with Lucie available. If Petr&apos;s or Lucie&apos;s page shows <b>On pause</b>, an earlier visit
          left them paused: press <b>End pause</b> on that page first.
        </p>
        <ol className={styles.steps}>
          <li className={styles.step}>
            <h2 className={styles.stepTitle}>Change the traffic</h2>
            <ScenarioButtons />
          </li>
          <li className={styles.step}>
            <h2 className={styles.stepTitle}>Pause an agent</h2>
            <p className={styles.explanation}>
              On Lucie&apos;s page, press <b>Lunch</b>, then <b>End pause</b>. With callers waiting, she is on her next call at once.
            </p>
            <p className={styles.watch}>
              <b>Watch:</b> wallboard, Pauses widget, Lunch column: 1 in Sales and in Support while she is on pause.
            </p>
          </li>
          <li className={styles.step}>
            <h2 className={styles.stepTitle}>An agent closes the page</h2>
            <p className={styles.explanation}>
              With no page open for 10 s, the daemon pauses Lucie as Inactive, so no call rings at an empty desk. Works while no other
              tab shows her page. Then reopen it and press <b>End pause</b>.
            </p>
            <p className={styles.watch}>
              <b>Watch:</b> Pauses widget, Inactive column: up by 1 in Sales and in Support about 10 s after closing. Under Peak a
              simulated agent who misses a ring can move it too, so this is clearest on Normal.
            </p>
            {agentPageState === 'closed' ? (
              <button type="button" className={styles.action} onClick={reloadAgentPage}>
                Reopen Lucie&apos;s page
              </button>
            ) : (
              <button type="button" className={styles.action} onClick={() => setAgentPageState('closed')}>
                Close Lucie&apos;s page
              </button>
            )}
          </li>
          <li className={styles.step}>
            <h2 className={styles.stepTitle}>An agent&apos;s page goes silent</h2>
            <p className={styles.explanation}>
              A sleeping laptop or a dropped Wi-Fi closes nothing. Freezing stops Lucie&apos;s page answering the daemon&apos;s pings;
              after 10 s of silence the daemon drops the page and pauses her. Then unfreeze it and press <b>End pause</b>.
            </p>
            <p className={styles.watch}>
              <b>Watch:</b> Pauses widget, Inactive column: up by 1 in both queues within 15 s, clearest on Normal.
            </p>
            {agentPageState === 'frozen' ? (
              <button type="button" className={styles.action} onClick={reloadAgentPage}>
                Unfreeze (reload) Lucie&apos;s page
              </button>
            ) : (
              <button type="button" className={styles.action} disabled={agentPageState === 'closed'} onClick={freezeAgentPage}>
                Freeze Lucie&apos;s page
              </button>
            )}
          </li>
          <li className={styles.step}>
            <h2 className={styles.stepTitle}>The PBX drops</h2>
            <p className={styles.explanation}>
              The simulator cuts the daemon&apos;s AMI connection for 6 s while calls go on. On reconnect the daemon rebuilds queues,
              pauses and waiting callers from a QueueStatus.
            </p>
            <p className={styles.watch}>
              <b>Watch:</b> a red &quot;PBX disconnected&quot; banner over the wallboard and both agent pages, numbers dimmed. After
              about 8 s it goes, and the waits in Waiting activities have kept counting through the outage.
            </p>
            <ExchangeOutageButton />
          </li>
          <li className={styles.step}>
            <h2 className={styles.stepTitle}>The daemon restarts</h2>
            <p className={styles.explanation}>
              In a terminal in the repository folder, run <code>docker compose restart daemon</code>.
            </p>
            <p className={styles.watch}>
              <b>Watch:</b> the indicator top right of every frame goes to &quot;Reconnecting&quot;, then &quot;● Live&quot;. The
              Inactive column does not change, and Service availability now counts &quot;since&quot; the restart.
            </p>
          </li>
        </ol>
      </aside>
      <div className={styles.frames}>
        <iframe title="Wallboard" src="/wallboard" className={styles.wallboardFrame} />
        <div className={styles.agentFrames}>
          <iframe title="Agent 201 page" src="/agent/201" className={styles.agentFrame} />
          {agentPageState !== 'closed' && (
            <div className={styles.agentFrameHolder} data-frozen={agentPageState === 'frozen' ? 'true' : undefined}>
              <iframe key={agentPageLoadCount} ref={agentFrameRef} title="Agent 203 page" src="/agent/203" className={styles.agentFrame} />
              {agentPageState === 'frozen' && (
                <div role="status" aria-label="Lucie's page is frozen" className={styles.frozenOverlay}>
                  <svg viewBox="0 0 24 24" width="40" height="40" aria-hidden="true" className={styles.frozenIcon}>
                    <path d="M2 8.5a15 15 0 0 1 20 0M5.5 12a10 10 0 0 1 13 0M9 15.5a5 5 0 0 1 6 0M3 3l18 18" />
                  </svg>
                  <b>No connection</b>
                  <span>Like a sleeping laptop: this page no longer answers the server, and cannot tell.</span>
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </main>
  );
}
