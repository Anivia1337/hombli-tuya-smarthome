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
  return c;
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

load();
setInterval(() => { if (!document.hidden) load(); }, 5000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
