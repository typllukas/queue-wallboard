import type { ConnectionStatus } from '@/hooks/useDaemonWebSocket';
import styles from './LiveIndicator.module.css';

type LiveIndicatorProps = {
  connectionStatus: ConnectionStatus;
  reconnectInSeconds: number | null;
};

function describeConnection(connectionStatus: ConnectionStatus, reconnectInSeconds: number | null): string {
  switch (connectionStatus) {
    case 'live':
      return '● Live';
    case 'reconnecting':
      return `Reconnecting in ${reconnectInSeconds ?? 0} s`;
    case 'connecting':
      return 'Connecting…';
    case 'refused':
      return 'Not connected';
  }
}

export function LiveIndicator({ connectionStatus, reconnectInSeconds }: LiveIndicatorProps) {
  return (
    <span role="status" aria-label="Connection" className={styles.indicator} data-status={connectionStatus}>
      {describeConnection(connectionStatus, reconnectInSeconds)}
    </span>
  );
}
