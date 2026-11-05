import { act, renderHook, waitFor } from '@testing-library/react';
import { StrictMode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { serverMessageSchema } from '@/api/wireSchema';
import type { FloorMessage } from '@/domain/floorReducer';
import { MAXIMUM_BACKOFF_SECONDS, useDaemonWebSocket, WATCHDOG_MILLISECONDS } from '@/hooks/useDaemonWebSocket';
import { readSnapshotExample } from '@/test/contractExample';
import { listenAsDaemon, type DaemonControl } from '@/test/daemonFake';

const MAXIMUM_BACKOFF_MILLISECONDS = MAXIMUM_BACKOFF_SECONDS * 1000;
const snapshotExample = serverMessageSchema.parse(readSnapshotExample());

afterEach(() => {
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

function renderConnection(agentExtension: string | null = null, wrapper?: typeof StrictMode) {
  const receivedMessages: FloorMessage[] = [];
  const renderedHook = renderHook(() =>
    useDaemonWebSocket({
      agentExtension,
      onMessage: (message) => {
        receivedMessages.push(message);
      },
    }),
    { wrapper },
  );
  return { ...renderedHook, receivedMessages };
}

test('a ping is answered with a pong', async () => {
  const daemonControl = listenAsDaemon();
  const { unmount } = renderConnection();
  await waitFor(() => expect(daemonControl.connectionCount).toBe(1));

  daemonControl.sendToPage({ action: 'ping' });

  await waitFor(() => expect(daemonControl.clientMessages).toEqual([{ action: 'pong' }]));
  unmount();
});

test('a snapshot goes to onMessage and turns the status live', async () => {
  const daemonControl = listenAsDaemon();
  const { result, receivedMessages, unmount } = renderConnection();
  expect(result.current.connectionStatus).toBe('connecting');

  await waitFor(() => {
    daemonControl.sendToPage(snapshotExample);
    expect(result.current.connectionStatus).toBe('live');
  });

  expect(receivedMessages[0]?.action).toBe('snapshot');
  unmount();
});

test('an agent page opens the socket with its extension in the query', async () => {
  const daemonControl = listenAsDaemon();
  const { unmount } = renderConnection('203');

  await waitFor(() => expect(daemonControl.connectedUrls).toEqual(['ws://localhost:8090/websocket?agent=203']));
  unmount();
});

test('an error message lands in lastError until it is cleared', async () => {
  const daemonControl = listenAsDaemon();
  const { result, unmount } = renderConnection();

  await waitFor(() => {
    daemonControl.sendToPage({ action: 'error', message: 'Unknown agent 999' });
    expect(result.current.lastError).toBe('Unknown agent 999');
  });
  act(() => {
    result.current.clearError();
  });

  expect(result.current.lastError).toBeNull();
  unmount();
});

test('a message the schema rejects is logged and changes no state', async () => {
  const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
  const daemonControl = listenAsDaemon();
  const { result, receivedMessages, unmount } = renderConnection();

  await waitFor(() => {
    daemonControl.sendMalformedToPage({ action: 'agentsChanged', agents: [{ id_agent: 7 }] });
    expect(consoleError).toHaveBeenCalled();
  });

  expect(receivedMessages).toEqual([]);
  expect(result.current.lastError).toBeNull();
  expect(result.current.connectionStatus).toBe('connecting');
  unmount();
});

test('a close from the daemon starts a reconnect that connects again', async () => {
  vi.spyOn(Math, 'random').mockReturnValue(1);
  const daemonControl = listenAsDaemon();
  const { result, unmount } = renderConnection();
  await waitFor(() => expect(daemonControl.connectionCount).toBe(1));
  await waitFor(() => {
    daemonControl.sendToPage(snapshotExample);
    expect(result.current.connectionStatus).toBe('live');
  });

  daemonControl.closeFromDaemon();

  await waitFor(() => expect(result.current.connectionStatus).toBe('reconnecting'));
  expect(result.current.reconnectInSeconds).toBe(1);
  await waitFor(() => expect(daemonControl.connectionCount).toBe(2), { timeout: 3000 });
  unmount();
});

test('a policy violation close, which the daemon sends for an unknown agent, stops the reconnecting', async () => {
  vi.spyOn(Math, 'random').mockReturnValue(1);
  const daemonControl = listenAsDaemon();
  const { result, unmount } = renderConnection('299');
  await waitFor(() => expect(daemonControl.connectionCount).toBe(1));

  daemonControl.closeFromDaemon(1008);

  await waitFor(() => expect(result.current.connectionStatus).toBe('refused'));
  await new Promise((resolve) => setTimeout(resolve, 1500));
  expect(daemonControl.connectionCount).toBe(1);
  expect(result.current.reconnectInSeconds).toBeNull();
  unmount();
});

const FAKED_TIMERS = ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval'] as const;
const SAFE_MARGIN_MILLISECONDS = 1000;

test('silence for ten seconds after the last ping makes the page reconnect', async () => {
  vi.useFakeTimers({ toFake: [...FAKED_TIMERS] });
  const daemonControl = listenAsDaemon();
  const { result, unmount } = renderConnection();
  await vi.waitFor(() => expect(daemonControl.connectionCount).toBe(1));

  act(() => {
    vi.advanceTimersByTime(WATCHDOG_MILLISECONDS - SAFE_MARGIN_MILLISECONDS);
  });
  expect(result.current.connectionStatus).toBe('connecting');

  act(() => {
    vi.advanceTimersByTime(2 * SAFE_MARGIN_MILLISECONDS);
  });
  expect(result.current.connectionStatus).toBe('reconnecting');

  act(() => {
    vi.advanceTimersByTime(MAXIMUM_BACKOFF_MILLISECONDS);
  });
  await vi.waitFor(() => expect(daemonControl.connectionCount).toBeGreaterThanOrEqual(2));
  unmount();
});

test('a ping restarts the ten second watchdog', async () => {
  vi.useFakeTimers({ toFake: [...FAKED_TIMERS] });
  const daemonControl = listenAsDaemon();
  const { result, unmount } = renderConnection();
  await vi.waitFor(() => expect(daemonControl.connectionCount).toBe(1));

  act(() => {
    vi.advanceTimersByTime(WATCHDOG_MILLISECONDS - SAFE_MARGIN_MILLISECONDS);
  });
  daemonControl.sendToPage({ action: 'ping' });
  await vi.waitFor(() => expect(daemonControl.clientMessages).toEqual([{ action: 'pong' }]));
  act(() => {
    vi.advanceTimersByTime(WATCHDOG_MILLISECONDS - SAFE_MARGIN_MILLISECONDS);
  });

  expect(result.current.connectionStatus).toBe('connecting');
  unmount();
});

test('unmounting closes the socket and schedules no reconnect', async () => {
  vi.useFakeTimers({ toFake: [...FAKED_TIMERS] });
  const daemonControl = listenAsDaemon();
  const { unmount } = renderConnection();
  await vi.waitFor(() => expect(daemonControl.connectionCount).toBe(1));

  unmount();

  await vi.waitFor(() => expect(daemonControl.closedCount).toBe(1));
  expect(vi.getTimerCount()).toBe(0);
  act(() => {
    vi.advanceTimersByTime(MAXIMUM_BACKOFF_MILLISECONDS * 2);
  });
  expect(daemonControl.connectionCount).toBe(1);
});

test('Strict Mode closes the first socket and keeps only the second live', async () => {
  vi.useFakeTimers({ toFake: [...FAKED_TIMERS] });
  const createdSockets: WebSocket[] = [];
  vi.stubGlobal(
    'WebSocket',
    class extends WebSocket {
      constructor(url: string | URL) {
        super(url);
        createdSockets.push(this);
      }
    },
  );
  const daemonControl = listenAsDaemon();
  const { result, unmount } = renderConnection(null, StrictMode);

  await vi.waitFor(() => expect(createdSockets[1]?.readyState).toBe(WebSocket.OPEN));
  await vi.waitFor(() => {
    daemonControl.sendToPage(snapshotExample);
    expect(result.current.connectionStatus).toBe('live');
  });

  expect(createdSockets).toHaveLength(2);
  expect(createdSockets[0]?.readyState).toBeGreaterThanOrEqual(WebSocket.CLOSING);
  expect(vi.getTimerCount()).toBe(1);
  unmount();
});

async function dropAndReadReconnectDelay(daemonControl: DaemonControl, readReconnectDelay: () => number | null): Promise<number> {
  const connectionCountBefore = daemonControl.connectionCount;
  daemonControl.closeFromDaemon();
  await vi.waitFor(() => expect(readReconnectDelay()).not.toBeNull());
  const delaySeconds = readReconnectDelay() ?? Number.NaN;
  act(() => {
    vi.advanceTimersByTime(MAXIMUM_BACKOFF_MILLISECONDS);
  });
  await vi.waitFor(() => expect(daemonControl.connectionCount).toBe(connectionCountBefore + 1));
  return delaySeconds;
}

test('the reconnect delay is full jitter: random times 0.5 s doubling per attempt, capped at the maximum', async () => {
  vi.useFakeTimers({ toFake: [...FAKED_TIMERS] });
  vi.spyOn(Math, 'random').mockReturnValue(0.5);
  const daemonControl = listenAsDaemon();
  const { result, unmount } = renderConnection();
  await vi.waitFor(() => expect(daemonControl.connectionCount).toBe(1));

  const delays: number[] = [];
  for (let attemptIndex = 0; attemptIndex < 7; attemptIndex += 1) {
    delays.push(await dropAndReadReconnectDelay(daemonControl, () => result.current.reconnectInSeconds));
  }

  expect(delays).toEqual([1, 1, 1, 2, 4, MAXIMUM_BACKOFF_SECONDS / 2, MAXIMUM_BACKOFF_SECONDS / 2]);
  unmount();
});

test('the first reconnect waits random times half a second', async () => {
  vi.useFakeTimers({ toFake: [...FAKED_TIMERS] });
  vi.spyOn(Math, 'random').mockReturnValue(1);
  const daemonControl = listenAsDaemon();
  const { result, unmount } = renderConnection();
  await vi.waitFor(() => expect(daemonControl.connectionCount).toBe(1));

  daemonControl.closeFromDaemon();
  await vi.waitFor(() => expect(result.current.connectionStatus).toBe('reconnecting'));
  act(() => {
    vi.advanceTimersByTime(300);
  });
  expect(daemonControl.connectionCount).toBe(1);
  act(() => {
    vi.advanceTimersByTime(300);
  });

  await vi.waitFor(() => expect(daemonControl.connectionCount).toBe(2));
  unmount();
});

test('a snapshot restarts the backoff at its first step', async () => {
  vi.useFakeTimers({ toFake: [...FAKED_TIMERS] });
  vi.spyOn(Math, 'random').mockReturnValue(1);
  const daemonControl = listenAsDaemon();
  const { result, unmount } = renderConnection();
  await vi.waitFor(() => expect(daemonControl.connectionCount).toBe(1));
  const readReconnectDelay = () => result.current.reconnectInSeconds;
  const delaysBeforeSnapshot = [
    await dropAndReadReconnectDelay(daemonControl, readReconnectDelay),
    await dropAndReadReconnectDelay(daemonControl, readReconnectDelay),
    await dropAndReadReconnectDelay(daemonControl, readReconnectDelay),
  ];
  expect(delaysBeforeSnapshot).toEqual([1, 1, 2]);

  await vi.waitFor(() => {
    daemonControl.sendToPage(snapshotExample);
    expect(result.current.connectionStatus).toBe('live');
  });

  expect(await dropAndReadReconnectDelay(daemonControl, readReconnectDelay)).toBe(1);
  unmount();
});

test('the reconnect countdown drops by one every second', async () => {
  vi.useFakeTimers({ toFake: [...FAKED_TIMERS] });
  vi.spyOn(Math, 'random').mockReturnValue(1);
  const daemonControl = listenAsDaemon();
  const { result, unmount } = renderConnection();
  await vi.waitFor(() => expect(daemonControl.connectionCount).toBe(1));
  const readReconnectDelay = () => result.current.reconnectInSeconds;
  for (let dropIndex = 0; dropIndex < 3; dropIndex += 1) {
    await dropAndReadReconnectDelay(daemonControl, readReconnectDelay);
  }

  daemonControl.closeFromDaemon();
  await vi.waitFor(() => expect(result.current.reconnectInSeconds).toBe(4));
  act(() => {
    vi.advanceTimersByTime(1000);
  });
  expect(result.current.reconnectInSeconds).toBe(3);
  act(() => {
    vi.advanceTimersByTime(1000);
  });

  expect(result.current.reconnectInSeconds).toBe(2);
  unmount();
});

test('send writes a client message to the daemon', async () => {
  const daemonControl = listenAsDaemon();
  const { result, unmount } = renderConnection('203');
  await waitFor(() => expect(daemonControl.connectionCount).toBe(1));

  await waitFor(() => {
    result.current.send({ action: 'pause', pause: 'lunch' });
    expect(daemonControl.clientMessages).toContainEqual({ action: 'pause', pause: 'lunch' });
  });
  unmount();
});
