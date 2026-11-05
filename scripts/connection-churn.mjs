const [durationArgument, daemonWebsocketUrl = 'ws://daemon:8090/websocket'] = process.argv.slice(2);
const durationSeconds = Number(durationArgument);
if (!Number.isFinite(durationSeconds) || durationSeconds <= 0) {
  console.error('Usage: node scripts/connection-churn.mjs <seconds> [daemon WebSocket URL]');
  process.exit(1);
}

const WALLBOARD_PAGE_COUNT = 8;
// no 201 or 203: they are person-controlled, so a closed page leaves them Inactive until someone presses End pause
const AGENT_EXTENSIONS = ['202', '204', '205', '206'];
const SHORTEST_PAGE_LIFETIME_SECONDS = 1;
const LONGEST_PAGE_LIFETIME_SECONDS = 20;
const REOPEN_DELAY_MILLISECONDS = 100;
const PROGRESS_INTERVAL_SECONDS = 60;
const CLOSE_WAIT_LIMIT_MILLISECONDS = 5000;
const CLOSE_WAIT_STEP_MILLISECONDS = 50;

const counts = { opened: 0, closed: 0, closedByDaemon: 0, failedConnects: 0, pongsSent: 0 };
const openSockets = new Set();
const startedAt = Date.now();
let isStopping = false;

function drawPageLifetimeMilliseconds() {
  const lifetimeSeconds = SHORTEST_PAGE_LIFETIME_SECONDS
    + Math.random() * (LONGEST_PAGE_LIFETIME_SECONDS - SHORTEST_PAGE_LIFETIME_SECONDS);

  return lifetimeSeconds * 1000;
}

function openPage(agentExtension) {
  if (isStopping) {
    return;
  }
  const pageUrl = agentExtension === null ? daemonWebsocketUrl : `${daemonWebsocketUrl}?agent=${agentExtension}`;
  const socket = new WebSocket(pageUrl);
  let isOpened = false;
  let isClosedByScript = false;
  openSockets.add(socket);
  const lifetimeTimer = setTimeout(() => {
    isClosedByScript = true;
    socket.close(1000);
  }, drawPageLifetimeMilliseconds());

  socket.onopen = () => {
    isOpened = true;
    counts.opened++;
  };
  socket.onmessage = (event) => {
    if (JSON.parse(event.data).action === 'ping') {
      socket.send(JSON.stringify({ action: 'pong' }));
      counts.pongsSent++;
    }
  };
  socket.onclose = () => {
    clearTimeout(lifetimeTimer);
    openSockets.delete(socket);
    if (!isOpened) {
      counts.failedConnects++;
    } else {
      counts.closed++;
      if (!isClosedByScript && !isStopping) {
        counts.closedByDaemon++;
      }
    }
    setTimeout(() => openPage(agentExtension), REOPEN_DELAY_MILLISECONDS);
  };
}

function reportCounts(label) {
  const elapsedSeconds = Math.round((Date.now() - startedAt) / 1000);
  console.log(`${label} after ${elapsedSeconds} s: opened ${counts.opened}, closed ${counts.closed}`
    + ` (${counts.closedByDaemon} by the daemon), failed to connect ${counts.failedConnects}, pongs sent ${counts.pongsSent},`
    + ` open now ${openSockets.size}`);
}

function stopChurn() {
  isStopping = true;
  clearInterval(progressTimer);
  for (const socket of openSockets) {
    socket.close(1000);
  }
  const waitStartedAt = Date.now();
  const closeWaitTimer = setInterval(() => {
    if (openSockets.size === 0 || Date.now() - waitStartedAt > CLOSE_WAIT_LIMIT_MILLISECONDS) {
      clearInterval(closeWaitTimer);
      reportCounts('Finished');
    }
  }, CLOSE_WAIT_STEP_MILLISECONDS);
}

console.log(`Churning ${WALLBOARD_PAGE_COUNT} wallboard pages and agent pages ${AGENT_EXTENSIONS.join(', ')}`
  + ` against ${daemonWebsocketUrl} for ${durationSeconds} s`);
for (let wallboardPageIndex = 0; wallboardPageIndex < WALLBOARD_PAGE_COUNT; wallboardPageIndex++) {
  openPage(null);
}
for (const agentExtension of AGENT_EXTENSIONS) {
  openPage(agentExtension);
}
const progressTimer = setInterval(() => reportCounts('Progress'), PROGRESS_INTERVAL_SECONDS * 1000);
setTimeout(stopChurn, durationSeconds * 1000);
