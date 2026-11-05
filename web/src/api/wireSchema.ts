import { z } from 'zod';

export const PAUSE_REASONS = ['lunch', 'training', 'inactive', 'other'] as const;
const pauseReasonSchema = z.enum(PAUSE_REASONS);
export const requestablePauseReasonSchema = pauseReasonSchema.exclude(['inactive', 'other']);

const timestampSchema = z.iso.datetime({ offset: true });

const agentSchema = z.object({
  id_agent: z.string(),
  title: z.string(),
  exten: z.string(),
  state: z.enum(['Session', 'Idle', 'Paused']),
  exten_status: z.enum(['Idle', 'Busy', 'Unavailable']),
  id_pause: pauseReasonSchema.nullable(),
  onpause: timestampSchema.nullable(),
  statetime: timestampSchema,
  queues: z.array(z.string()),
});

const queueSchema = z.object({
  name: z.string(),
  title: z.string(),
  threshold_calls: z.number(),
  threshold_users: z.number(),
  threshold_time: z.number(),
  ongoing: z.number(),
  available: z.number(),
  users_on_pause: z.record(pauseReasonSchema, z.number()),
  answered: z.number(),
  unanswered: z.number(),
  total: z.number(),
  counted_since: timestampSchema,
  abandon_rate: z.number().nullable(),
  sla: z.number().nullable(),
  svl: z.number().nullable(),
  waiting_calls: z.array(z.object({ id: z.string(), waiting_since: timestampSchema })),
});

export const serverMessageSchema = z.discriminatedUnion('action', [
  z.object({
    action: z.literal('snapshot'),
    agents: z.array(agentSchema),
    queues: z.array(queueSchema),
    exchange_connected: z.boolean(),
    sla_threshold_seconds: z.number(),
  }),
  z.object({ action: z.literal('agentsChanged'), agents: z.array(agentSchema) }),
  z.object({ action: z.literal('queuesChanged'), queues: z.array(queueSchema) }),
  z.object({ action: z.literal('exchangeConnection'), connected: z.boolean() }),
  z.object({ action: z.literal('error'), message: z.string() }),
  z.object({ action: z.literal('ping') }),
]);

const pullSchema = z.object({
  agents: z.array(agentSchema),
  queues: z.array(queueSchema),
  exchange_connected: z.boolean(),
});

export const pullResponseSchema = z.discriminatedUnion('full', [
  pullSchema.extend({ full: z.literal(true), sla_threshold_seconds: z.number() }),
  pullSchema.extend({ full: z.literal(false) }),
]);

export const clientMessageSchema = z.discriminatedUnion('action', [
  z.object({ action: z.literal('pong') }),
  z.object({ action: z.literal('pause'), pause: requestablePauseReasonSchema }),
  z.object({ action: z.literal('unpause') }),
]);

export type RequestablePauseReason = z.infer<typeof requestablePauseReasonSchema>;
export type PauseReason = (typeof PAUSE_REASONS)[number];
export type Agent = z.infer<typeof agentSchema>;
export type Queue = z.infer<typeof queueSchema>;
export type ServerMessage = z.infer<typeof serverMessageSchema>;
export type PullResponse = z.infer<typeof pullResponseSchema>;
export type ClientMessage = z.infer<typeof clientMessageSchema>;
