import 'server-only';
import { pullResponseSchema } from '@/api/wireSchema';
import { getDaemonInternalUrl } from '@/config';
import { floorReducer, type FloorState } from '@/domain/floorReducer';

const PULL_TIMEOUT_MILLISECONDS = 2000;

export async function fetchFloorState(): Promise<FloorState | null> {
  const pullUrl = `${getDaemonInternalUrl()}/pull`;
  let response: Response;
  try {
    response = await fetch(pullUrl, { signal: AbortSignal.timeout(PULL_TIMEOUT_MILLISECONDS) });
  } catch (error) {
    console.warn(`The daemon did not answer ${pullUrl}; the page renders without a floor state (${String(error)})`);
    return null;
  }
  if (!response.ok) {
    console.warn(`The daemon answered ${pullUrl} with HTTP ${response.status}; the page renders without a floor state`);
    return null;
  }

  let responseBody: unknown;
  try {
    responseBody = await response.json();
  } catch (error) {
    console.error(`Dropped a pull response whose body could not be read as JSON (${String(error)})`);
    return null;
  }
  const parsed = pullResponseSchema.safeParse(responseBody);
  if (!parsed.success) {
    console.error('Dropped a pull response the schema rejects', parsed.error.issues);
    return null;
  }
  if (!parsed.data.full) {
    console.error('Dropped a pull response that is not the full floor');
    return null;
  }
  const { agents, queues, exchange_connected, sla_threshold_seconds } = parsed.data;
  return floorReducer(null, { action: 'snapshot', agents, queues, exchange_connected, sla_threshold_seconds });
}
