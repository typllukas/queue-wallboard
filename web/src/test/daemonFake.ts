import { ws, type WebSocketHandlerConnection } from 'msw';
import { clientMessageSchema, type ClientMessage, type ServerMessage } from '@/api/wireSchema';
import { mswServer } from '@/test/mswServer';

const daemon = ws.link('ws://localhost:8090/websocket');

export type DaemonControl = {
  clientMessages: ClientMessage[];
  connectedUrls: string[];
  connectionCount: number;
  closedCount: number;
  sendToPage: (message: ServerMessage) => void;
  sendMalformedToPage: (payload: unknown) => void;
  closeFromDaemon: (closeCode?: number) => void;
};

export function listenAsDaemon(): DaemonControl {
  const clients = new Set<WebSocketHandlerConnection['client']>();
  const sendToClients = (payload: unknown) => {
    for (const client of clients) {
      client.send(JSON.stringify(payload));
    }
  };
  const daemonControl: DaemonControl = {
    clientMessages: [],
    connectedUrls: [],
    connectionCount: 0,
    closedCount: 0,
    sendToPage: sendToClients,
    sendMalformedToPage: sendToClients,
    closeFromDaemon: (closeCode) => {
      for (const client of clients) {
        client.close(closeCode);
      }
    },
  };
  mswServer.use(
    daemon.addEventListener('connection', ({ client }) => {
      daemonControl.connectionCount += 1;
      daemonControl.connectedUrls.push(client.url.href);
      clients.add(client);
      client.addEventListener('message', (event) => {
        if (typeof event.data === 'string') {
          daemonControl.clientMessages.push(clientMessageSchema.parse(JSON.parse(event.data)));
        }
      });
      client.addEventListener('close', () => {
        daemonControl.closedCount += 1;
        clients.delete(client);
      });
    }),
  );
  return daemonControl;
}
