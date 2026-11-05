import type { ConnectionStatus } from '@/hooks/useDaemonWebSocket';

type MissingFloorStateProps = {
  connectionStatus: ConnectionStatus;
};

export function MissingFloorState({ connectionStatus }: MissingFloorStateProps) {
  return <p>{connectionStatus === 'reconnecting' ? 'The server is not answering.' : 'Connecting…'}</p>;
}
