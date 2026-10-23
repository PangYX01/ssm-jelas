const crypto = require('node:crypto');
const { database, ensureTable } = require('../lib/db');

const maxAge = 12 * 60 * 60;
function sign(value) { return crypto.createHmac('sha256', process.env.SESSION_SECRET).update(value).digest('hex'); }
function isAuthenticated(req) {
  if (!process.env.SESSION_SECRET) return false;
  const match = (req.headers.cookie || '').match(/(?:^|;\s*)ssm_admin=([^;]+)/);
  if (!match) return false;
  const [issued, mac] = decodeURIComponent(match[1]).split('.');
  if (!/^\d+$/.test(issued) || !/^[a-f0-9]{64}$/.test(mac)) return false;
  if (Date.now() - Number(issued) > maxAge * 1000 || Number(issued) > Date.now()) return false;
  return crypto.timingSafeEqual(Buffer.from(mac), Buffer.from(sign(issued)));
}
function sameOrigin(req) {
  const origin = req.headers.origin;
  return !origin || origin === `https://${req.headers.host}` || origin === `http://${req.headers.host}`;
}
module.exports = async function handler(req, res) {
  res.setHeader('Cache-Control', 'no-store');
  res.setHeader('X-Content-Type-Options', 'nosniff');
  if (!process.env.ADMIN_PASSWORD || !process.env.SESSION_SECRET) return res.status(503).json({ error: 'Admin access is not configured.' });
  if (req.method === 'POST' && !sameOrigin(req)) return res.status(403).json({ error: 'Invalid origin' });
  if (req.method === 'POST' && req.body?.action === 'login') {
    const input = Buffer.from(String(req.body.password || ''));
    const expected = Buffer.from(process.env.ADMIN_PASSWORD);
    if (input.length !== expected.length || !crypto.timingSafeEqual(input, expected)) return res.status(401).json({ error: 'Incorrect password.' });
    const issued = String(Date.now());
    res.setHeader('Set-Cookie', `ssm_admin=${issued}.${sign(issued)}; HttpOnly; Secure; SameSite=Strict; Path=/api/admin; Max-Age=${maxAge}`);
    return res.status(200).json({ ok: true });
  }
  if (req.method === 'POST' && req.body?.action === 'logout') {
    res.setHeader('Set-Cookie', 'ssm_admin=; HttpOnly; Secure; SameSite=Strict; Path=/api/admin; Max-Age=0');
    return res.status(200).json({ ok: true });
  }
  if (req.method !== 'GET') { res.setHeader('Allow', 'GET, POST'); return res.status(405).json({ error: 'Method not allowed' }); }
  if (!isAuthenticated(req)) return res.status(401).json({ error: 'Please sign in.' });
  try {
    const sql = database();
    await ensureTable(sql);
    const records = await sql`SELECT id, name, contact_number, email, topic, message, consent_at, created_at
      FROM guidance_enquiries ORDER BY created_at DESC, id DESC LIMIT 500`;
    return res.status(200).json({ records });
  } catch (error) {
    console.error('Admin enquiry load failed:', error);
    return res.status(503).json({ error: 'Unable to load enquiries right now.' });
  }
};

