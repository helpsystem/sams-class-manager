/*
 * SDS Scan — office scanner bridge for Sam's Driving School.
 *
 * Runs quietly on the office computer. When a staff member presses "Scan on the office scanner" on the
 * school's website, the website asks this program (on this computer only, http://localhost:52700) to scan,
 * and this program asks the network scanner (Brother DCP-L2550DW or any AirPrint / eSCL scanner) for the
 * picture (from the glass or the document feeder) and hands it back to the page. Nothing is kept on disk and
 * nothing goes anywhere else.
 *
 * Only pages of samsdrivingschool.org (and its test site) may use it.
 */
'use strict';
const http = require('http');
const https = require('https');
const os = require('os');
const fs = require('fs');
const path = require('path');

const VERSION = '1.2.0';
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
      agent: u.protocol === 'https:' ? AG.https : AG.http,
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
const AG = { http: new http.Agent({ keepAlive: true, maxSockets: 4 }), https: new https.Agent({ keepAlive: true, maxSockets: 4, rejectUnauthorized: false }) };
const sleep = ms => new Promise(r => setTimeout(r, ms));
function tag(xml, name) { const m = new RegExp('<(?:\\w+:)?' + name + '>([^<]*)</(?:\\w+:)?' + name + '>').exec(xml); return m ? m[1].trim() : ''; }

/* ---------------------------------------------------------------- finding the scanner */
async function probe(base) {
  try {
    const r = await req(base + '/ScannerCapabilities', { timeout: 2500 });
    if (r.status !== 200) { return null; }
    const x = r.body.toString('utf8');
    if (!/ScannerCapabilities/.test(x)) { return null; }
    // Size of the glass (in 1/300 inch) from the platen section.
    const pl = (/<scan:Platen>([\s\S]*?)<\/scan:Platen>/.exec(x) || [])[1] || x;
    const mw = +tag(pl, 'MaxWidth') || 2550, mh = +tag(pl, 'MaxHeight') || 3508;
    // Document feeder (ADF): present, its largest page, and whether it scans both sides by itself.
    const ad = (/<scan:Adf>([\s\S]*?)<\/scan:Adf>/.exec(x) || [])[1] || '';
    const adS = (/<scan:AdfSimplexInputCaps>([\s\S]*?)<\/scan:AdfSimplexInputCaps>/.exec(ad) || [])[1] || ad;
    return {
      base, model: tag(x, 'MakeAndModel') || 'eSCL scanner', platen: /Platen/.test(x), maxW: mw, maxH: mh,
      adf: !!ad, duplex: /AdfDuplexInputCaps/.test(ad), adfW: +tag(adS, 'MaxWidth') || 2550, adfH: +tag(adS, 'MaxHeight') || 4200,
    };
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
let known = null, knownAt = 0;
async function scanner(force) {
  if (!force && known && Date.now() - knownAt < 120000) { return known; }
  const s0 = await scanner0(force);
  if (s0) { known = s0; knownAt = Date.now(); }
  return s0;
}
async function scanner0(force) {
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
let cleanup = null;
async function scan(o) {
  if (cleanup) { await cleanup; cleanup = null; }
  const s = await scanner(false);
  if (!s) { throw Object.assign(new Error('The office scanner was not found on the network. Check that the Brother is on and on the same Wi-Fi, or enter its IP address.'), { code: 404 }); }
  const dpi = [75, 100, 150, 200, 300, 600].includes(+o.dpi) ? +o.dpi : 300;
  const feeder = o.source === 'adf';
  if (feeder && !s.adf) { throw Object.assign(new Error('This scanner has no document feeder. Use the glass.'), { code: 400 }); }
  if (feeder) { await feederReady(s); }
  // Region in 1/300 inch, from the top-left corner of the glass. Default: the whole Letter glass.
  // Region in 1/300 inch from the scanner's own corner; "full" = the whole glass.
  const MW = feeder ? (s.adfW || 2550) : (s.maxW || 2550), MH = feeder ? (s.adfH || 4200) : (s.maxH || 3508);
  // Page size: letter 8.5 x 11 in, legal 8.5 x 14 in (in 1/300 inch); otherwise the whole glass / the largest feeder page.
  const PAPER = { letter: [2550, 3300], legal: [2550, 4200] }[o.paper] || null;
  const X = Math.max(0, Math.min(MW - 30, Math.round((+o.x || 0) * 300))), Y = Math.max(0, Math.min(MH - 30, Math.round((+o.y || 0) * 300)));
  const W = Math.min(MW - X, o.w ? Math.round(+o.w * 300) : (PAPER ? PAPER[0] : MW)), H = Math.min(MH - Y, o.h ? Math.round(+o.h * 300) : (PAPER ? PAPER[1] : MH));
  const color = o.gray ? 'Grayscale8' : 'RGB24';
  const xml = '<?xml version="1.0" encoding="UTF-8"?>\n' +
    '<scan:ScanSettings xmlns:scan="http://schemas.hp.com/imaging/escl/2011/05/03" xmlns:pwg="http://www.pwg.org/schemas/2010/12/sm">' +
    '<pwg:Version>2.0</pwg:Version>' +
    '<pwg:ScanRegions><pwg:ScanRegion><pwg:ContentRegionUnits>escl:ThreeHundredthsOfInches</pwg:ContentRegionUnits><pwg:XOffset>' + X + '</pwg:XOffset><pwg:YOffset>' + Y + '</pwg:YOffset><pwg:Width>' + W + '</pwg:Width><pwg:Height>' + H + '</pwg:Height></pwg:ScanRegion></pwg:ScanRegions>' +
    '<pwg:InputSource>' + (feeder ? 'Feeder' : 'Platen') + '</pwg:InputSource>' +
    (feeder ? '<scan:Duplex>' + (o.duplex && s.duplex ? 'true' : 'false') + '</scan:Duplex>' : '') +
    '<scan:ColorMode>' + color + '</scan:ColorMode>' +
    '<scan:XResolution>' + dpi + '</scan:XResolution><scan:YResolution>' + dpi + '</scan:YResolution>' +
    '<pwg:DocumentFormat>image/jpeg</pwg:DocumentFormat><scan:DocumentFormatExt>image/jpeg</scan:DocumentFormatExt>' +
    '</scan:ScanSettings>';
  let job = null;
  for (let i = 0; i < 12 && !job; i++) {
    const r = await req(s.base + '/ScanJobs', { method: 'POST', body: xml, timeout: 15000 });
    if (r.status === 201 && r.headers.location) { job = r.headers.location; break; }
    if (r.status === 503) { await sleep(800); continue; } // busy (warming up / another job)
    throw Object.assign(new Error('The scanner refused the scan (' + r.status + '). Is the cover closed and the glass free?'), { code: 502 });
  }
  if (!job) { throw Object.assign(new Error('The scanner is busy. Try again in a moment.'), { code: 503 }); }
  const jobUrl = /^https?:/.test(job) ? job : new URL(job, s.base).toString();
  log('scan job', jobUrl, dpi + 'dpi', W + 'x' + H, feeder ? 'feeder' + (o.duplex ? ' duplex' : '') : 'glass');
  if (feeder) { return feederPages(jobUrl, s); }
  for (let i = 0; i < 60; i++) {
    const r = await req(jobUrl.replace(/\/$/, '') + '/NextDocument', { timeout: 90000 });
    if (r.status === 200 && r.body.length > 1000) {
      // Close the job the way the scanner expects (ask for the next page → 404), so the next scan starts at once.
      cleanup = req(jobUrl.replace(/\/$/, '') + '/NextDocument', { timeout: 15000 }).catch(() => null).then(() => null);
      return { img: r.body, type: r.headers['content-type'] || 'image/jpeg', model: s.model, glass: MW + 'x' + MH }; }
    if (r.status === 503 || (r.status === 404 && i < 3)) { await sleep(400); continue; }
    throw Object.assign(new Error('The scanner did not return the picture (' + r.status + ').'), { code: 502 });
  }
  throw Object.assign(new Error('The scan took too long.'), { code: 504 });
}

/** Feeder: is there paper in it? (eSCL ScannerStatus → AdfState) */
async function feederReady(s) {
  try {
    const r = await req(s.base + '/ScannerStatus', { timeout: 4000 });
    const st = tag(r.body.toString('utf8'), 'AdfState');
    if (/Empty/i.test(st)) { throw Object.assign(new Error('The document feeder is empty. Put the pages in the feeder (face up, top edge first) and try again.'), { code: 409 }); }
    if (/Jam/i.test(st)) { throw Object.assign(new Error('Paper is jammed in the feeder. Clear it and try again.'), { code: 409 }); }
    if (/Open/i.test(st)) { throw Object.assign(new Error('The feeder cover is open. Close it and try again.'), { code: 409 }); }
  } catch (e) { if (e.code === 409) { throw e; } /* status not readable: just try the scan */ }
}
/** Every page the feeder gives, until it says there is none left (404). */
async function feederPages(jobUrl, s) {
  const pages = [];
  for (let i = 0; i < 400 && pages.length < 120; i++) {
    const r = await req(jobUrl.replace(/\/$/, '') + '/NextDocument', { timeout: 90000 });
    if (r.status === 200 && r.body.length > 1000) { pages.push(r.body); continue; }
    if (r.status === 503) { await sleep(400); continue; }
    if (r.status === 404 && (pages.length || i >= 3)) { break; }
    if (r.status === 404) { await sleep(500); continue; }
    if (pages.length) { break; }
    throw Object.assign(new Error('The scanner did not return the pages (' + r.status + ').'), { code: 502 });
  }
  if (!pages.length) { throw Object.assign(new Error('No pages came from the feeder. Check that the pages are in the feeder face up.'), { code: 409 }); }
  log('feeder:', pages.length, 'page(s)');
  return { pages, model: s.model };
}
let lastJob = { id: '', pages: [] };

/* ---------------------------------------------------------------- the local service */
function cors(req0, res) {
  const o = req0.headers.origin || '';
  if (ALLOWED.some(r => r.test(o))) {
    res.setHeader('Access-Control-Allow-Origin', o);
    res.setHeader('Vary', 'Origin');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
    res.setHeader('Access-Control-Allow-Private-Network', 'true');
    res.setHeader('Access-Control-Expose-Headers', 'X-Scanner, X-Glass');
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
      json(res, 200, { ok: true, version: VERSION, scanner: s ? { model: s.model, base: s.base.replace(/\/eSCL.*$/i, ''), glass: [s.maxW || 2550, s.maxH || 3508], adf: !!s.adf, duplex: !!s.duplex, adfMax: [s.adfW || 0, s.adfH || 0] } : null });
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
        const q = u.searchParams;
        const r = await scan({ dpi: q.get('dpi'), x: q.get('x'), y: q.get('y'), w: q.get('w'), h: q.get('h'), gray: q.get('gray') === '1', source: q.get('source'), duplex: q.get('duplex') === '1', paper: q.get('paper') });
        if (r.pages) {
          // Feeder: the pages stay here for a moment; the page fetches them one by one (/page?id=…&i=0…).
          lastJob = { id: Date.now().toString(36) + Math.random().toString(36).slice(2, 8), pages: r.pages };
          json(res, 200, { ok: true, id: lastJob.id, pages: r.pages.length, model: r.model });
          return;
        }
        res.writeHead(200, { 'Content-Type': r.type, 'Content-Length': r.img.length, 'X-Scanner': r.model, 'X-Glass': r.glass, 'Cache-Control': 'no-store' });
        res.end(r.img);
      } finally { busy = false; }
      return;
    }
    if (u.pathname === '/page') {
      const i = +u.searchParams.get('i');
      const img = lastJob.id && u.searchParams.get('id') === lastJob.id ? lastJob.pages[i] : null;
      if (!img) { json(res, 404, { ok: false, msg: 'That page is no longer here. Scan again.' }); return; }
      res.writeHead(200, { 'Content-Type': 'image/jpeg', 'Content-Length': img.length, 'Cache-Control': 'no-store' });
      res.end(img);
      if (i === lastJob.pages.length - 1) { setTimeout(() => { if (lastJob.pages[i] === img) { lastJob = { id: '', pages: [] }; } }, 60000); }
      return;
    }
    json(res, 404, { ok: false, msg: 'Unknown request.' });
  } catch (e) {
    log('error', e.message);
    known = null;
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
