import type { Agent, Queue, ServerMessage } from '@/api/wireSchema';

export type FloorMessage = Extract<
  ServerMessage,
  { action: 'snapshot' | 'agentsChanged' | 'queuesChanged' | 'exchangeConnection' }
>;

export type FloorState = {
  agents: Record<string, Agent>;
  queues: Record<string, Queue>;
  exchangeConnected: boolean;
  slaThresholdSeconds: number;
};

export function floorReducer(state: FloorState | null, message: FloorMessage): FloorState | null {
  if (message.action === 'snapshot') {
    return {
      agents: Object.fromEntries(message.agents.map((agent) => [agent.id_agent, agent])),
      queues: Object.fromEntries(message.queues.map((queue) => [queue.name, queue])),
      exchangeConnected: message.exchange_connected,
      slaThresholdSeconds: message.sla_threshold_seconds,
    };
  }
  if (state === null) {
    return null;
  }
  switch (message.action) {
    case 'agentsChanged':
      return {
        ...state,
        agents: { ...state.agents, ...Object.fromEntries(message.agents.map((agent) => [agent.id_agent, agent])) },
      };
    case 'queuesChanged':
      return {
        ...state,
        queues: { ...state.queues, ...Object.fromEntries(message.queues.map((queue) => [queue.name, queue])) },
      };
    case 'exchangeConnection':
      return { ...state, exchangeConnected: message.connected };
  }
}

export function findAgentByExtension(floorState: FloorState, extension: string): Agent | undefined {
  return Object.values(floorState.agents).find((agent) => agent.exten === extension);
}
