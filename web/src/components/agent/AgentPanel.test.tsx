import { render, screen, within, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderToString } from 'react-dom/server';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import type { Agent, ClientMessage, Queue, ServerMessage } from '@/api/wireSchema';
import type { FloorState } from '@/domain/floorReducer';
import { AgentPanel } from '@/components/agent/AgentPanel';
import { formatSecondsAgo, NOW } from '@/test/clock';
import { listenAsDaemon, type DaemonControl } from '@/test/daemonFake';

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] });
  vi.setSystemTime(NOW);
});

afterEach(() => {
  vi.useRealTimers();
});

const availableAgent = {
  id_agent: '203',
  title: 'Lucie Dvořáková',
  exten: '203',
  state: 'Idle',
  exten_status: 'Idle',
  id_pause: null,
  onpause: null,
  statetime: formatSecondsAgo(12),
  queues: ['sales', 'support'],
} satisfies Agent;

const speakingAgent = { ...availableAgent, state: 'Session', exten_status: 'Busy', statetime: formatSecondsAgo(220) } satisfies Agent;

const agentOnLunch = {
  ...availableAgent,
  state: 'Paused',
  id_pause: 'lunch',
  onpause: formatSecondsAgo(485),
  statetime: formatSecondsAgo(485),
} satisfies Agent;

const agentOnInactivePause = {
  ...agentOnLunch,
  id_pause: 'inactive',
  onpause: formatSecondsAgo(31),
  statetime: formatSecondsAgo(31),
} satisfies Agent;

const salesQueue = {
  name: 'sales',
  title: 'Sales',
  threshold_calls: 4,
  threshold_users: 1,
  threshold_time: 60,
  ongoing: 0,
  available: 1,
  users_on_pause: { lunch: 0, training: 0, inactive: 0, other: 0 },
  answered: 0,
  unanswered: 0,
  total: 0,
  counted_since: '2026-11-04T00:00:00+01:00',
  abandon_rate: null,
  sla: null,
  svl: null,
  waiting_calls: [1, 2, 3].map((callNumber) => ({ id: `sales-${callNumber}`, waiting_since: formatSecondsAgo(callNumber) })),
} satisfies Queue;

const supportQueue = {
  ...salesQueue,
  name: 'support',
  title: 'Support',
  threshold_calls: 3,
  waiting_calls: [1, 2, 3, 4, 5].map((callNumber) => ({ id: `support-${callNumber}`, waiting_since: formatSecondsAgo(callNumber) })),
} satisfies Queue;

const createFloorWith = (agent: Agent) =>
  ({
    agents: { [agent.id_agent]: agent },
    queues: { sales: salesQueue, support: supportQueue },
    exchangeConnected: true,
    slaThresholdSeconds: 20,
  }) satisfies FloorState;

function findButton(name: string): HTMLElement {
  return screen.getByRole('button', { name });
}

async function waitUntilLive(daemonControl: DaemonControl, floorState: FloorState): Promise<void> {
  const snapshot = {
    action: 'snapshot',
    agents: Object.values(floorState.agents),
    queues: Object.values(floorState.queues),
    exchange_connected: floorState.exchangeConnected,
    sla_threshold_seconds: floorState.slaThresholdSeconds,
  } satisfies ServerMessage;
  await vi.waitFor(() => {
    daemonControl.sendToPage(snapshot);
    expect(screen.getByRole('status', { name: 'Connection' })).toHaveTextContent('● Live');
  });
}

test('the agent name, extension and the time in the available state show', async () => {
  const daemonControl = listenAsDaemon();
  render(<AgentPanel extension="203" initialState={createFloorWith(availableAgent)} />);
  await waitUntilLive(daemonControl, createFloorWith(availableAgent));

  expect(screen.getByRole('heading', { name: 'Lucie Dvořáková · 203' })).toBeVisible();
  expect(screen.getByText('Available')).toBeVisible();
  expect(screen.getByText('for 0:12')).toBeVisible();
  expect(findButton('Lunch')).toBeEnabled();
  expect(findButton('Training')).toBeEnabled();
});

test('an agent with a busy extension is speaking, for as long as the state lasts', () => {
  listenAsDaemon();
  render(<AgentPanel extension="203" initialState={createFloorWith(speakingAgent)} />);

  expect(screen.getByText('Speaking')).toBeVisible();
  expect(screen.getByText('for 3:40')).toBeVisible();
});

test('clicking Lunch sends the pause, shows Pausing for Lunch and disables both buttons until the exchange confirms', async () => {
  const daemonControl = listenAsDaemon();
  const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
  render(<AgentPanel extension="203" initialState={createFloorWith(availableAgent)} />);
  await waitUntilLive(daemonControl, createFloorWith(availableAgent));

  await user.click(findButton('Lunch'));

  expect(daemonControl.clientMessages).toEqual([{ action: 'pause', pause: 'lunch' }] satisfies ClientMessage[]);
  expect(screen.getByText('Pausing for Lunch…')).toBeVisible();
  expect(findButton('Lunch')).toBeDisabled();
  expect(findButton('Training')).toBeDisabled();
  expect(screen.queryByText(/^On pause/)).not.toBeInTheDocument();

  daemonControl.sendToPage({ action: 'agentsChanged', agents: [agentOnLunch] });

  expect(await screen.findByText('On pause: Lunch')).toBeVisible();
  expect(screen.getByText('for 8:05')).toBeVisible();
  expect(screen.queryByText('Pausing for Lunch…')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Lunch' })).not.toBeInTheDocument();
});

test('clicking Training sends the training pause', async () => {
  const daemonControl = listenAsDaemon();
  const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
  render(<AgentPanel extension="203" initialState={createFloorWith(availableAgent)} />);
  await waitUntilLive(daemonControl, createFloorWith(availableAgent));

  await user.click(findButton('Training'));

  expect(daemonControl.clientMessages).toEqual([{ action: 'pause', pause: 'training' }] satisfies ClientMessage[]);
  expect(screen.getByText('Pausing for Training…')).toBeVisible();
});

test('an error from the exchange shows a banner and enables the buttons again', async () => {
  const daemonControl = listenAsDaemon();
  const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
  render(<AgentPanel extension="203" initialState={createFloorWith(availableAgent)} />);
  await waitUntilLive(daemonControl, createFloorWith(availableAgent));
  await user.click(findButton('Lunch'));
  expect(findButton('Lunch')).toBeDisabled();

  daemonControl.sendToPage({ action: 'error', message: 'The PBX did not confirm the pause. Try again.' });

  expect(await screen.findByText('The PBX did not confirm the pause. Try again.')).toBeVisible();
  expect(screen.queryByText('Pausing for Lunch…')).not.toBeInTheDocument();
  expect(findButton('Lunch')).toBeEnabled();
  expect(findButton('Training')).toBeEnabled();
});

test('a pause the PBX did not confirm stays dropped after the PBX comes back', async () => {
  const daemonControl = listenAsDaemon();
  const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
  render(<AgentPanel extension="203" initialState={createFloorWith(availableAgent)} />);
  await waitUntilLive(daemonControl, createFloorWith(availableAgent));
  await user.click(findButton('Lunch'));
  daemonControl.sendToPage({ action: 'error', message: 'The PBX did not confirm the pause. Try again.' });
  await screen.findByText('The PBX did not confirm the pause. Try again.');

  daemonControl.sendToPage({ action: 'exchangeConnection', connected: false });
  daemonControl.sendToPage({ action: 'exchangeConnection', connected: true });

  await waitFor(() => expect(screen.queryByText('The PBX did not confirm the pause. Try again.')).not.toBeInTheDocument());
  expect(screen.queryByText('Pausing for Lunch…')).not.toBeInTheDocument();
  expect(findButton('Lunch')).toBeEnabled();
});

test('a pause for a reason the PBX reports outside Lunch, Training and Inactive shows as Other and offers End pause', async () => {
  const daemonControl = listenAsDaemon();
  const agentOnOtherPause = { ...availableAgent, state: 'Paused', id_pause: 'other', onpause: availableAgent.statetime } satisfies Agent;
  render(<AgentPanel extension="203" initialState={createFloorWith(agentOnOtherPause)} />);
  await waitUntilLive(daemonControl, createFloorWith(agentOnOtherPause));

  expect(screen.getByText('On pause: Other')).toBeVisible();
  expect(findButton('End pause')).toBeEnabled();
});

test('a new click hides the error of the previous attempt', async () => {
  const daemonControl = listenAsDaemon();
  const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
  render(<AgentPanel extension="203" initialState={createFloorWith(availableAgent)} />);
  await waitUntilLive(daemonControl, createFloorWith(availableAgent));
  await user.click(findButton('Lunch'));
  daemonControl.sendToPage({ action: 'error', message: 'The PBX did not confirm the pause. Try again.' });
  await screen.findByText('The PBX did not confirm the pause. Try again.');

  await user.click(findButton('Training'));

  expect(screen.queryByText('The PBX did not confirm the pause. Try again.')).not.toBeInTheDocument();
  expect(screen.getByText('Pausing for Training…')).toBeVisible();
});

test('End pause sends the unpause and the pause shown stays until the exchange reports the agent available', async () => {
  const daemonControl = listenAsDaemon();
  const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
  render(<AgentPanel extension="203" initialState={createFloorWith(agentOnLunch)} />);
  await waitUntilLive(daemonControl, createFloorWith(agentOnLunch));

  await user.click(findButton('End pause'));

  expect(daemonControl.clientMessages).toEqual([{ action: 'unpause' }] satisfies ClientMessage[]);
  expect(screen.getByText('On pause: Lunch')).toBeVisible();
  daemonControl.sendToPage({ action: 'agentsChanged', agents: [availableAgent] });
  expect(await screen.findByText('Available')).toBeVisible();
  expect(findButton('Lunch')).toBeEnabled();
});

test('after a confirmed pause and End pause the page does not fall back to Pausing', async () => {
  const daemonControl = listenAsDaemon();
  const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
  render(<AgentPanel extension="203" initialState={createFloorWith(availableAgent)} />);
  await waitUntilLive(daemonControl, createFloorWith(availableAgent));
  await user.click(findButton('Lunch'));
  daemonControl.sendToPage({ action: 'agentsChanged', agents: [agentOnLunch] });
  await user.click(await screen.findByRole('button', { name: 'End pause' }));

  daemonControl.sendToPage({ action: 'agentsChanged', agents: [availableAgent] });

  expect(await screen.findByText('Available')).toBeVisible();
  expect(screen.queryByText(/^Pausing for/)).not.toBeInTheDocument();
  expect(findButton('Lunch')).toBeEnabled();
});

test('an Inactive pause explains both of its causes and offers End pause', async () => {
  const daemonControl = listenAsDaemon();
  render(<AgentPanel extension="203" initialState={createFloorWith(agentOnInactivePause)} />);
  await waitUntilLive(daemonControl, createFloorWith(agentOnInactivePause));

  expect(screen.getByText('On pause: Inactive')).toBeVisible();
  expect(screen.getByText('for 0:31 · you did not answer a call in time, or this page stopped responding')).toBeVisible();
  expect(findButton('End pause')).toBeEnabled();
});

test('my queues lists each queue of the agent with its waiting count coloured as on the wallboard', () => {
  listenAsDaemon();
  const agentInSalesOnly = { ...availableAgent, queues: ['sales'] } satisfies Agent;
  render(<AgentPanel extension="203" initialState={createFloorWith(agentInSalesOnly)} />);

  const salesRow = within(screen.getByRole('region', { name: 'My queues' })).getByRole('listitem');
  expect(salesRow).toHaveTextContent('Sales3');
  expect(within(salesRow).getByText('3').closest('[data-colour]')).toHaveAttribute('data-colour', 'orange');
  expect(screen.queryByText('Support')).not.toBeInTheDocument();
});

test('my queues colours a queue over its call threshold red', () => {
  listenAsDaemon();
  render(<AgentPanel extension="203" initialState={createFloorWith(availableAgent)} />);

  const supportRow = within(screen.getByRole('region', { name: 'My queues' }))
    .getAllByRole('listitem')
    .find((row) => row.textContent?.startsWith('Support'));
  if (supportRow === undefined) {
    throw new Error('No Support row in My queues');
  }
  expect(supportRow).toHaveTextContent('Support5');
  expect(within(supportRow).getByText('5').closest('[data-colour]')).toHaveAttribute('data-colour', 'red');
});

test('a lost connection shows the banner, dims the state, disables the buttons and says when it reconnects', async () => {
  const daemonControl = listenAsDaemon();
  render(<AgentPanel extension="203" initialState={createFloorWith(availableAgent)} />);
  await waitUntilLive(daemonControl, createFloorWith(availableAgent));
  expect(screen.queryByText('Connection to the server lost. Showing the last known state.')).not.toBeInTheDocument();

  daemonControl.closeFromDaemon();

  expect(await screen.findByText('Connection to the server lost. Showing the last known state.')).toBeVisible();
  expect(screen.getByRole('status', { name: 'Connection' })).toHaveTextContent(/^Reconnecting in \d+ s$/);
  expect(screen.getByRole('main')).toHaveAttribute('data-dimmed', 'true');
  expect(findButton('Lunch')).toBeDisabled();
  expect(findButton('Training')).toBeDisabled();
});

test('a pause request is dropped when the connection falls and the reconnect snapshot shows the agent available', async () => {
  const daemonControl = listenAsDaemon();
  const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
  render(<AgentPanel extension="203" initialState={createFloorWith(availableAgent)} />);
  await waitUntilLive(daemonControl, createFloorWith(availableAgent));
  await user.click(findButton('Lunch'));
  expect(screen.getByText('Pausing for Lunch…')).toBeVisible();

  daemonControl.closeFromDaemon();
  await screen.findByText('Connection to the server lost. Showing the last known state.');
  expect(screen.queryByText('Pausing for Lunch…')).not.toBeInTheDocument();
  await waitUntilLive(daemonControl, createFloorWith(availableAgent));

  expect(screen.queryByText(/^Pausing for/)).not.toBeInTheDocument();
  expect(findButton('Lunch')).toBeEnabled();
  expect(findButton('Training')).toBeEnabled();
});

test('a snapshot that does not show the requested pause drops the request', async () => {
  const daemonControl = listenAsDaemon();
  const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
  render(<AgentPanel extension="203" initialState={createFloorWith(availableAgent)} />);
  await waitUntilLive(daemonControl, createFloorWith(availableAgent));
  await user.click(findButton('Lunch'));
  expect(screen.getByText('Pausing for Lunch…')).toBeVisible();

  daemonControl.sendToPage({
    action: 'snapshot',
    agents: [availableAgent],
    queues: [salesQueue, supportQueue],
    exchange_connected: true,
    sla_threshold_seconds: 20,
  });

  await vi.waitFor(() => {
    expect(screen.queryByText(/^Pausing for/)).not.toBeInTheDocument();
  });
  expect(findButton('Lunch')).toBeEnabled();
});

test('a disconnected phone exchange shows a banner and dims the state until it is connected again', async () => {
  const daemonControl = listenAsDaemon();
  render(<AgentPanel extension="203" initialState={createFloorWith(availableAgent)} />);
  await waitUntilLive(daemonControl, createFloorWith(availableAgent));
  expect(screen.queryByText('PBX disconnected')).not.toBeInTheDocument();
  expect(screen.getByRole('main')).not.toHaveAttribute('data-dimmed');

  daemonControl.sendToPage({ action: 'exchangeConnection', connected: false });

  expect(await screen.findByText('PBX disconnected')).toBeVisible();
  expect(screen.getByRole('main')).toHaveAttribute('data-dimmed', 'true');

  daemonControl.sendToPage({ action: 'exchangeConnection', connected: true });

  await vi.waitFor(() => {
    expect(screen.queryByText('PBX disconnected')).not.toBeInTheDocument();
  });
  expect(screen.getByRole('main')).not.toHaveAttribute('data-dimmed');
});

test('a growing waiting count in My queues is highlighted as worse', async () => {
  const daemonControl = listenAsDaemon();
  render(<AgentPanel extension="203" initialState={createFloorWith(availableAgent)} />);
  await waitUntilLive(daemonControl, createFloorWith(availableAgent));
  const findSalesWaitingCount = (count: string) => {
    const salesRow = screen.getByText('Sales').closest('li');
    if (salesRow === null) {
      throw new Error('No Sales row in My queues');
    }
    return within(salesRow).getByText(count);
  };
  expect(findSalesWaitingCount('3')).not.toHaveAttribute('data-change');

  const fourWaitingCalls = [1, 2, 3, 4].map((callNumber) => ({ id: `sales-${callNumber}`, waiting_since: formatSecondsAgo(callNumber) }));
  daemonControl.sendToPage({ action: 'queuesChanged', queues: [{ ...salesQueue, waiting_calls: fourWaitingCalls }] });

  await vi.waitFor(() => {
    expect(findSalesWaitingCount('4')).toHaveAttribute('data-change', 'worse');
  });
});

test('a page for an extension the floor does not know says so, and a missing floor says it is connecting', () => {
  listenAsDaemon();
  const connectingPanel = render(<AgentPanel extension="299" initialState={null} />);
  expect(screen.getByText('Connecting…', { selector: 'p' })).toBeVisible();
  connectingPanel.unmount();

  render(<AgentPanel extension="299" initialState={createFloorWith(availableAgent)} />);
  expect(screen.getByText('No agent with extension 299 is known to the server.')).toBeVisible();
  expect(screen.queryByRole('button')).not.toBeInTheDocument();
});

test('a page opened before the PBX has listed any agent waits for the list instead of calling the agent unknown', () => {
  listenAsDaemon();
  const floorBeforeFirstAgentList = { agents: {}, queues: {}, exchangeConnected: false, slaThresholdSeconds: 20 } satisfies FloorState;
  render(<AgentPanel extension="203" initialState={floorBeforeFirstAgentList} />);

  expect(screen.getByText('Waiting for the PBX to list the agents…')).toBeVisible();
  expect(screen.getByText('PBX disconnected')).toBeVisible();
  expect(screen.queryByText(/is known to the server/)).not.toBeInTheDocument();
  expect(screen.queryByRole('button')).not.toBeInTheDocument();
});

test('the server render shows the state without the time in it', () => {
  const html = renderToString(<AgentPanel extension="203" initialState={createFloorWith(agentOnLunch)} />);

  expect(html).toContain('On pause: Lunch');
  expect(html).not.toMatch(/\d:\d\d/);
});

test('pressing Lunch from the keyboard moves the focus to End pause once the pause is confirmed, and back', async () => {
  const daemonControl = listenAsDaemon();
  const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
  render(<AgentPanel extension="203" initialState={createFloorWith(availableAgent)} />);
  await waitUntilLive(daemonControl, createFloorWith(availableAgent));

  findButton('Lunch').focus();
  await user.keyboard('{Enter}');
  daemonControl.sendToPage({ action: 'agentsChanged', agents: [agentOnLunch] });

  expect(await screen.findByRole('button', { name: 'End pause' })).toHaveFocus();

  await user.keyboard('{Enter}');
  daemonControl.sendToPage({ action: 'agentsChanged', agents: [availableAgent] });

  expect(await screen.findByRole('button', { name: 'Lunch' })).toHaveFocus();
});

test('the pause buttons are disabled while the phone exchange is disconnected', async () => {
  const daemonControl = listenAsDaemon();
  const floorWithoutExchange = { ...createFloorWith(availableAgent), exchangeConnected: false } satisfies FloorState;
  render(<AgentPanel extension="203" initialState={floorWithoutExchange} />);
  await waitUntilLive(daemonControl, floorWithoutExchange);

  expect(findButton('Lunch')).toBeDisabled();
  expect(findButton('Training')).toBeDisabled();
});

test('an exchange error disappears once the phone exchange is connected again', async () => {
  const daemonControl = listenAsDaemon();
  render(<AgentPanel extension="203" initialState={createFloorWith(availableAgent)} />);
  await waitUntilLive(daemonControl, createFloorWith(availableAgent));

  daemonControl.sendToPage({ action: 'exchangeConnection', connected: false });
  daemonControl.sendToPage({ action: 'error', message: 'The PBX did not confirm the pause. Try again.' });
  expect(await screen.findByRole('alert')).toHaveTextContent('The PBX did not confirm the pause. Try again.');

  daemonControl.sendToPage({ action: 'exchangeConnection', connected: true });

  await vi.waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
});

test('a page for an extension the daemon refuses says the agent is unknown and stops reconnecting', async () => {
  const daemonControl = listenAsDaemon();
  render(<AgentPanel extension="299" initialState={null} />);
  await vi.waitFor(() => {
    daemonControl.closeFromDaemon(1008);
    expect(screen.getByText('No agent with extension 299 is known to the server.')).toBeVisible();
  });

  expect(screen.getByRole('status', { name: 'Connection' })).toHaveTextContent('Not connected');
});

test('my queues colour each queue name with the worst colour of that queue, as the wallboard does', () => {
  listenAsDaemon();
  render(<AgentPanel extension="203" initialState={createFloorWith(availableAgent)} />);

  expect(screen.getByText('Support')).toHaveAttribute('data-colour', 'red');
  expect(screen.getByText('Sales')).toHaveAttribute('data-colour', 'orange');
});

test('without a state and with a reconnect pending it says the server is not answering', async () => {
  const daemonControl = listenAsDaemon();
  render(<AgentPanel extension="203" initialState={null} />);

  await vi.waitFor(() => {
    daemonControl.closeFromDaemon();
    expect(screen.getByRole('status', { name: 'Connection' })).toHaveTextContent(/^Reconnecting in \d+ s$/);
  });

  expect(screen.getByText('The server is not answering.')).toBeVisible();
  expect(screen.queryByText('Connecting…', { selector: 'p' })).not.toBeInTheDocument();
});
