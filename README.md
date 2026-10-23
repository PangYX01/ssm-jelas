# SSM Jelas — Vercel version

This package is the Vercel-ready version of the standalone SSM Jelas v3 checker.

## Redesigned version

This version applies the selected existing-site hybrid design to the supplied `osr.zip`, with the requested restorations. The full-width skyline hero, separate image-and-text basics and document sections, and photographic Other SSM Services section use their previous layouts. Refreshed typography is retained. The three about-strip items have matching widths, padding, and icon alignment; separator lines are centred on column boundaries and become horizontal on mobile. The guidance photographs, renewal information, full enquiry form, and simplified official-services directory are retained.

The Suruhanjaya Syarikat Malaysia and Business Information topics share one destination in the official-services directory. Their headings and explanatory text are retained; the duplicate "Find on SSM Website" link is consolidated into "Official SSM Website". SSM4U remains a separate destination.

All public-page styling is contained in one `style.css`; no separate redesign stylesheet is required. The page JavaScript, backend, admin page, images, documents, and Vercel configuration are retained. All other public-page copy and form attributes were compared with the ZIP source and verified unchanged.

Public-page typography is controlled by the tokens at the top of `style.css`: H1 48px, H2 32px, H3 20px, H4 18px, body text 16px, labels/buttons/small text 14px, and section kickers 12px (at the browser's default 16px base size). Section-specific and breakpoint-specific font sizes have been removed. All ordinary paragraphs use the body size; kicker paragraphs use the kicker size. The admin interface retains its own stylesheet.

Desktop, tablet, and mobile breakpoints are included. Browser rendering was not verified in the authoring environment because its local HTTP preview was inaccessible and direct local-file browsing was blocked. Review the deployed preview on desktop and mobile before publishing it to your live domain. The database-backed submission flow still requires the Vercel environment variables below.

## Files
- `index.html` — current v3 frontend with automatic SSM number checking.
- `style.css` — all page styling, including named classes replacing the original inline styles. Rules are formatted in readable blocks; global fonts, colors, and existing cascade order are retained.
- `api/ssm-status.js` — Vercel Node.js serverless API that performs the SSM e-Search lookup.
- `api/enquiries.js` — validates and saves guidance enquiries.
- `admin/index.html` — enquiry inbox at `/admin/`, with records protected by the admin API.
- `api/admin.js` — admin sign-in and read-only record API.
- `package.json` — installs the HTML parser used by the serverless function.
- `vercel.json` — gives the lookup function enough execution time for the SSM request/canary flow.

## Deploy
1. Put these files in the root of your GitHub repository.
2. Push/commit to GitHub.
3. Import or redeploy the repository in Vercel.
4. Vercel will run `npm install` automatically and expose the checker endpoint at `/api/ssm-status`.

## Enquiry form and admin setup

The guidance form needs a PostgreSQL database. Before deploying, add these environment variables to the Vercel project:

- `DATABASE_URL`: a PostgreSQL connection string with permission to create and use the `guidance_enquiries` table. A Neon pooled connection string works with the included driver.
- `ADMIN_PASSWORD`: a long, unique password for `/admin/`.
- `SESSION_SECRET`: a separate, random secret of at least 32 bytes for signing admin sessions (for example, generate one with `node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"`).

Redeploy after setting the variables. The first submission creates the table automatically. Visit `/admin/`, sign in, and view up to the 500 newest enquiries. Sessions expire after 12 hours; the page supports refresh and sign-out. The admin page never embeds records in the public HTML. Keep all three environment variables private and do not commit them to the repository.

The form requires name, contact number, email, topic, and consent. The message is optional. The API validates these fields again before storing them. A failed database write returns an error to the visitor instead of showing a success message. The privacy section explains what the form stores.

No PHP is required in this version.

## Styling maintenance

Keep visual declarations in `style.css`. The HTML contains no inline `style` attributes or embedded style blocks. The JavaScript remains in the HTML as before.

Source checks confirm the original page JavaScript, form fields, content, API handlers, assets, and link destinations are preserved, apart from the explicitly consolidated official-services link. Browser layout and interaction checks remain to be performed in the deployed preview.

## Accepted SSM number formats
The same formats used by the existing One Stop validation flow are retained: 12 digits, or old 9-character formats (`123456789`, `A12345678`, `AB1234567`) with an optional suffix such as `-H`.

## Important
The lookup depends on the external SSM e-Search page continuing to accept server-side requests and retaining its current HTML/form structure. If SSM changes or blocks requests from Vercel infrastructure, the checker will return `unavailable` and the API will need to be adjusted.
