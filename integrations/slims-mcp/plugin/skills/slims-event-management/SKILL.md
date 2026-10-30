---
name: slims-event-management
description: Manage Slim's Dive Bar events using the connected Slim's Event Manager MCP tools, including draft creation, flyer uploads, duplicate detection, preview review, and approval-gated publishing.
---

# Slim's event management

Use the connected Slim's MCP tools for the actual WordPress `abm_event` custom post type, never an invented HTML calendar or a generic blog post.

## Workflow

1. Extract title, local start date, start and end time, cover, descriptive copy, and flyer from supplied source. Do not guess unsupplied cover charges, performers, or special offers.
2. Call `connection_status`, `list_events` and, when necessary, `get_event` to check for duplicates. Confirm that the WordPress connection is valid before writes.
3. Call `list_categories` to select an existing category; do not invent IDs.
4. Call `create_event_draft` with status implied as draft, never published. An event finishing after midnight keeps the **starting** date and a 24-hour end time earlier than start, e.g. 13:00–02:00.
5. Upload the provided original flyer with `upload_flyer` and attach its WordPress media ID to the draft. If tools cannot access the attachment bytes, explain the limitation; do not substitute a generated image.
6. Call `get_event` to verify fields, date and occurrence counts. Call `preview_event` and give the real WordPress preview link. Screenshots require an authenticated renderer or browser; never claim the URL itself is a screenshot.
7. Present draft details for approval. Do not invoke `publish_event` unless the user explicitly requests publication for that specific draft. The tool requires confirmation `PUBLISH <id>`.

## Safety

- Only use the site-specific tools. Do not construct arbitrary WP REST requests to unrelated site resources.
- If `abm_occurrences.locked` is true, do not update occurrence dates through ordinary meta writes; they are imported verbatim and protected.
- A live event cannot be edited with `update_event_draft`; do not work around that control.
- WordPress application passwords and MCP access tokens must remain server-side Cloudflare secrets; never request them in chat, logs, or GitHub files.
- Do not publish an event merely to make it visible for screenshot capture.
