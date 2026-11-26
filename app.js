const $ = (id) => document.getElementById(id);
const LOCALE = 'en-GB';
const fmt = (n, d = 1) => n.toLocaleString(LOCALE, { minimumFractionDigits: d, maximumFractionDigits: d });

function el(tag, cls, text) {
  const e = document.createElement(tag);
  if (cls) e.className = cls;
  if (text !== undefined) e.textContent = text;
  return e;
}

function card(p) {
  const c = el('article', 'card' + (p.error || !p.on ? ' off' : ''));
  const h = el('h2', '', p.name);
  h.append(el('span', 'pill', p.error ? 'Offline' : p.on ? 'On' : 'Off'));
  c.append(h);
  if (p.error) {
    c.append(el('p', 'err', p.error));
    return c;
  }
  const big = el('p', 'big', fmt(p.w) + ' ');
  big.append(el('small', '', 'W'));
  const dl = el('dl');
  for (const [k, v] of [['Voltage', fmt(p.v) + ' V'], ['Current', fmt(p.ma / 1000, 2) + ' A']]) {
    dl.append(el('dt', '', k), el('dd', '', v));
  }
  c.append(big, dl);
  if (p.switch) {
    const b = el('button', 'switch', p.on ? 'Turn off' : 'Turn on');
    b.type = 'button';
    b.addEventListener('click', () => toggle(p, b));
    c.append(b);
  }
  return c;
}

// Asks first, then switches the plug.
function toggle(p, button) {
  const on = !p.on;
  const d = $('confirm');
  $('confirm-title').textContent = `Turn ${on ? 'on' : 'off'} ${p.name}?`;
  $('confirm-text').textContent = on ? 'The plug will be switched on.' : 'Everything plugged into it loses power.';
  $('confirm-ok').textContent = on ? 'Turn on' : 'Turn off';
  d.onclose = async () => {
    if (d.returnValue !== 'ok') return;
    button.disabled = true;
    button.textContent = 'Switching …';
    try {
      const r = await fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Smarthome': '1' },
        body: JSON.stringify({ name: p.name, on }),
      });
      if (!r.ok) throw new Error((await r.json()).error);
    } catch (e) {
      alert(`Could not switch ${p.name}: ${e.message}`);
    }
    load();
  };
  d.returnValue = '';
  d.showModal();
}

// PM2.5 bands of the European Air Quality Index (µg/m³)
const PM25 = [[10, 'Good'], [20, 'Fair'], [25, 'Moderate'], [50, 'Poor'], [75, 'Very poor'], [Infinity, 'Extremely poor']];

function airCard(a) {
  const c = el('article', 'card' + (a.error || !a.on ? ' off' : ''));
  const h = el('h2', '', a.name);
  h.append(el('span', 'pill', a.error ? 'Offline' : a.on ? 'On' : 'Off'));
  c.append(h);
  if (a.error) {
    c.append(el('p', 'err', a.error));
    return c;
  }
  const big = el('p', 'big', a.pm25 + ' ');
  big.append(el('small', '', 'µg/m³ PM2.5'));
  const dl = el('dl');
  const rows = [
    ['Air quality', PM25.find(([max]) => a.pm25 <= max)[1]],
    ['Humidity', a.humidity + ' %'],
    ['Temperature', fmt(a.temp) + ' °C'],
    ['Mode', a.mode ?? '–'],
    ['Filter', a.filter + ' %'],
  ];
  for (const [k, v] of rows) dl.append(el('dt', '', k), el('dd', '', v));
  c.append(big, dl);
  if (a.fault) c.append(el('p', 'warn', a.fault));
  return c;
}

function usageTable(months, names) {
  const keys = Object.keys(months || {}).reverse();
  $('empty').hidden = keys.length > 0;
  $('months').hidden = keys.length === 0;
  if (!keys.length) return;
  const row = (cells, tag) => {
    const tr = el('tr');
    for (const c of cells) tr.append(el(tag, '', c));
    return tr;
  };
  const now = new Date();
  const current = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
  $('months').tHead.replaceChildren(row(['Month', ...names, 'Total'], 'th'));
  $('months').tBodies[0].replaceChildren(...keys.map((k) => {
    const [y, m] = k.split('-');
    const label = new Date(y, m - 1).toLocaleString(LOCALE, { month: 'long', year: 'numeric' });
    const values = names.map((n) => months[k][n] || 0);
    const kwh = (v) => fmt(v, 2) + ' kWh';
    return row([label + (k === current ? ' (so far)' : ''), ...values.map(kwh), kwh(values.reduce((a, b) => a + b, 0))], 'td');
  }));
}

async function load() {
  try {
    const r = await fetch('api.php', { cache: 'no-store' });
    if (r.status === 401) { location.reload(); return; }
    const d = await r.json();
    $('plugs').replaceChildren(...d.plugs.map(card), ...(d.air ? [airCard(d.air)] : []));
    $('total').textContent = fmt(d.plugs.reduce((s, p) => s + (p.w || 0), 0));
    usageTable(d.months, d.plugs.map((p) => p.name));
    $('updated').textContent = 'Updated ' + new Date(d.time * 1000).toLocaleTimeString(LOCALE);
  } catch {
    $('updated').textContent = 'Connection failed';
  }
}

// SNMP tab: NAS and printer. The server returns rows, bars and an optional table per device.
function snmpCard(d) {
  const c = el('article', 'card' + (d.error ? ' off' : '') + (d.table ? ' wide' : ''));
  const h = el('h2', '', d.name);
  h.append(el('span', 'pill' + (d.warn ? ' bad' : ''), d.error ? 'Offline' : d.status));
  c.append(h);
  if (d.error) {
    c.append(el('p', 'err', d.error));
    return c;
  }
  const dl = el('dl');
  for (const [k, v] of d.rows) dl.append(el('dt', '', k), el('dd', '', v));
  c.append(dl);
  for (const b of d.bars) {
    const m = el('div', 'meter' + (b.warn ? ' low' : ''));
    const top = el('div', 'meter-top');
    top.append(el('span', '', b.label), el('span', '', b.text));
    const fill = el('span');
    fill.style.width = (b.pct ?? 0) + '%';
    const bar = el('div', 'bar');
    bar.append(fill);
    m.append(top, bar);
    if (b.note) m.append(el('span', 'sub', b.note));
    c.append(m);
  }
  if (d.table) {
    const t = el('table');
    const tr = el('tr');
    for (const x of d.table.head) tr.append(el('th', '', x));
    t.createTHead().append(tr);
    const body = t.createTBody();
    for (const r of d.table.rows) {
      const row = el('tr');
      for (const x of r) row.append(el('td', '', x));
      body.append(row);
    }
    const s = el('div', 'scroll');
    s.append(t);
    c.append(s);
  }
  return c;
}

async function loadSnmp() {
  try {
    const r = await fetch('api.php?view=snmp', { cache: 'no-store' });
    if (r.status === 401) { location.reload(); return; }
    const d = await r.json();
    $('snmp').replaceChildren(...d.devices.map(snmpCard));
    $('updated').textContent = 'Updated ' + new Date(d.time * 1000).toLocaleTimeString(LOCALE);
  } catch {
    $('updated').textContent = 'Connection failed';
  }
}

// Tabs live in the URL hash, so #snmp can be bookmarked.
const snmpTab = () => location.hash === '#snmp';
const refresh = () => (snmpTab() ? loadSnmp() : load());
function showTab() {
  $('home').hidden = snmpTab();
  $('snmp').hidden = !snmpTab();
  for (const a of document.querySelectorAll('.tabs a')) {
    if (a.hash === (snmpTab() ? '#snmp' : '#smarthome')) a.setAttribute('aria-current', 'page');
    else a.removeAttribute('aria-current');
  }
  $('updated').textContent = 'loading …';
  refresh();
}

window.addEventListener('hashchange', showTab);
showTab();
setInterval(() => { if (!document.hidden) refresh(); }, 5000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
