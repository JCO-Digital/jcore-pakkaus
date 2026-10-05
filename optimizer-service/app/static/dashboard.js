const state = { days: 30, key: '', csrf: '', data: null };
const $ = (id) => document.getElementById(id);
const number = new Intl.NumberFormat('en');
const decimal = new Intl.NumberFormat('en', { maximumFractionDigits: 1 });
const dayFormat = new Intl.DateTimeFormat('en', { month: 'short', day: 'numeric', timeZone: 'UTC' });
const longDayFormat = new Intl.DateTimeFormat('en', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
const dateFormat = new Intl.DateTimeFormat('en', { year: 'numeric', month: 'short', day: 'numeric' });

// ------------------------------------------------------------ helpers

function el(tag, attrs = {}, ...children) {
  const node = document.createElement(tag);
  for (const [name, value] of Object.entries(attrs)) {
    if (name === 'className') node.className = value;
    else if (name.startsWith('on')) node.addEventListener(name.slice(2), value);
    else if (value !== false && value != null) node.setAttribute(name, value);
  }
  node.append(...children.filter((child) => child != null));
  return node;
}

function svg(tag, attrs = {}) {
  const node = document.createElementNS('http://www.w3.org/2000/svg', tag);
  for (const [name, value] of Object.entries(attrs)) node.setAttribute(name, value);
  return node;
}

async function api(path, { method = 'GET', body } = {}) {
  const response = await fetch(path, {
    method,
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  if (response.status === 401) {
    location.reload();
    throw new Error('Signed out');
  }
  if (!response.ok) {
    const detail = await response.json().then((data) => data.detail, () => null);
    throw new Error(typeof detail === 'string' ? detail : `Request failed with HTTP ${response.status}`);
  }
  return response.status === 204 ? null : response.json();
}

function showError(error) {
  $('error').textContent = error ? error.message : '';
  $('error').hidden = !error;
}

function bytes(value) {
  if (!value) return '0 B';
  const units = ['B', 'kB', 'MB', 'GB', 'TB'];
  const exponent = Math.min(units.length - 1, Math.floor(Math.log10(Math.abs(value)) / 3));
  return `${decimal.format(value / 1000 ** exponent)} ${units[exponent]}`;
}

function duration(seconds) {
  if (!seconds) return '0 min';
  if (seconds < 60) return `${Math.round(seconds)} s`;
  if (seconds < 3600) return `${Math.round(seconds / 60)} min`;
  return `${decimal.format(seconds / 3600)} h`;
}

function ago(timestamp) {
  if (!timestamp) return 'Never';
  const seconds = Date.now() / 1000 - timestamp;
  if (seconds < 90) return 'Just now';
  if (seconds < 3600) return `${Math.round(seconds / 60)} min ago`;
  if (seconds < 86400) return `${Math.round(seconds / 3600)} h ago`;
  if (seconds < 30 * 86400) return `${Math.round(seconds / 86400)} days ago`;
  return dateFormat.format(new Date(timestamp * 1000));
}

const utcDay = (day) => new Date(`${day}T00:00:00Z`);

function sum(rows, field) {
  return rows.reduce((total, row) => total + (row[field] || 0), 0);
}

// -------------------------------------------------------------- data

function selectedKeys() {
  const keys = state.data.keys;
  return state.key ? keys.filter((key) => key.id === state.key) : keys;
}

function dailyRows() {
  const fields = ['jobs', 'completed', 'skipped', 'failed', 'saved_bytes'];
  const series = selectedKeys().map((key) => state.data.daily[key.id] || []);
  return Array.from({ length: state.data.days }, (_, index) => {
    const row = { day: new Date((state.data.first_day + index * 86400) * 1000).toISOString().slice(0, 10) };
    for (const field of fields) row[field] = series.reduce((total, rows) => total + (rows[index]?.[field] || 0), 0);
    return row;
  });
}

async function load() {
  try {
    state.data = await api(`/admin/api/overview?days=${state.days}`);
    if (state.key && !state.data.keys.some((key) => key.id === state.key)) state.key = '';
    showError(null);
    render();
  } catch (error) {
    showError(error);
  }
}

// ------------------------------------------------------------ render

function render() {
  renderFilters();
  renderKpis();
  renderChart();
  renderKeys();
}

function renderFilters() {
  for (const button of document.querySelectorAll('.segmented button')) {
    button.setAttribute('aria-checked', String(Number(button.dataset.days) === state.days));
  }
  const select = $('key-filter');
  select.replaceChildren(
    el('option', { value: '' }, 'All API keys'),
    ...state.data.keys.map((key) => el('option', { value: key.id }, key.active ? key.name : `${key.name} (inactive)`)),
  );
  select.value = state.key;
}

function renderKpis() {
  const usage = selectedKeys().map((key) => key.usage);
  const total = (field) => sum(usage, field);
  const jobs = total('jobs');
  const input = total('input_bytes');
  const saved = total('saved_bytes');
  const active = total('active');

  $('kpi-jobs').textContent = number.format(jobs);
  $('kpi-jobs-sub').textContent = `${number.format(total('completed'))} optimized · ${number.format(total('skipped'))} skipped · ${number.format(total('failed'))} failed`;
  $('kpi-video').textContent = duration(total('video_seconds'));
  $('kpi-video-sub').textContent = `${bytes(input)} of source video`;
  $('kpi-saved').textContent = bytes(saved);
  $('kpi-saved-sub').textContent = input ? `${decimal.format((saved / input) * 100)}% of the source size` : 'Nothing processed yet';
  $('kpi-time').textContent = duration(total('processing_seconds'));
  $('kpi-time-sub').textContent = active ? `${number.format(active)} job${active === 1 ? '' : 's'} queued or running` : 'No jobs queued or running';
}

function niceScale(max) {
  if (max <= 0) return { top: 4, step: 1 };
  const rough = max / 4;
  const magnitude = 10 ** Math.floor(Math.log10(rough));
  const step = [1, 2, 5, 10].map((m) => m * magnitude).find((s) => s >= rough && s >= 1) || Math.max(1, magnitude * 10);
  return { top: Math.ceil(max / step) * step, step };
}

// A column with a 4px rounded data end and a square baseline.
function columnPath(x, y, width, height) {
  const r = Math.min(4, width / 2, height);
  return `M${x},${y + height}V${y + r}Q${x},${y} ${x + r},${y}H${x + width - r}Q${x + width},${y} ${x + width},${y + r}V${y + height}Z`;
}

function renderChart() {
  const rows = dailyRows();
  const container = $('chart');
  const keyName = state.key ? selectedKeys()[0]?.name : null;
  $('chart-title').textContent = keyName ? `Jobs per day, ${keyName}` : 'Jobs per day, all API keys';

  const width = container.clientWidth || 800;
  const height = container.clientHeight || 240;
  const margin = { top: 8, right: 4, bottom: 26, left: 40 };
  const innerW = width - margin.left - margin.right;
  const innerH = height - margin.top - margin.bottom;
  const { top, step } = niceScale(Math.max(0, ...rows.map((row) => row.jobs)));
  const y = (value) => margin.top + innerH - (value / top) * innerH;
  const band = innerW / Math.max(1, rows.length);
  const barWidth = Math.max(1, Math.min(24, band - 2));

  const chart = svg('svg', { viewBox: `0 0 ${width} ${height}`, role: 'img', 'aria-label': `${$('chart-title').textContent}. The table below has the same data.` });

  for (let value = 0; value <= top; value += step) {
    chart.append(svg('line', { class: 'grid', x1: margin.left, x2: width - margin.right, y1: y(value), y2: y(value) }));
    const label = svg('text', { class: 'axis', x: margin.left - 8, y: y(value) + 4, 'text-anchor': 'end' });
    label.textContent = number.format(value);
    chart.append(label);
  }

  const labelEvery = Math.max(1, Math.ceil(64 / band));
  rows.forEach((row, index) => {
    const x = margin.left + index * band;
    if (row.jobs > 0) {
      const barHeight = Math.max(1, (row.jobs / top) * innerH);
      chart.append(svg('path', { class: 'bar', d: columnPath(x + (band - barWidth) / 2, y(0) - barHeight, barWidth, barHeight) }));
    }
    // Count from the end so today always gets a label.
    if ((rows.length - 1 - index) % labelEvery === 0) {
      const label = svg('text', { class: 'axis', x: x + band / 2, y: height - 6, 'text-anchor': 'middle' });
      label.textContent = dayFormat.format(utcDay(row.day));
      chart.append(label);
    }
    const hit = svg('rect', { class: 'hit', x, y: margin.top, width: band, height: innerH });
    hit.addEventListener('mouseenter', () => showTooltip(row, x + band / 2));
    hit.addEventListener('mouseleave', hideTooltip);
    chart.append(hit);
  });

  if (!sum(rows, 'jobs')) {
    const empty = svg('text', { class: 'empty', x: margin.left + innerW / 2, y: margin.top + innerH / 2, 'text-anchor': 'middle' });
    empty.textContent = 'No jobs in this period';
    chart.append(empty);
  }

  container.replaceChildren(chart);
  renderDailyTable(rows);
}

function showTooltip(row, centerX) {
  const tooltip = $('tooltip');
  const line = (label, value) => el('div', { className: 'row' }, el('span', {}, label), el('strong', {}, value));
  tooltip.replaceChildren(
    el('div', { className: 'title' }, longDayFormat.format(utcDay(row.day))),
    line('Jobs', number.format(row.jobs)),
    line('Optimized', number.format(row.completed)),
    line('Skipped', number.format(row.skipped)),
    line('Failed', number.format(row.failed)),
    line('Space saved', bytes(row.saved_bytes)),
  );
  tooltip.hidden = false;
  const chart = $('chart');
  const card = chart.parentElement;
  const left = chart.offsetLeft + centerX;
  const room = card.clientWidth - tooltip.offsetWidth - 8;
  tooltip.style.left = `${Math.max(8, Math.min(room, left - tooltip.offsetWidth / 2))}px`;
  tooltip.style.top = `${chart.offsetTop - 8}px`;
}

function hideTooltip() {
  $('tooltip').hidden = true;
}

function renderDailyTable(rows) {
  const head = el('tr', {}, ...['Day', 'Jobs', 'Optimized', 'Skipped', 'Failed', 'Space saved'].map((label, index) => el('th', { className: index ? 'num' : '' }, label)));
  const body = [...rows].reverse().map((row) => el('tr', {},
    el('td', {}, longDayFormat.format(utcDay(row.day))),
    el('td', { className: 'num' }, number.format(row.jobs)),
    el('td', { className: 'num' }, number.format(row.completed)),
    el('td', { className: 'num' }, number.format(row.skipped)),
    el('td', { className: 'num' }, number.format(row.failed)),
    el('td', { className: 'num' }, bytes(row.saved_bytes)),
  ));
  $('daily-table').replaceChildren(el('thead', {}, head), el('tbody', {}, ...body));
}

function keyStatus(key) {
  if (key.kind === 'env') {
    return key.active ? el('span', { className: 'badge good' }, 'Active') : el('span', { className: 'badge neutral' }, 'Not set');
  }
  return key.active ? el('span', { className: 'badge good' }, 'Active') : el('span', { className: 'badge neutral' }, 'Revoked');
}

function renderKeys() {
  const rows = state.data.keys.map((key) => {
    const usage = key.usage;
    const lastUsed = key.kind === 'env' ? usage.last_job_at : key.last_used_at;
    const detail = key.kind === 'env'
      ? 'Set in the service environment'
      : `${key.created_by ? `${key.created_by}, ` : ''}${dateFormat.format(new Date(key.created_at * 1000))}`;
    return el('tr', { className: key.active ? '' : 'inactive' },
      el('td', {}, key.name, el('span', { className: 'by' }, detail)),
      el('td', {}, key.prefix ? el('code', {}, `${key.prefix}…`) : '–'),
      el('td', {}, keyStatus(key)),
      el('td', { title: lastUsed ? new Date(lastUsed * 1000).toLocaleString() : false }, ago(lastUsed)),
      el('td', { className: 'num' }, number.format(usage.jobs || 0)),
      el('td', { className: 'num' }, number.format(usage.failed || 0)),
      el('td', { className: 'num' }, duration(usage.video_seconds)),
      el('td', { className: 'num' }, bytes(usage.input_bytes)),
      el('td', { className: 'num' }, bytes(usage.saved_bytes)),
      el('td', { className: 'num' }, duration(usage.processing_seconds)),
      el('td', {}, key.kind === 'key' && key.active
        ? el('button', { className: 'button small', type: 'button', onclick: () => revoke(key) }, 'Revoke')
        : null),
    );
  });
  $('keys').replaceChildren(...(rows.length ? rows : [el('tr', {}, el('td', { className: 'empty', colspan: 11 }, 'No API keys yet. Create one above.'))]));
}

// ----------------------------------------------------------- actions

async function revoke(key) {
  if (!confirm(`Revoke "${key.name}"? Clients using it stop working immediately.`)) return;
  try {
    await api(`/admin/api/keys/${encodeURIComponent(key.id)}/revoke`, { method: 'POST' });
    await load();
  } catch (error) {
    showError(error);
  }
}

async function createKey(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const button = form.querySelector('button');
  button.disabled = true;
  try {
    const { key, secret } = await api('/admin/api/keys', { method: 'POST', body: { name: form.name.value } });
    $('new-key-name').textContent = key.name;
    $('new-key-secret').textContent = secret;
    $('copy-key').textContent = 'Copy';
    $('new-key').hidden = false;
    form.reset();
    await load();
  } catch (error) {
    showError(error);
  } finally {
    button.disabled = false;
  }
}

async function init() {
  let session;
  try {
    session = await api('/admin/api/session');
  } catch (error) {
    showError(error);
    return;
  }
  state.csrf = session.csrf;
  $('login').textContent = session.user.login;
  if (session.user.avatar_url) {
    $('avatar').src = session.user.avatar_url;
    $('avatar').hidden = false;
  }

  for (const button of document.querySelectorAll('.segmented button')) {
    button.addEventListener('click', () => {
      state.days = Number(button.dataset.days);
      load();
    });
  }
  $('key-filter').addEventListener('change', (event) => {
    state.key = event.target.value;
    render();
  });
  $('create-key').addEventListener('submit', createKey);
  $('copy-key').addEventListener('click', async () => {
    await navigator.clipboard.writeText($('new-key-secret').textContent);
    $('copy-key').textContent = 'Copied';
  });
  $('logout').addEventListener('click', async () => {
    await api('/admin/logout', { method: 'POST' }).catch(() => null);
    location.href = '/admin';
  });

  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => state.data && renderChart(), 100);
  });

  await load();
}

init();
