/*
 * SDS Scan — office scanner bridge for Sam's Driving School.
 *
 * Runs quietly on the office computer. When a staff member presses "Scan on the office scanner" on the
 * school's website, the website asks this program (on this computer only, http://localhost:52700) to scan,
 * and this program asks the network scanner (Brother DCP-L2550DW or any AirPrint / eSCL scanner) for the
 * picture and hands it back to the page. Nothing is stored here and nothing goes anywhere else.
 *
 * Only pages of samsdrivingschool.org (and its test site) may use it.
 */
'use strict';
const http = require('http');
const https = require('https');
const os = require('os');
const fs = require('fs');
const path = require('path');

const VERSION = '1.0.0';
const PORT = 52700;
const ALLOWED = [/^https:\/\/(www\.)?samsdrivingschool\.org$/, /^https:\/\/testsaman\.samsdrivingschool\.org$/];
if (process.env.SDS_SCAN_DEV_ORIGIN) { ALLOWED.push(new RegExp('^' + process.env.SDS_SCAN_DEV_ORIGIN.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$')); }

/* ---------------------------------------------------------------- settings & log */
const HOME = process.platform === 'win32'
  ? path.join(process.env.APPDATA || path.join(os.homedir(), 'AppData', 'Roaming'), 'SDS-Scan')
  : process.platform === 'darwin' ? path.join(os.homedir(), 'Library', 'Application Support', 'SDS-Scan') : path.join(os.homedir(), '.sds-scan');
try { fs.mkdirSync(HOME, { recursive: true }); } catch (e) { /* ignore */ }
const CFG = path.join(HOME, 'config.json');
const LOG = path.join(HOME, 'sds-scan.log');
function log(...a) {
  const line = new Date().toISOString() + ' ' + a.map(x => (typeof x === 'string' ? x : JSON.stringify(x))).join(' ') + '\n';
  try { if (fs.existsSync(LOG) && fs.statSync(LOG).size > 512000) { fs.renameSync(LOG, LOG + '.old'); } fs.appendFileSync(LOG, line); } catch (e) { /* ignore */ }
  if (process.stdout.isTTY) { process.stdout.write(line); }
}
function readCfg() { try { return JSON.parse(fs.readFileSync(CFG, 'utf8')); } catch (e) { return {}; } }
function writeCfg(c) { try { fs.writeFileSync(CFG, JSON.stringify(c, null, 2)); } catch (e) { log('config write failed', e.message); } }

/* ---------------------------------------------------------------- small HTTP client for the scanner */
function req(url, opt = {}) {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    const lib = u.protocol === 'https:' ? https : http;
    const r = lib.request({
      hostname: u.hostname, port: u.port || (u.protocol === 'https:' ? 443 : 80), path: u.pathname + u.search, method: opt.method || 'GET',
      headers: Object.assign({ 'User-Agent': 'SDS-Scan/' + VERSION }, opt.body ? { 'Content-Type': 'text/xml', 'Content-Length': Buffer.byteLength(opt.body) } : {}, opt.headers || {}),
      timeout: opt.timeout || 8000,
      rejectUnauthorized: false, // printers use their own self-made certificate; this client only ever talks to the scanner
    }, res => {
      const chunks = [];
      res.on('data', c => chunks.push(c));
      res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: Buffer.concat(chunks) }));
    });
    r.on('timeout', () => r.destroy(new Error('timeout')));
    r.on('error', reject);
    if (opt.body) { r.write(opt.body); }
    r.end();
  });
}
const sleep = ms => new Promise(r => setTimeout(r, ms));
function tag(xml, name) { const m = new RegExp('<(?:\\w+:)?' + name + '>([^<]*)</(?:\\w+:)?' + name + '>').exec(xml); return m ? m[1].trim() : ''; }

/* ---------------------------------------------------------------- finding the scanner */
async function probe(base) {
  try {
    const r = await req(base + '/ScannerCapabilities', { timeout: 2500 });
    if (r.status !== 200) { return null; }
    const x = r.body.toString('utf8');
    if (!/ScannerCapabilities/.test(x)) { return null; }
    return { base, model: tag(x, 'MakeAndModel') || 'eSCL scanner', platen: /Platen/.test(x) };
  } catch (e) { return null; }
}
async function probeHost(ip, port, rs) {
  const tries = [];
  const p = rs ? '/' + rs.replace(/^\/+/, '') : '/eSCL';
  if (port) { tries.push((port === 443 ? 'https' : 'http') + '://' + ip + ':' + port + p); }
  tries.push('http://' + ip + p, 'https://' + ip + p);
  for (const b of tries) { const s = await probe(b); if (s) { return s; } }
  return null;
}
/** Bonjour / mDNS: _uscan._tcp (eSCL over http) and _uscans._tcp (https). */
function mdns(ms = 3000) {
  return new Promise(resolve => {
    let m;
    try { m = require('multicast-dns')(); } catch (e) { resolve([]); return; }
    const found = new Map();
    const srv = {};
    const txt = {};
    const addr = {};
    m.on('response', res => {
      for (const a of [].concat(res.answers || [], res.additionals || [])) {
        if (a.type === 'SRV') { srv[a.name] = { port: a.data.port, target: a.data.target }; }
        if (a.type === 'TXT') { const t = {}; (a.data || []).forEach(b => { const s = b.toString(); const i = s.indexOf('='); if (i > 0) { t[s.slice(0, i).toLowerCase()] = s.slice(i + 1); } }); txt[a.name] = t; }
        if (a.type === 'A') { addr[a.name] = a.data; }
      }
      for (const n of Object.keys(srv)) {
        const ip = addr[srv[n].target];
        if (ip && !found.has(n)) { found.set(n, { ip, port: srv[n].port, rs: (txt[n] || {}).rs || 'eSCL', name: (txt[n] || {}).ty || n.split('._')[0] }); }
      }
    });
    m.on('error', () => {});
    try { m.query({ questions: [{ name: '_uscan._tcp.local', type: 'PTR' }, { name: '_uscans._tcp.local', type: 'PTR' }] }); } catch (e) { /* ignore */ }
    setTimeout(() => { try { m.query({ questions: [{ name: '_uscan._tcp.local', type: 'PTR' }] }); } catch (e) {} }, 1000);
    setTimeout(() => { try { m.destroy(); } catch (e) {} resolve([...found.values()]); }, ms);
  });
}
/** Last resort: try every address of this computer's network (x.x.x.1–254). */
async function sweep() {
  const nets = [];
  for (const list of Object.values(os.networkInterfaces())) {
    for (const n of list || []) { if (n.family === 'IPv4' && !n.internal && /^(10|172|192)\./.test(n.address)) { nets.push(n.address.split('.').slice(0, 3).join('.')); } }
  }
  const ips = [];
  [...new Set(nets)].forEach(p => { for (let i = 1; i < 255; i++) { ips.push(p + '.' + i); } });
  let hit = null;
  const queue = ips.slice();
  async function worker() {
    while (queue.length && !hit) {
      const ip = queue.shift();
      const s = await probe('http://' + ip + '/eSCL').catch(() => null);
      if (s && !hit) { hit = s; }
    }
  }
  await Promise.all(Array.from({ length: 48 }, worker));
  return hit;
}
let finding = null;
async function scanner(force) {
  const cfg = readCfg();
  if (!force && cfg.base) {
    const s = await probe(cfg.base);
    if (s) { return s; }
    log('saved scanner not answering:', cfg.base);
  }
  if (cfg.ip) {
    const s = await probeHost(cfg.ip);
    if (s) { cfg.base = s.base; cfg.model = s.model; writeCfg(cfg); return s; }
  }
  if (!finding) {
    finding = (async () => {
      log('looking for the scanner (Bonjour)…');
      const list = await mdns();
      list.sort((a, b) => (/brother/i.test(b.name) ? 1 : 0) - (/brother/i.test(a.name) ? 1 : 0));
      for (const d of list) { const s = await probeHost(d.ip, d.port, d.rs); if (s) { return s; } }
      log('Bonjour found nothing usable; trying the local network…');
      return sweep();
    })().finally(() => { setTimeout(() => { finding = null; }, 0); });
  }
  const s = await finding;
  if (s) { const c = readCfg(); c.base = s.base; c.model = s.model; writeCfg(c); log('scanner:', s.model, s.base); }
  return s;
}

/* ---------------------------------------------------------------- scanning */
let busy = false;
async function scan(o) {
  const s = await scanner(false);
  if (!s) { throw Object.assign(new Error('The office scanner was not found on the network. Check that the Brother is on and on the same Wi-Fi, or enter its IP address.'), { code: 404 }); }
  const dpi = [150, 200, 300, 600].includes(+o.dpi) ? +o.dpi : 300;
  // Region in 1/300 inch, from the top-left corner of the glass. Default: the whole Letter glass.
  const W = Math.round((+o.w || 8.5) * 300), H = Math.round((+o.h || 11) * 300);
  const color = o.gray ? 'Grayscale8' : 'RGB24';
  const xml = '<?xml version="1.0" encoding="UTF-8"?>\n' +
    '<scan:ScanSettings xmlns:scan="http://schemas.hp.com/imaging/escl/2011/05/03" xmlns:pwg="http://www.pwg.org/schemas/2010/12/sm">' +
    '<pwg:Version>2.0</pwg:Version>' +
    '<pwg:ScanRegions><pwg:ScanRegion><pwg:ContentRegionUnits>escl:ThreeHundredthsOfInches</pwg:ContentRegionUnits><pwg:XOffset>0</pwg:XOffset><pwg:YOffset>0</pwg:YOffset><pwg:Width>' + W + '</pwg:Width><pwg:Height>' + H + '</pwg:Height></pwg:ScanRegion></pwg:ScanRegions>' +
    '<pwg:InputSource>Platen</pwg:InputSource>' +
    '<scan:ColorMode>' + color + '</scan:ColorMode>' +
    '<scan:XResolution>' + dpi + '</scan:XResolution><scan:YResolution>' + dpi + '</scan:YResolution>' +
    '<pwg:DocumentFormat>image/jpeg</pwg:DocumentFormat><scan:DocumentFormatExt>image/jpeg</scan:DocumentFormatExt>' +
    '</scan:ScanSettings>';
  let job = null;
  for (let i = 0; i < 6 && !job; i++) {
    const r = await req(s.base + '/ScanJobs', { method: 'POST', body: xml, timeout: 15000 });
    if (r.status === 201 && r.headers.location) { job = r.headers.location; break; }
    if (r.status === 503) { await sleep(1500); continue; } // busy (warming up / another job)
    throw Object.assign(new Error('The scanner refused the scan (' + r.status + '). Is the cover closed and the glass free?'), { code: 502 });
  }
  if (!job) { throw Object.assign(new Error('The scanner is busy. Try again in a moment.'), { code: 503 }); }
  const jobUrl = /^https?:/.test(job) ? job : new URL(job, s.base).toString();
  log('scan job', jobUrl, dpi + 'dpi', W + 'x' + H);
  for (let i = 0; i < 60; i++) {
    const r = await req(jobUrl.replace(/\/$/, '') + '/NextDocument', { timeout: 90000 });
    if (r.status === 200 && r.body.length > 1000) { return { img: r.body, type: r.headers['content-type'] || 'image/jpeg', model: s.model }; }
    if (r.status === 503 || r.status === 404 && i < 3) { await sleep(1000); continue; }
    throw Object.assign(new Error('The scanner did not return the picture (' + r.status + ').'), { code: 502 });
  }
  throw Object.assign(new Error('The scan took too long.'), { code: 504 });
}

/* ---------------------------------------------------------------- the local service */
function cors(req0, res) {
  const o = req0.headers.origin || '';
  if (ALLOWED.some(r => r.test(o))) {
    res.setHeader('Access-Control-Allow-Origin', o);
    res.setHeader('Vary', 'Origin');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
    res.setHeader('Access-Control-Allow-Private-Network', 'true');
    res.setHeader('Access-Control-Expose-Headers', 'X-Scanner');
    return true;
  }
  return false;
}
function json(res, code, data) { res.writeHead(code, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }); res.end(JSON.stringify(data)); }

const server = http.createServer(async (rq, res) => {
  const ok = cors(rq, res);
  if (rq.method === 'OPTIONS') { res.writeHead(ok ? 204 : 403); res.end(); return; }
  if (!ok) { json(res, 403, { ok: false, msg: 'Only the Sam’s Driving School website can use this scanner bridge.' }); return; }
  const u = new URL(rq.url, 'http://localhost');
  try {
    if (u.pathname === '/status') {
      const s = await scanner(u.searchParams.get('find') === '1');
      json(res, 200, { ok: true, version: VERSION, scanner: s ? { model: s.model, base: s.base.replace(/\/eSCL.*$/i, '') } : null });
      return;
    }
    if (u.pathname === '/config' && rq.method === 'POST') {
      const ip = (u.searchParams.get('ip') || '').trim();
      if (!/^[\w.\-:]+$/.test(ip)) { json(res, 400, { ok: false, msg: 'Enter the scanner IP address, e.g. 192.168.1.40' }); return; }
      const s = await probeHost(ip);
      if (!s) { json(res, 404, { ok: false, msg: 'No scanner answered at ' + ip + '.' }); return; }
      writeCfg({ ip, base: s.base, model: s.model });
      json(res, 200, { ok: true, scanner: { model: s.model } });
      return;
    }
    if (u.pathname === '/scan' && rq.method === 'POST') {
      if (busy) { json(res, 409, { ok: false, msg: 'A scan is already running.' }); return; }
      busy = true;
      try {
        const r = await scan({ dpi: u.searchParams.get('dpi'), w: u.searchParams.get('w'), h: u.searchParams.get('h'), gray: u.searchParams.get('gray') === '1' });
        res.writeHead(200, { 'Content-Type': r.type, 'Content-Length': r.img.length, 'X-Scanner': r.model, 'Cache-Control': 'no-store' });
        res.end(r.img);
      } finally { busy = false; }
      return;
    }
    json(res, 404, { ok: false, msg: 'Unknown request.' });
  } catch (e) {
    log('error', e.message);
    json(res, e.code && e.code >= 400 && e.code < 600 ? e.code : 500, { ok: false, msg: e.message });
  }
});
server.on('error', e => {
  if (e.code === 'EADDRINUSE') { log('already running — this copy closes.'); process.exit(0); }
  log('server error', e.message); process.exit(1);
});
server.listen(PORT, '127.0.0.1', () => {
  log('SDS Scan ' + VERSION + ' ready on http://localhost:' + PORT);
  scanner(false).then(s => log(s ? 'scanner ready: ' + s.model : 'scanner not found yet')).catch(() => {});
});
module.exports = { scan, scanner, probe };
