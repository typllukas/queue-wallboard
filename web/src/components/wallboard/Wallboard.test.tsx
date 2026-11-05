import { act, render, screen, within } from '@testing-library/react';
import { renderToString } from 'react-dom/server';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { serverMessageSchema, type Queue } from '@/api/wireSchema';
import type { FloorState } from '@/domain/floorReducer';
import { Wallboard } from '@/components/wallboard/Wallboard';
import { formatSecondsAgo, NOW } from '@/test/clock';
import { readSnapshotExample } from '@/test/contractExample';
import { listenAsDaemon } from '@/test/daemonFake';

const snapshotExample = serverMessageSchema.parse(readSnapshotExample());

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] });
  vi.setSystemTime(NOW);
});

afterEach(() => {
  vi.useRealTimers();
});

const quietSalesQueue = {
  name: 'sales',
  title: 'Sales',
  threshold_calls: 4,
  threshold_users: 1,
  threshold_time: 60,
  ongoing: 2,
  available: 1,
  users_on_pause: { lunch: 1, training: 0, inactive: 0, other: 0 },
  answered: 46,
  unanswered: 3,
  total: 49,
  counted_since: '2026-11-04T00:00:00+01:00',
  abandon_rate: 0.06,
  sla: 0.87,
  svl: 0.91,
  waiting_calls: [{ id: 'sales-1', waiting_since: formatSecondsAgo(48) }],
} satisfies Queue;

const overloadedSupportQueue = {
  ...quietSalesQueue,
  name: 'support',
  title: 'Support',
  threshold_calls: 3,
  threshold_time: 60,
  ongoing: 3,
  available: 0,
  users_on_pause: { lunch: 1, training: 2, inactive: 0, other: 3 },
  answered: 31,
  unanswered: 9,
  total: 40,
  abandon_rate: 0.23,
  sla: 0.62,
  svl: 0.7,
  waiting_calls: [
    { id: 'support-3', waiting_since: formatSecondsAgo(41) },
    { id: 'support-1', waiting_since: formatSecondsAgo(112) },
    { id: 'support-4', waiting_since: formatSecondsAgo(15) },
    { id: 'support-2', waiting_since: formatSecondsAgo(80) },
    { id: 'support-5', waiting_since: formatSecondsAgo(5) },
  ],
} satisfies Queue;

const busyFloor = {
  agents: {},
  queues: { sales: quietSalesQueue, support: overloadedSupportQueue },
  exchangeConnected: true,
  slaThresholdSeconds: 20,
} satisfies FloorState;

function findWidget(name: string): HTMLElement {
  return screen.getByRole('region', { name });
}

function findCell(widgetName: string, rowName: RegExp, columnName: string): HTMLElement {
  const widget = findWidget(widgetName);
  const columnIndex = within(widget)
    .getAllByRole('columnheader')
    .findIndex((header) => header.textContent === columnName);
  if (columnIndex === -1) {
    throw new Error(`No column ${columnName} in ${widgetName}`);
  }
  const cell = within(within(widget).getByRole('row', { name: rowName })).getAllByRole('cell')[columnIndex - 1];
  if (cell === undefined) {
    throw new Error(`No cell in column ${columnName}`);
  }
  return cell;
}

test('a queue over its thresholds turns its waiting count, available count and name red', () => {
  listenAsDaemon();
  render(<Wallboard initialState={busyFloor} />);

  const supportRow = within(findWidget('Queues stats')).getByRole('row', { name: /^Support/ });
  expect(findCell('Queues stats', /^Support/, 'Waiting')).toHaveTextContent('5');
  expect(findCell('Queues stats', /^Support/, 'Waiting')).toHaveAttribute('data-colour', 'red');
  expect(findCell('Queues stats', /^Support/, 'Available')).toHaveTextContent('0');
  expect(findCell('Queues stats', /^Support/, 'Available')).toHaveAttribute('data-colour', 'red');
  expect(within(supportRow).getByRole('rowheader')).toHaveAttribute('data-colour', 'red');
  expect(findCell('Queues stats', /^Support/, 'ABN')).toHaveTextContent('23 %');

  expect(findCell('Queues stats', /^Sales/, 'Waiting')).toHaveAttribute('data-colour', 'green');
  expect(findCell('Queues stats', /^Sales/, 'Available')).toHaveAttribute('data-colour', 'green');
  expect(within(within(findWidget('Queues stats')).getByRole('row', { name: /^Sales/ })).getByRole('rowheader')).toHaveAttribute(
    'data-colour',
    'green',
  );
});

test('service availability says since when it counts once the daemon has restarted during the day', () => {
  listenAsDaemon();
  const salesAfterRestart = { ...quietSalesQueue, counted_since: '2026-11-04T10:21:26+01:00' } satisfies Queue;
  render(<Wallboard initialState={{ ...busyFloor, queues: { sales: salesAfterRestart, support: { ...overloadedSupportQueue, counted_since: '2026-11-04T10:21:26+01:00' } } }} />);

  expect(findWidget('Service availability')).toHaveTextContent('since 10:21 · SLA threshold 20 s');
});

test('service availability names the SLA threshold of the floor, which no queue carries', () => {
  listenAsDaemon();
  render(<Wallboard initialState={{ ...busyFloor, slaThresholdSeconds: 30 }} />);

  expect(findWidget('Service availability')).toHaveTextContent('since midnight · SLA threshold 30 s');
});

test('service availability names the SLA threshold even before the floor has a queue', () => {
  listenAsDaemon();
  render(<Wallboard initialState={{ ...busyFloor, queues: {} }} />);

  expect(findWidget('Service availability')).toHaveTextContent('SLA threshold 20 s');
  expect(findWidget('Service availability')).not.toHaveTextContent('since');
});

test('service availability shows a dash for a queue with no finished calls and percentages otherwise', () => {
  listenAsDaemon();
  const salesWithoutFinishedCalls = { ...quietSalesQueue, sla: null, svl: null, abandon_rate: null, answered: 0, unanswered: 0, total: 0 } satisfies Queue;
  render(<Wallboard initialState={{ ...busyFloor, queues: { sales: salesWithoutFinishedCalls, support: overloadedSupportQueue } }} />);

  const widget = findWidget('Service availability');
  expect(widget).toHaveTextContent('since midnight · SLA threshold 20 s');
  const salesGroup = within(widget).getByRole('group', { name: 'Sales' });
  expect(within(salesGroup).getByRole('group', { name: 'SLA' })).toHaveTextContent('–');
  expect(within(salesGroup).getByRole('group', { name: 'SLA' })).not.toHaveAttribute('data-colour');
  expect(within(salesGroup).getByRole('group', { name: 'SVL' })).toHaveTextContent('–');
  const supportGroup = within(widget).getByRole('group', { name: 'Support' });
  expect(within(supportGroup).getByRole('group', { name: 'SLA' })).toHaveTextContent('62 %');
  expect(within(supportGroup).getByRole('group', { name: 'SLA' })).toHaveAttribute('data-colour', 'red');
  expect(within(supportGroup).getByRole('group', { name: 'SVL' })).toHaveTextContent('70 %');
  expect(findCell('Queues stats', /^Sales/, 'ABN')).toHaveTextContent('–');
});

test('waiting activities list waiting calls longest first and turn red past the queue threshold time', () => {
  listenAsDaemon();
  render(<Wallboard initialState={busyFloor} />);

  const listedCalls = within(findWidget('Waiting activities')).getAllByRole('listitem');
  expect(listedCalls.map((listedCall) => listedCall.textContent)).toEqual([
    'Support1:52',
    'Support1:20',
    'Sales0:48',
    'Support0:41',
    'Support0:15',
    'Support0:05',
  ]);
  expect(listedCalls.map((listedCall) => listedCall.getAttribute('data-colour'))).toEqual(['red', 'red', null, null, null, null]);
});

test('waiting activities show the six longest waits and count the rest, so the Pauses widget stays on screen', () => {
  listenAsDaemon();
  const newerSupportCalls = [3, 2, 1].map((seconds) => ({ id: `support-new-${seconds}`, waiting_since: formatSecondsAgo(seconds) }));
  const crowdedSupportQueue = { ...overloadedSupportQueue, waiting_calls: [...overloadedSupportQueue.waiting_calls, ...newerSupportCalls] } satisfies Queue;
  render(<Wallboard initialState={{ ...busyFloor, queues: { sales: quietSalesQueue, support: crowdedSupportQueue } }} />);

  const widget = findWidget('Waiting activities');
  expect(within(widget).getAllByRole('listitem').map((listedCall) => listedCall.textContent)).toEqual([
    'Support1:52',
    'Support1:20',
    'Sales0:48',
    'Support0:41',
    'Support0:15',
    'Support0:05',
  ]);
  expect(within(widget).getByText('and 3 more waiting')).toBeVisible();
});

test('waiting times tick every second and a call turns red once it passes the threshold time', () => {
  listenAsDaemon();
  render(<Wallboard initialState={busyFloor} />);
  const findSalesCall = () => within(findWidget('Waiting activities')).getByText(/^Sales/).closest('li');

  expect(findSalesCall()).toHaveTextContent('0:48');
  expect(findSalesCall()).not.toHaveAttribute('data-colour');

  act(() => {
    vi.advanceTimersByTime(13_000);
  });

  expect(findSalesCall()).toHaveTextContent('1:01');
  expect(findSalesCall()).toHaveAttribute('data-colour', 'red');
});

test('pauses list the users on each pause reason per queue', () => {
  listenAsDaemon();
  render(<Wallboard initialState={busyFloor} />);

  expect(findCell('Pauses', /^Support/, 'Lunch')).toHaveTextContent('1');
  expect(findCell('Pauses', /^Support/, 'Training')).toHaveTextContent('2');
  expect(findCell('Pauses', /^Support/, 'Inactive')).toHaveTextContent('0');
  expect(findCell('Pauses', /^Support/, 'Other')).toHaveTextContent('3');
  expect(findCell('Pauses', /^Sales/, 'Training')).toHaveTextContent('0');
});

test('without a state it says it is connecting and shows the widgets once the snapshot arrives', async () => {
  const daemonControl = listenAsDaemon();
  render(<Wallboard initialState={null} />);

  expect(screen.getByText('Connecting…', { selector: 'main *' })).toBeVisible();
  expect(screen.queryByRole('region', { name: 'Queues stats' })).not.toBeInTheDocument();

  await vi.waitFor(() => {
    daemonControl.sendToPage(snapshotExample);
    expect(screen.getByRole('region', { name: 'Queues stats' })).toBeInTheDocument();
  });
  expect(screen.getByRole('status', { name: 'Connection' })).toHaveTextContent('● Live');
});

test('a lost connection dims the last state and says when it reconnects', async () => {
  const daemonControl = listenAsDaemon();
  render(<Wallboard initialState={busyFloor} />);
  const content = screen.getByRole('main');
  expect(content).not.toHaveAttribute('data-dimmed');

  await vi.waitFor(() => {
    daemonControl.sendToPage(snapshotExample);
    expect(screen.getByRole('status', { name: 'Connection' })).toHaveTextContent('● Live');
  });
  daemonControl.closeFromDaemon();

  await vi.waitFor(() => {
    expect(screen.getByRole('status', { name: 'Connection' })).toHaveTextContent(/^Reconnecting in \d+ s$/);
  });
  expect(content).toHaveAttribute('data-dimmed', 'true');
  expect(findWidget('Queues stats')).toBeInTheDocument();
});

test('a disconnected phone exchange shows a banner and dims the numbers', () => {
  listenAsDaemon();
  render(<Wallboard initialState={{ ...busyFloor, exchangeConnected: false }} />);

  expect(screen.getByText('PBX disconnected')).toBeVisible();
  expect(screen.getByRole('main')).toHaveAttribute('data-dimmed', 'true');
});

test('a changed queue number is highlighted in the direction that is worse for it, or neutral when it has none', async () => {
  const daemonControl = listenAsDaemon();
  render(<Wallboard initialState={busyFloor} />);
  await vi.waitFor(() => {
    daemonControl.sendToPage({ action: 'snapshot', agents: [], queues: Object.values(busyFloor.queues), exchange_connected: true, sla_threshold_seconds: 20 });
    expect(screen.getByRole('status', { name: 'Connection' })).toHaveTextContent('● Live');
  });

  const busierSales = { ...quietSalesQueue, available: 2, unanswered: 4, total: 50, ongoing: 3, users_on_pause: { lunch: 0, training: 1, inactive: 0, other: 0 }, sla: 0.8, waiting_calls: [] } satisfies Queue;
  daemonControl.sendToPage({ action: 'queuesChanged', queues: [busierSales] });

  await vi.waitFor(() => {
    expect(within(findCell('Queues stats', /^Sales/, 'Waiting')).getByText('0')).toHaveAttribute('data-change', 'better');
  });
  expect(within(findCell('Queues stats', /^Sales/, 'Available')).getByText('2')).toHaveAttribute('data-change', 'better');
  expect(within(findCell('Queues stats', /^Sales/, 'Unanswered')).getByText('4')).toHaveAttribute('data-change', 'worse');
  expect(within(within(findWidget('Service availability')).getByRole('group', { name: 'Sales' }))
    .getByRole('group', { name: 'SLA' })
    .querySelector('[data-change]')).toHaveAttribute('data-change', 'worse');
  expect(within(findCell('Queues stats', /^Sales/, 'Total')).getByText('50')).toHaveAttribute('data-change', 'neutral');
  expect(within(findCell('Queues stats', /^Sales/, 'Ongoing')).getByText('3')).toHaveAttribute('data-change', 'neutral');
  expect(within(findCell('Pauses', /^Sales/, 'Training')).getByText('1')).toHaveAttribute('data-change', 'neutral');
  expect(within(findCell('Pauses', /^Sales/, 'Inactive')).getByText('0')).not.toHaveAttribute('data-change');
  expect(within(findCell('Queues stats', /^Support/, 'Waiting')).getByText('5')).not.toHaveAttribute('data-change');
});

test('a queue name takes the worst colour of its columns even when only the available count is bad', () => {
  listenAsDaemon();
  const emptyBillingQueue = { ...quietSalesQueue, name: 'billing', title: 'Billing', available: 0, waiting_calls: [] } satisfies Queue;
  render(<Wallboard initialState={{ ...busyFloor, queues: { billing: emptyBillingQueue } }} />);

  const billingRow = within(findWidget('Queues stats')).getByRole('row', { name: /^Billing/ });
  expect(findCell('Queues stats', /^Billing/, 'Waiting')).toHaveAttribute('data-colour', 'green');
  expect(within(billingRow).getByRole('rowheader')).toHaveAttribute('data-colour', 'red');
});

test('the server render lists the waiting calls without wait times or red markers', () => {
  const html = renderToString(<Wallboard initialState={busyFloor} />);

  expect(html.match(/<li /g)).toHaveLength(6);
  expect(html).not.toMatch(/\d:\d\d/);
  expect(html).not.toContain('data-colour="red"><span>');
});

test('waiting calls are ordered by instant, not by the text of their offset', () => {
  listenAsDaemon();
  const laterInstantEarlierText = { id: 'sales-later', waiting_since: '2026-11-03T09:59:00+01:00' };
  const earlierInstantLaterText = { id: 'sales-earlier', waiting_since: '2026-11-03T09:59:00+02:00' };
  const mixedOffsetQueue = { ...quietSalesQueue, waiting_calls: [laterInstantEarlierText, earlierInstantLaterText] } satisfies Queue;
  render(<Wallboard initialState={{ ...busyFloor, queues: { sales: mixedOffsetQueue } }} />);

  const listedCalls = within(findWidget('Waiting activities')).getAllByRole('listitem');
  expect(listedCalls.map((listedCall) => listedCall.textContent)).toEqual(['Sales1:01:00', 'Sales1:00']);
});

test('waiting activities say so when no call is waiting', () => {
  listenAsDaemon();
  render(<Wallboard initialState={{ ...busyFloor, queues: { sales: { ...quietSalesQueue, waiting_calls: [] } } }} />);

  expect(within(findWidget('Waiting activities')).getByText('No calls waiting')).toBeVisible();
  expect(within(findWidget('Waiting activities')).queryByRole('listitem')).not.toBeInTheDocument();
});

test('without a state and with a reconnect pending it says the server is not answering, not that it is connecting', async () => {
  const daemonControl = listenAsDaemon();
  render(<Wallboard initialState={null} />);

  await vi.waitFor(() => {
    daemonControl.closeFromDaemon();
    expect(screen.getByRole('status', { name: 'Connection' })).toHaveTextContent(/^Reconnecting in \d+ s$/);
  });

  expect(screen.getByText('The server is not answering.', { selector: 'main *' })).toBeVisible();
  expect(screen.queryByText('Connecting…', { selector: 'main *' })).not.toBeInTheDocument();
});
