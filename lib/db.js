const { neon } = require('@neondatabase/serverless');

function database() {
  if (!process.env.DATABASE_URL) throw new Error('DATABASE_URL is not configured');
  return neon(process.env.DATABASE_URL);
}

async function ensureTable(sql) {
  await sql`CREATE TABLE IF NOT EXISTS guidance_enquiries (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    contact_number VARCHAR(30) NOT NULL,
    email VARCHAR(254) NOT NULL,
    topic VARCHAR(80) NOT NULL,
    message TEXT NOT NULL DEFAULT '',
    consent_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
  )`;
}

module.exports = { database, ensureTable };
