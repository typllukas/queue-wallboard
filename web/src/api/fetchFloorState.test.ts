// @vitest-environment node
import { delay, http, HttpResponse } from 'msw';
import { expect, test, vi } from 'vitest';
import { fetchFloorState } from '@/api/fetchFloorState';
import { serverMessageSchema, type PullResponse } from '@/api/wireSchema';
import { readSnapshotExample } from '@/test/contractExample';
import { mswServer } from '@/test/mswServer';

const PULL_URL = 'http://localhost:8090/pull';

function readSnapshot() {
  const snapshot = serverMessageSchema.parse(readSnapshotExample());
  if (snapshot.action !== 'snapshot') {
    throw new Error('Expected a snapshot message.');
  }
  return snapshot;
}

function createFullPull() {
  const snapshot = readSnapshot();
  return {
    full: true,
    agents: snapshot.agents,
    queues: snapshot.queues,
    exchange_connected: snapshot.exchange_connected,
    sla_threshold_seconds: snapshot.sla_threshold_seconds,
  } satisfies PullResponse;
}

test('a full pull becomes the floor state, keyed by agent id and queue name', async () => {
  const snapshot = readSnapshot();
  const fullPullWithTheExchangeDown = { ...createFullPull(), exchange_connected: false } satisfies PullResponse;
  const requestedUrls: string[] = [];
  mswServer.use(
    http.get(PULL_URL, ({ request }) => {
      requestedUrls.push(request.url);
      return HttpResponse.json(fullPullWithTheExchangeDown);
    }),
  );

  const floorState = await fetchFloorState();

  expect(requestedUrls).toEqual([PULL_URL]);
  expect(Object.keys(floorState?.agents ?? {})).toEqual(['201', '203', '205']);
  expect(Object.values(floorState?.agents ?? {})).toEqual(snapshot.agents);
  expect(floorState?.agents['203']?.title).toBe('Petr Svoboda');
  expect(Object.keys(floorState?.queues ?? {})).toEqual(['sales', 'support']);
  expect(Object.values(floorState?.queues ?? {})).toEqual(snapshot.queues);
  expect(floorState?.exchangeConnected).toBe(false);
  expect(floorState?.slaThresholdSeconds).toBe(20);
});

test('a network error is logged as no answer and gives no floor state', async () => {
  const consoleWarn = vi.spyOn(console, 'warn').mockImplementation(() => {});
  mswServer.use(http.get(PULL_URL, () => HttpResponse.error()));

  expect(await fetchFloorState()).toBeNull();
  expect(consoleWarn).toHaveBeenCalledExactlyOnceWith(expect.stringContaining(`did not answer ${PULL_URL}`));
});

test('an error status is logged with the status and gives no floor state, even with a valid body', async () => {
  const consoleWarn = vi.spyOn(console, 'warn').mockImplementation(() => {});
  mswServer.use(http.get(PULL_URL, () => HttpResponse.json(createFullPull(), { status: 500 })));

  expect(await fetchFloorState()).toBeNull();
  expect(consoleWarn).toHaveBeenCalledExactlyOnceWith(expect.stringContaining('HTTP 500'));
});

test('a body that is not JSON is logged as unreadable and gives no floor state', async () => {
  const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
  mswServer.use(http.get(PULL_URL, () => new HttpResponse('<html>daemon</html>', { status: 200 })));

  expect(await fetchFloorState()).toBeNull();
  expect(consoleError).toHaveBeenCalledExactlyOnceWith(expect.stringContaining('could not be read as JSON'));
});

test('a body the schema rejects gives no floor state', async () => {
  const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
  const pullMissingItsAgents = { full: true, queues: [], exchange_connected: true };
  mswServer.use(http.get(PULL_URL, () => HttpResponse.json(pullMissingItsAgents)));

  expect(await fetchFloorState()).toBeNull();
  expect(consoleError).toHaveBeenCalledExactlyOnceWith('Dropped a pull response the schema rejects', expect.any(Array));
});

test('a pull that is not full gives no floor state, because a partial floor would render as the whole floor', async () => {
  const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
  mswServer.use(http.get(PULL_URL, () => HttpResponse.json({ ...createFullPull(), full: false } satisfies PullResponse)));

  expect(await fetchFloorState()).toBeNull();
  expect(consoleError).toHaveBeenCalledExactlyOnceWith('Dropped a pull response that is not the full floor');
});

test('a daemon that never answers gives no floor state instead of holding the render', async () => {
  const consoleWarn = vi.spyOn(console, 'warn').mockImplementation(() => {});
  mswServer.use(
    http.get(PULL_URL, async () => {
      await delay('infinite');
      return HttpResponse.json({});
    }),
  );

  expect(await fetchFloorState()).toBeNull();
  expect(consoleWarn).toHaveBeenCalledExactlyOnceWith(expect.stringContaining('TimeoutError'));
});
