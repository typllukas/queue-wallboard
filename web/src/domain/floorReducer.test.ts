import { expect, test } from 'vitest';
import { serverMessageSchema, type Agent, type Queue } from '@/api/wireSchema';
import { floorReducer, type FloorMessage, type FloorState } from '@/domain/floorReducer';
import { readSnapshotExample } from '@/test/contractExample';

const idleAgent = {
  id_agent: '201',
  title: 'Jana Nováková',
  exten: '201',
  state: 'Idle',
  exten_status: 'Idle',
  id_pause: null,
  onpause: null,
  statetime: '2026-11-03T09:30:00+01:00',
  queues: ['sales'],
} satisfies Agent;

const pausedAgent = {
  ...idleAgent,
  id_agent: '203',
  exten: '203',
  title: 'Petr Svoboda',
  state: 'Paused',
  id_pause: 'lunch',
  onpause: '2026-11-03T09:50:00+01:00',
} satisfies Agent;

const salesQueue = {
  name: 'sales',
  title: 'Sales',
  threshold_calls: 5,
  threshold_users: 1,
  threshold_time: 60,
  ongoing: 0,
  available: 1,
  users_on_pause: { lunch: 1, training: 0, inactive: 0, other: 0 },
  answered: 0,
  unanswered: 0,
  total: 0,
  counted_since: '2026-11-04T00:00:00+01:00',
  abandon_rate: null,
  sla: null,
  svl: null,
  waiting_calls: [],
} satisfies Queue;

const initialState = {
  agents: { '201': idleAgent, '203': pausedAgent },
  queues: { sales: salesQueue },
  exchangeConnected: true,
  slaThresholdSeconds: 20,
} satisfies FloorState;

test('agentsChanged replaces agent 203 whole and leaves agent 201 untouched', () => {
  const resumedAgent = { ...pausedAgent, state: 'Idle', id_pause: null, onpause: null } satisfies Agent;

  const state = floorReducer(initialState, { action: 'agentsChanged', agents: [resumedAgent] });

  expect(state).toEqual({ ...initialState, agents: { '201': idleAgent, '203': resumedAgent } });
  expect(state?.agents['201']).toBe(idleAgent);
});

test('queuesChanged replaces the queue by name', () => {
  const busierQueue = { ...salesQueue, available: 0 } satisfies Queue;

  const state = floorReducer(initialState, { action: 'queuesChanged', queues: [busierQueue] });

  expect(state?.queues).toEqual({ sales: busierQueue });
});

test('exchangeConnection flips the flag and nothing else', () => {
  const state = floorReducer(initialState, { action: 'exchangeConnection', connected: false });

  expect(state).toEqual({ ...initialState, exchangeConnected: false });
});

test('a snapshot after changes replaces everything, including agents that are gone', () => {
  const snapshot = serverMessageSchema.parse(readSnapshotExample());
  if (snapshot.action !== 'snapshot') {
    throw new Error('Expected a snapshot message.');
  }

  const state = floorReducer(initialState, snapshot);

  expect(Object.keys(state?.agents ?? {})).toEqual(['201', '203', '205']);
  expect(Object.keys(state?.queues ?? {})).toEqual(['sales', 'support']);
  expect(state?.agents['203']).toEqual(snapshot.agents[1]);
});

test('a snapshot replaces the SLA threshold and a change message keeps it', () => {
  const stateAfterSnapshot = floorReducer(initialState, { action: 'snapshot', agents: [], queues: [], exchange_connected: true, sla_threshold_seconds: 30 });
  const stateAfterChange = floorReducer(stateAfterSnapshot, { action: 'queuesChanged', queues: [salesQueue] });

  expect(stateAfterSnapshot?.slaThresholdSeconds).toBe(30);
  expect(stateAfterChange?.slaThresholdSeconds).toBe(30);
});

test('a snapshot creates the state from nothing', () => {
  const state = floorReducer(null, { action: 'snapshot', agents: [idleAgent], queues: [], exchange_connected: false, sla_threshold_seconds: 20 });

  expect(state).toEqual({ agents: { '201': idleAgent }, queues: {}, exchangeConnected: false, slaThresholdSeconds: 20 });
});

test.each([
  { action: 'agentsChanged', agents: [idleAgent] },
  { action: 'queuesChanged', queues: [salesQueue] },
  { action: 'exchangeConnection', connected: true },
] satisfies FloorMessage[])('a $action message before any snapshot changes nothing', (message) => {
  expect(floorReducer(null, message)).toBeNull();
});
