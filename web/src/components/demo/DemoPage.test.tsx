import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, expect, test } from 'vitest';
import { DemoPage, hasWebSocket } from '@/components/demo/DemoPage';
import { mswServer } from '@/test/mswServer';

beforeEach(() => {
  mswServer.use(http.get(`${process.env.NEXT_PUBLIC_SIMULATOR_CONTROL_URL}/scenario`, () => HttpResponse.json({ scenario: 'normal' })));
});

function findAgentFrameWindow(): (Window & { WebSocket: typeof WebSocket }) | null {
  const frameWindow = screen.getByTitle<HTMLIFrameElement>('Agent 203 page').contentWindow;
  return frameWindow !== null && hasWebSocket(frameWindow) ? frameWindow : null;
}

function readSocketSend(frameWindow: (Window & { WebSocket: typeof WebSocket }) | null): unknown {
  return frameWindow === null ? undefined : Reflect.get(frameWindow.WebSocket.prototype, 'send');
}

function readFrameSources(): (string | null)[] {
  return screen.getAllByTitle(/wallboard|agent/i).map((frame) => frame.getAttribute('src'));
}

test('shows the wallboard and the pages of agents 201 and 203 as titled frames', () => {
  render(<DemoPage />);

  expect(readFrameSources()).toEqual(['/wallboard', '/agent/201', '/agent/203']);
});

test("closing Lucie's page removes its frame and reopening restores it", async () => {
  const user = userEvent.setup();
  render(<DemoPage />);

  await user.click(screen.getByRole('button', { name: "Close Lucie's page" }));

  expect(readFrameSources()).toEqual(['/wallboard', '/agent/201']);
  expect(screen.getByRole('button', { name: "Freeze Lucie's page" })).toBeDisabled();

  await user.click(screen.getByRole('button', { name: "Reopen Lucie's page" }));

  expect(readFrameSources()).toEqual(['/wallboard', '/agent/201', '/agent/203']);
  expect(screen.getByRole('button', { name: "Freeze Lucie's page" })).toBeEnabled();
});

test("freezing Lucie's page silences its sockets and unfreezing loads the page again", async () => {
  const user = userEvent.setup();
  render(<DemoPage />);
  const frozenFrameWindow = findAgentFrameWindow();
  const nativeSend = readSocketSend(frozenFrameWindow);

  await user.click(screen.getByRole('button', { name: "Freeze Lucie's page" }));
  const silencedSend = readSocketSend(frozenFrameWindow);

  expect(silencedSend).not.toBe(nativeSend);
  expect(screen.getByRole('status', { name: "Lucie's page is frozen" })).toHaveTextContent('No connection');

  await user.click(screen.getByRole('button', { name: "Unfreeze (reload) Lucie's page" }));

  expect(findAgentFrameWindow()).not.toBe(frozenFrameWindow);
  expect(readSocketSend(findAgentFrameWindow())).not.toBe(silencedSend);
  expect(screen.queryByRole('status', { name: "Lucie's page is frozen" })).toBeNull();
  expect(screen.getByRole('button', { name: "Freeze Lucie's page" })).toBeEnabled();
});

test('the outage button asks the simulator to drop the exchange connection', async () => {
  const outageRequestPaths: string[] = [];
  mswServer.use(
    http.post(`${process.env.NEXT_PUBLIC_SIMULATOR_CONTROL_URL}/exchange-outage`, ({ request }) => {
      outageRequestPaths.push(new URL(request.url).pathname);
      return new HttpResponse(null, { status: 204 });
    }),
  );
  const user = userEvent.setup();
  render(<DemoPage />);

  await user.click(screen.getByRole('button', { name: 'Drop the PBX connection' }));

  expect(await screen.findByText(/^Dropped at \d{2}:\d{2}:\d{2}\. The daemon reconnects about 8 s later\.$/)).toBeVisible();
  expect(outageRequestPaths).toEqual(['/exchange-outage']);
  expect(screen.queryByRole('alert')).toBeNull();
});

test('the outage button says so when the simulator does not answer', async () => {
  mswServer.use(http.post(`${process.env.NEXT_PUBLIC_SIMULATOR_CONTROL_URL}/exchange-outage`, () => new HttpResponse(null, { status: 500 })));
  const user = userEvent.setup();
  render(<DemoPage />);

  await user.click(screen.getByRole('button', { name: 'Drop the PBX connection' }));

  expect(await screen.findByRole('alert')).toHaveTextContent('Could not reach the simulator. Is it running?');
  expect(screen.queryByText(/Dropped/)).toBeNull();
});
