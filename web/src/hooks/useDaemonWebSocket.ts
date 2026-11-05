import { useCallback, useEffect, useEffectEvent, useRef, useState } from 'react';
import { serverMessageSchema, type ClientMessage } from '@/api/wireSchema';
import { getDaemonWebsocketUrl } from '@/config';
import type { FloorMessage } from '@/domain/floorReducer';

export const WATCHDOG_MILLISECONDS = 10_000;
export const MAXIMUM_BACKOFF_SECONDS = 10;
const BASE_BACKOFF_SECONDS = 0.5;
const POLICY_VIOLATION_CLOSE_CODE = 1008;

export type ConnectionStatus = 'connecting' | 'live' | 'reconnecting' | 'refused';

type DaemonWebSocketOptions = {
  agentExtension: string | null;
  onMessage: (message: FloorMessage) => void;
};

function calculateBackoffSeconds(attempt: number): number {
  return Math.random() * Math.min(MAXIMUM_BACKOFF_SECONDS, BASE_BACKOFF_SECONDS * 2 ** attempt);
}

function sendToSocket(socket: WebSocket, message: ClientMessage) {
  if (socket.readyState === WebSocket.OPEN) {
    socket.send(JSON.stringify(message));
  }
}

function buildSocketUrl(agentExtension: string | null): string {
  const url = new URL(getDaemonWebsocketUrl());
  if (agentExtension !== null) {
    url.searchParams.set('agent', agentExtension);
  }
  return url.href;
}

export function useDaemonWebSocket({ agentExtension, onMessage }: DaemonWebSocketOptions) {
  const [connectionStatus, setConnectionStatus] = useState<ConnectionStatus>('connecting');
  const [reconnectInSeconds, setReconnectInSeconds] = useState<number | null>(null);
  const [lastError, setLastError] = useState<string | null>(null);
  const socketRef = useRef<WebSocket | null>(null);
  const handleMessage = useEffectEvent(onMessage);

  useEffect(() => {
    let attempt = 0;
    let watchdogTimeoutId: ReturnType<typeof setTimeout> | undefined;
    let reconnectTimeoutId: ReturnType<typeof setTimeout> | undefined;
    let countdownIntervalId: ReturnType<typeof setInterval> | undefined;

    const clearTimers = () => {
      clearTimeout(watchdogTimeoutId);
      clearTimeout(reconnectTimeoutId);
      clearInterval(countdownIntervalId);
    };

    const scheduleReconnect = () => {
      const delaySeconds = calculateBackoffSeconds(attempt);
      attempt += 1;
      setConnectionStatus('reconnecting');
      setReconnectInSeconds(Math.ceil(delaySeconds));
      countdownIntervalId = setInterval(() => {
        setReconnectInSeconds((remainingSeconds) => (remainingSeconds === null ? null : Math.max(0, remainingSeconds - 1)));
      }, 1000);
      reconnectTimeoutId = setTimeout(() => {
        clearInterval(countdownIntervalId);
        setReconnectInSeconds(null);
        connect();
      }, delaySeconds * 1000);
    };

    const abandonSocket = (socket: WebSocket) => {
      socket.onclose = null;
      socket.onmessage = null;
      socket.close();
      clearTimers();
      scheduleReconnect();
    };

    const connect = () => {
      const socket = new WebSocket(buildSocketUrl(agentExtension));
      socketRef.current = socket;
      const restartWatchdog = () => {
        clearTimeout(watchdogTimeoutId);
        watchdogTimeoutId = setTimeout(() => {
          abandonSocket(socket);
        }, WATCHDOG_MILLISECONDS);
      };
      restartWatchdog();

      socket.onmessage = (event) => {
        const parsed = serverMessageSchema.safeParse(parseJson(event.data));
        if (!parsed.success) {
          console.error('Dropped a daemon message the schema rejects', parsed.error.issues);
          return;
        }
        const message = parsed.data;
        switch (message.action) {
          case 'ping':
            restartWatchdog();
            sendToSocket(socket, { action: 'pong' });
            return;
          case 'error':
            setLastError(message.message);
            return;
          case 'snapshot':
            attempt = 0;
            setConnectionStatus('live');
            handleMessage(message);
            return;
          case 'agentsChanged':
          case 'queuesChanged':
          case 'exchangeConnection':
            handleMessage(message);
            return;
        }
      };

      socket.onclose = (event) => {
        clearTimers();
        if (event.code === POLICY_VIOLATION_CLOSE_CODE) {
          setConnectionStatus('refused');
          return;
        }
        scheduleReconnect();
      };
    };

    connect();

    return () => {
      clearTimers();
      const socket = socketRef.current;
      socketRef.current = null;
      if (socket !== null) {
        socket.onclose = null;
        socket.onmessage = null;
        socket.close();
      }
    };
  }, [agentExtension]);

  const send = useCallback((message: ClientMessage) => {
    if (socketRef.current !== null) {
      sendToSocket(socketRef.current, message);
    }
  }, []);

  const clearError = useCallback(() => {
    setLastError(null);
  }, []);

  return { connectionStatus, reconnectInSeconds, send, lastError, clearError };
}

function parseJson(text: unknown): unknown {
  if (typeof text !== 'string') {
    return text;
  }
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}
