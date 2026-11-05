import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { afterEach, expect, test, vi } from 'vitest';
import { SCENARIO_POLL_MILLISECONDS, ScenarioButtons } from '@/components/demo/ScenarioButtons';
import { mswServer } from '@/test/mswServer';

const postedPaths: string[] = [];
const CONTROL_URL = process.env.NEXT_PUBLIC_SIMULATOR_CONTROL_URL;

afterEach(() => {
  postedPaths.length = 0;
  vi.useRealTimers();
});

type ControlPort = { runningScenario: string };

function runControlPort(runningScenario: string): ControlPort {
  const controlPort = { runningScenario };
  mswServer.use(
    http.get(`${CONTROL_URL}/scenario`, () => HttpResponse.json({ scenario: controlPort.runningScenario })),
    http.post(`${CONTROL_URL}/scenario/:scenarioId`, ({ request, params }) => {
      postedPaths.push(new URL(request.url).pathname);
      controlPort.runningScenario = String(params.scenarioId);
      return new HttpResponse(null, { status: 204 });
    }),
  );
  return controlPort;
}

test('on load the button of the scenario the simulator runs is marked', async () => {
  runControlPort('normal');
  render(<ScenarioButtons />);

  expect(await screen.findByRole('button', { name: 'Normal', pressed: true })).toBeVisible();
  expect(screen.getByText('Watch Queues stats: now and then callers queue up, so Waiting turns orange, and Available turns red while every agent is busy.')).toBeVisible();
  expect(postedPaths).toEqual([]);
});

test('the mark follows the simulator when its scenario changes without a click', async () => {
  vi.useFakeTimers({ shouldAdvanceTime: true });
  const controlPort = runControlPort('peak');
  render(<ScenarioButtons />);
  await screen.findByRole('button', { name: 'Peak', pressed: true });

  controlPort.runningScenario = 'normal';
  await vi.advanceTimersByTimeAsync(SCENARIO_POLL_MILLISECONDS);

  expect(await screen.findByRole('button', { name: 'Normal', pressed: true })).toBeVisible();
  expect(screen.getByRole('button', { name: 'Peak', pressed: false })).toBeVisible();
});

test('clicking Peak posts to the peak scenario and marks Peak active', async () => {
  runControlPort('normal');
  const user = userEvent.setup();
  render(<ScenarioButtons />);
  await screen.findByRole('button', { name: 'Normal', pressed: true });

  await user.click(screen.getByRole('button', { name: 'Peak' }));

  expect(await screen.findByRole('button', { name: 'Peak', pressed: true })).toBeVisible();
  expect(postedPaths).toEqual(['/scenario/peak']);
  expect(screen.getByRole('button', { name: 'Normal', pressed: false })).toBeVisible();
  expect(screen.getByText(/within about a minute Support's Waiting turns red and keeps climbing/)).toBeVisible();
});

test('a control port that does not answer marks nothing and asks to pick a scenario', async () => {
  mswServer.use(http.get(`${CONTROL_URL}/scenario`, () => HttpResponse.error()));
  render(<ScenarioButtons />);

  expect(await screen.findByText('Pick a scenario.')).toBeVisible();
  expect(screen.queryByRole('button', { pressed: true })).toBeNull();
});

test('a scenario answer the schema rejects marks nothing', async () => {
  mswServer.use(http.get(`${CONTROL_URL}/scenario`, () => HttpResponse.json({ scenario: 'stormy' })));
  vi.spyOn(console, 'error').mockImplementation(() => undefined);
  render(<ScenarioButtons />);

  await vi.waitFor(() => expect(console.error).toHaveBeenCalled());
  expect(screen.queryByRole('button', { pressed: true })).toBeNull();
});

test('a refused post shows an error and leaves the running scenario marked', async () => {
  runControlPort('normal');
  const user = userEvent.setup();
  render(<ScenarioButtons />);
  await screen.findByRole('button', { name: 'Normal', pressed: true });

  mswServer.use(http.post(`${CONTROL_URL}/scenario/:scenarioId`, () => new HttpResponse(null, { status: 404 })));
  await user.click(screen.getByRole('button', { name: 'Peak' }));

  expect(await screen.findByRole('alert')).toHaveTextContent(/could not switch to peak/i);
  expect(screen.getByRole('button', { name: 'Normal', pressed: true })).toBeVisible();
  expect(screen.getByRole('button', { name: 'Peak', pressed: false })).toBeVisible();
});

test('an unreachable simulator shows an error and marks nothing', async () => {
  mswServer.use(
    http.get(`${CONTROL_URL}/scenario`, () => HttpResponse.error()),
    http.post(`${CONTROL_URL}/scenario/:scenarioId`, () => HttpResponse.error()),
  );
  const user = userEvent.setup();
  render(<ScenarioButtons />);

  await user.click(screen.getByRole('button', { name: 'Quiet' }));

  expect(await screen.findByRole('alert')).toHaveTextContent(/could not switch to quiet/i);
  expect(screen.queryByRole('button', { pressed: true })).toBeNull();
});

test('a later successful click clears the error', async () => {
  mswServer.use(
    http.get(`${CONTROL_URL}/scenario`, () => HttpResponse.error()),
    http.post(`${CONTROL_URL}/scenario/:scenarioId`, () => HttpResponse.error()),
  );
  const user = userEvent.setup();
  render(<ScenarioButtons />);
  await user.click(screen.getByRole('button', { name: 'Quiet' }));
  await screen.findByRole('alert');

  runControlPort('normal');
  await user.click(screen.getByRole('button', { name: 'Quiet' }));

  expect(await screen.findByRole('button', { name: 'Quiet', pressed: true })).toBeVisible();
  expect(screen.queryByRole('alert')).toBeNull();
});
