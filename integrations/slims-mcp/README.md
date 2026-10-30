# Slim's Event Manager — ChatGPT/MCP Integration

This integration wraps the existing [Arkon Event Manager](../../) WordPress REST API without changing the calendar's data model or publishing drafts. **The source and Cloudflare OAuth KV are provisioned; the two Workers and ChatGPT plugin are not deployed or connected yet.** The preview tool returns a real WordPress draft preview URL, **not a screenshot**.

## Existing WordPress contract

- Events: `/wp-json/wp/v2/abm_event`, custom post type `abm_event`.
- Categories: `/wp-json/wp/v2/abm_category`.
- Image uploads: `/wp-json/wp/v2/media`; set `featured_media` on the event.
- Event data: `meta.abm_event_date` (YYYY-MM-DD), `meta.abm_event_time_start` and `meta.abm_event_time_end` (HH:mm, or `close` for end), `meta.abm_event_cost` (`none` for no cover).
- Server-side REST save hook rebuilds occurrence records; the public calendar lists only published posts.
- An event ending at 02:00 after starting at 13:00 belongs to the **starting** date; the plugin handles overnight rollover. Imported multiple-date occurrence sets may be locked, inspect `abm_occurrences.locked` before editing a date.

## Components

- `src/index.mjs`: limited WordPress REST proxy and nine MCP tools. Has an internal bearer check; do not expose this file directly as the deployed Worker entrypoint.
- `src/oauth.mjs`: **public-facing** MCP resource server. Validates OAuth access tokens over a Cloudflare Worker Service Binding, requests `mcp:read` and `mcp:write`, and then invokes the internal proxy.
- `auth-server/src/index.mjs`: OAuth authorization server, restricted to a single owner passphrase. Handles client registration, token issuance, and grants using `OAUTH_KV`.
- `auth-server/wrangler.jsonc` + `wrangler.jsonc`: distinct Cloudflare Workers.
- `plugin/`: draft private ChatGPT plugin manifest and MCP connection. Install only after OAuth is deployed and verified.
- `skills/slims-event-management/SKILL.md`: skill guidance to bundle with the plugin.

## Deploy (requires an authorized operator)

The site owner must create a **dedicated WordPress Application Password**. Do this in WordPress under **Users > Profile > Application Passwords**. Use a dedicated account with event-editing and media-upload capabilities; do not put the password into chat, email, GitHub, a JSON config, or logs.

1. Clone this repository onto an authenticated workstation and change into `integrations/slims-mcp`. Install dependencies with `npm install`. Run `npm run check`.
2. Configure Wrangler authentication to the correct Cloudflare account (account ID `a9d568c89915ded6a37945f39b908475`, subdomain `arkoninteractive`).
3. The OAuth KV namespace was already created: `slims-event-oauth` = `cc69e46a5a1a4351a9b34ff8dafba0ee`. **Don't create a duplicate.** Its binding is present in `auth-server/wrangler.jsonc`.
4. Deploy the authorization server first: `npm run deploy:auth`.
5. From `integrations/slims-mcp`, set the strong owner sign-in secret interactively:
   ```sh
   npx wrangler secret put AUTH_LOGIN_SECRET -c auth-server/wrangler.jsonc
   ```
   Use a newly generated strong passphrase (at least 24 random characters), retained securely by the site owner. It is only used during the browser's OAuth approval screen.
6. Deploy the MCP resource server: `npm run deploy:mcp`.
7. Set its secrets **interactively**, using the corresponding `-c wrangler.jsonc` on each command:
   ```sh
   npx wrangler secret put MCP_API_KEY -c wrangler.jsonc
   npx wrangler secret put WP_USERNAME -c wrangler.jsonc
   npx wrangler secret put WP_APP_PASSWORD -c wrangler.jsonc
   ```
   `MCP_API_KEY` must be an independent randomly generated value at least 24 characters long. It is used only between the OAuth-validated handler and the internal WordPress bridge, **not** placed in the plugin manifest.
8. Test the **OAuth-protected** `https://slims-event-mcp.arkoninteractive.workers.dev/mcp` endpoint from an MCP inspector. Unauthenticated requests must be rejected with a protected-resource authorization challenge. On successful owner authorization, verify `tools/list` and `connection_status`. **Do not test by publishing.**
9. Install the private ChatGPT plugin from `plugin/` (bundle `skills/slims-event-management/` under its `skills/` directory), and complete the OAuth authorization in the account connection flow. Test reading an event, creating a draft, and uploading an original flyer. Verify the draft is absent from the public calendar.
10. After the plugin works, implement an authenticated screenshot renderer using the actual WordPress event template. Until then, `preview_event` returns edit/preview links for a WordPress-authenticated browser, never a fabricated screenshot.

## Security and limitations

- The WordPress Application Password gives the dedicated WP account whatever WordPress capabilities that account already holds; create a restricted role rather than sharing the owner/admin password.
- A simple passphrase-protected authorization form is provided for initial single-owner use. **Before production, configure Cloudflare rate limiting and consider moving owner login to Cloudflare Access or a stronger identity provider.** Do not enable broader workspace sharing until stronger account identity and per-user authorization exist.
- Do not add the dedicated WordPress credential to Cloudflare page variables, GitHub secrets that build untrusted pull requests, or ChatGPT messages. Use Worker encrypted secrets.
- `upload_flyer` accepts base64 JPEG/PNG/WebP up to 5 MiB and validates file signatures. Some ChatGPT surfaces do not pass uploaded file bytes directly into MCP arguments; add a supported file attachment workflow if needed.
- Publishing is separate from creating or editing drafts and requires the exact `PUBLISH <id>` confirmation value for that draft. User approval must precede calling the tool.
- Avoid edits to locked imported recurrence data, as those event dates are preserved in the occurrence table.
- **No production deployment or WordPress authentication has been verified**. Code committed to GitHub is not the same as an operational connection.

## Tool list

`connection_status`, `list_events`, `get_event`, `list_categories`, `create_event_draft`, `update_event_draft`, `upload_flyer`, `preview_event`, `publish_event`.
