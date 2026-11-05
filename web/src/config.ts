function requireEnvironmentValue(name: string, value: string | undefined): string {
  if (value === undefined || value === '') {
    throw new Error(`Environment variable ${name} is not set.`);
  }
  return value;
}

export function getDaemonWebsocketUrl(): string {
  return requireEnvironmentValue('NEXT_PUBLIC_DAEMON_WEBSOCKET_URL', process.env.NEXT_PUBLIC_DAEMON_WEBSOCKET_URL);
}

export function getSimulatorControlUrl(): string {
  return requireEnvironmentValue('NEXT_PUBLIC_SIMULATOR_CONTROL_URL', process.env.NEXT_PUBLIC_SIMULATOR_CONTROL_URL);
}

export function getDaemonInternalUrl(): string {
  return requireEnvironmentValue('DAEMON_INTERNAL_URL', process.env.DAEMON_INTERNAL_URL);
}
