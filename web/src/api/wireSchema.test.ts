import { expect, test } from 'vitest';
import { clientMessageSchema, pullResponseSchema, serverMessageSchema, type PullResponse } from '@/api/wireSchema';
import { readPullExample, readSnapshotExample } from '@/test/contractExample';

test('the contract example parses as a snapshot', () => {
  const result = serverMessageSchema.safeParse(readSnapshotExample());

  expect(result.success).toBe(true);
  if (!result.success || result.data.action !== 'snapshot') {
    throw new Error('Expected a snapshot message.');
  }
  expect(result.data.agents.map((agent) => agent.id_agent)).toEqual(['201', '203', '205']);
  expect(result.data.queues[1]?.waiting_calls).toHaveLength(2);
});

test('a snapshot whose agents lack the fields no page shows still parses', () => {
  const example = readSnapshotExample();
  if (typeof example !== 'object' || example === null || !('agents' in example) || !Array.isArray(example.agents)) {
    throw new Error('The example has no agents.');
  }
  const unshownFields = ['logintime', 'lastcalltime'];
  const firstAgent: unknown = example.agents[0];
  for (const field of unshownFields) {
    expect(firstAgent).toHaveProperty(field);
  }
  const agentsWithoutUnshownFields = example.agents.map((agent: unknown) => {
    if (typeof agent !== 'object' || agent === null) {
      throw new Error('The example has an agent that is not an object.');
    }
    return Object.fromEntries(Object.entries(agent).filter(([field]) => !unshownFields.includes(field)));
  });

  expect(serverMessageSchema.safeParse({ ...example, agents: agentsWithoutUnshownFields }).success).toBe(true);
});

test('the contract example with an unknown pause id is rejected', () => {
  const example = readSnapshotExample();
  if (typeof example !== 'object' || example === null || !('agents' in example) || !Array.isArray(example.agents)) {
    throw new Error('The example has no agents.');
  }
  const brokenExample = { ...example, agents: [{ ...example.agents[1], id_pause: 'coffee' }] };

  expect(serverMessageSchema.safeParse(brokenExample).success).toBe(false);
});

test.each([
  { action: 'exchangeConnection', connected: false },
  { action: 'error', message: 'Unknown agent 999' },
  { action: 'ping' },
  { action: 'agentsChanged', agents: [] },
  { action: 'queuesChanged', queues: [] },
])('the server message $action parses', (message) => {
  expect(serverMessageSchema.safeParse(message).success).toBe(true);
});

test.each([{ action: 'reboot' }, { action: 'exchangeConnection' }, { action: 'agentsChanged', agents: [{}] }, 'ping'])(
  'a malformed server message %j is rejected',
  (message) => {
    expect(serverMessageSchema.safeParse(message).success).toBe(false);
  },
);

test.each([{ action: 'pong' }, { action: 'unpause' }, { action: 'pause', pause: 'lunch' }, { action: 'pause', pause: 'training' }])(
  'the client message %j parses',
  (message) => {
    expect(clientMessageSchema.safeParse(message).success).toBe(true);
  },
);

test.each([{ action: 'pause', pause: 'inactive' }, { action: 'pause' }])(
  'the client message %j is rejected, since inactive is the daemon\'s own pause',
  (message) => {
    expect(clientMessageSchema.safeParse(message).success).toBe(false);
  },
);

test('the contract example parses as a pull response', () => {
  const result = pullResponseSchema.safeParse(readPullExample());

  expect(result.success).toBe(true);
  expect(result.data?.full).toBe(false);
  expect(result.data?.agents.map((agent) => agent.id_agent)).toEqual(['203']);
});

test('a full pull without the SLA threshold is rejected', () => {
  const snapshot = serverMessageSchema.parse(readSnapshotExample());
  if (snapshot.action !== 'snapshot') {
    throw new Error('Expected a snapshot message.');
  }
  const fullPullMissingItsSlaThreshold = { full: true, agents: snapshot.agents, queues: snapshot.queues, exchange_connected: true };

  expect(pullResponseSchema.safeParse({ ...fullPullMissingItsSlaThreshold, sla_threshold_seconds: 20 } satisfies PullResponse).success).toBe(true);
  expect(pullResponseSchema.safeParse(fullPullMissingItsSlaThreshold).success).toBe(false);
});

test('a pull response without its full flag is rejected', () => {
  expect(pullResponseSchema.safeParse({ agents: [], queues: [], exchange_connected: true }).success).toBe(false);
});
