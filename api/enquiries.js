const { database, ensureTable } = require('../lib/db');

const topics = new Set([
  'SSM renewal or expiry',
  'Business registration guidance',
  'Business documents or information',
  'Business information updates',
  'Other SSM-related question'
]);

module.exports = async function handler(req, res) {
  res.setHeader('Cache-Control', 'no-store');
  if (req.method !== 'POST') { res.setHeader('Allow', 'POST'); return res.status(405).json({ error: 'Method not allowed' }); }
  const origin = req.headers.origin;
  if (origin && origin !== `https://${req.headers.host}` && origin !== `http://${req.headers.host}`) return res.status(403).json({ error: 'Invalid origin' });
  if (Number(req.headers['content-length'] || 0) > 12000) return res.status(413).json({ error: 'Enquiry is too long' });
  const body = req.body || {};
  const name = String(body.name || '').trim();
  const contact = String(body.contactNumber || '').trim();
  const email = String(body.email || '').trim();
  const topic = String(body.topic || '');
  const message = String(body.message || '').trim();
  if (!name || name.length > 120 || !/^[+\d][\d\s()+.-]{5,29}$/.test(contact) ||
      !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || email.length > 254 ||
      !topics.has(topic) || message.length > 3000 || body.consent !== true) {
    return res.status(400).json({ error: 'Please complete all required fields and accept the consent statement.' });
  }
  try {
    const sql = database();
    await ensureTable(sql);
    await sql`INSERT INTO guidance_enquiries (name, contact_number, email, topic, message, consent_at)
      VALUES (${name}, ${contact}, ${email}, ${topic}, ${message}, NOW())`;
    return res.status(201).json({ ok: true });
  } catch (error) {
    console.error('Enquiry storage failed:', error);
    return res.status(503).json({ error: 'Unable to save your enquiry right now. Please try again later.' });
  }
};

