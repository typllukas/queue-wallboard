import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
      'server-only': fileURLToPath(new URL('./src/test/serverOnly.ts', import.meta.url)),
    },
  },
  test: {
    environment: 'jsdom',
    restoreMocks: true,
    env: { TZ: 'Europe/Prague', NEXT_PUBLIC_DAEMON_WEBSOCKET_URL: 'ws://localhost:8090/websocket', DAEMON_INTERNAL_URL: 'http://localhost:8090', NEXT_PUBLIC_SIMULATOR_CONTROL_URL: 'http://localhost:5039' },
    setupFiles: ['./vitest.setup.ts'],
  },
});
