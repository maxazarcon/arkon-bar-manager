# Slim's Event Manager MCP Bridge

A private Streamable HTTP MCP endpoint for the existing Arkon Event Manager WordPress REST API.

## Source of truth

The existing plugin's `abm_event` posts and `abm_category` taxonomy, served by `https://slimsdivebar.com/wp-json/wp/v2/`. No parallel event store, database migration, or modification to the live calendar.

## Configuration

1. Deploy the Cloudflare Worker with the bundled `wrangler.jsonc`. Review this code before deploying.
2. Use a dedicated WordPress account with the least permissions needed to edit event posts and upload media. Generate a WordPress **Application Password** under that account; do not reuse your login password.
3. Set these **Worker secrets**, never repository variables or chat messages:
   - `WP_USERNAME` — dedicated WordPress username.
   - `WP_APP_PASSWORD` — WordPress Application Password.
   - `MCP_API_KEY` — independently generated 32+ byte high-entropy random bearer credential; do not reuse WP credentials.
4. The Worker exposes `POST /mcp`, `GET /health` (configuration booleans only), and no other endpoints. **Do not enable production use until ChatGPT plugin authentication is verified.** A static bearer token is suitable for controlled integration testing, but a ChatGPT connector that requires OAuth must have an OAuth 2.1 authentication layer added; **do not attach this endpoint as an unauthenticated remote plugin**.
5. To verify, call `initialize`, `tools/list`, and `connection_status` using the MCP credential, followed by a **draft-only** creation/edit test. Confirm the event is absent from the public list.
6. For real draft screenshots, use an authenticated WordPress browser session (e.g. ChatGPT Work) or add a separate protected renderer with secure access to private previews. The `preview_event` tool returns a real preview URL but **does not claim it took a screenshot**.

## MCP tool contract

- `connection_status`
- `list_events`
- `get_event`
- `list_categories`
- `create_event_draft`
- `update_event_draft` — refuses published events
- `upload_flyer` — validates MIME, magic bytes, and 5 MiB bound; optional draft attachment
- `preview_event` — actual WordPress preview and edit links; screenshot not yet implemented
- `publish_event` — requires an exact `PUBLISH <id>` confirmation and fully populated event date/time

## Example

```json
{
  "name": "create_event_draft",
  "arguments": {
    "title": "Halloween at Slim's",
    "date": "2026-10-31",
    "start_time": "13:00",
    "end_time": "02:00",
    "description": "No bands, just good fun. Malört specials all day.",
    "cover": "none"
  }
}
```

Important: use the event's **start date** for an overnight event; Arkon Event Manager itself rolls earlier end times into the following day. Its public calendar filters out unpublished posts. The Worker checks title/date duplicates and detects locked imported occurrences when requested through `get_event`.

## Future production hardening

- Replace shared-token client authentication with scoped OAuth authorization, permitting seamless ChatGPT account connection.
- Implement a restricted preview screenshot service; never expose private draft links or page contents through a public screenshot URL.
- Add request auditing, rate limits, and a least-privilege event-only WordPress role.
- Review `WP_APP_PASSWORD` capability scope because standard WP roles may edit other posts too.
- Confirm WordPress production is running the matching Arkon Event Manager release.
