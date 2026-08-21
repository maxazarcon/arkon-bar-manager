=== Arkon Event Manager ===
Contributors: arkon
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 2.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bar event management for WordPress: events with date, time (including "Close"),
category and flyer, surfaced on the frontend via Themeco Pro/Cornerstone Looper
+ Dynamic Content, with per-event iCal and Google Calendar export.

== Description ==

= Repeating events =

An event holds one date plus an optional recurrence rule (daily, weekly, monthly
on the same date, or monthly on the same weekday), with an interval, an optional
end date or occurrence count, and a list of dates to skip for holidays.

Rules are expanded into concrete dates in a dedicated table, so the calendar
sorts and paginates in SQL rather than expanding rules in PHP on every request. A
non-repeating event gets exactly one row, so nothing downstream has to special
case it. Open-ended rules are generated a configurable number of months ahead
(24 by default) and extended daily by a scheduled task, so they never run dry.

= Migrating from Modern Events Calendar =

Use **Migrate & Tools**, not the CSV importer, if the MEC install is in the same
database.

A MEC CSV export carries one row per event keyed on the MEC post ID. Every
occurrence of a repeating event therefore collapses onto a single record, and a
weekly event arrives with one date instead of a hundred. On a venue calendar
where a couple of weekly nights can be most of the listings, that quietly removes
the majority of the calendar.

The database importer reads MEC's own occurrence table and copies every date
verbatim, so the new calendar shows exactly what the old one showed. It also
reuses the existing attachments instead of re-downloading images, maps categories
by name, and records each event's original slug so old links keep resolving.

Because MEC's schema has changed between versions, the importer resolves every
table and column at runtime and reports what it found. If it cannot recognize the
occurrence table it refuses to write rather than importing one date per event,
since that failure otherwise looks like a successful import until someone notices
the calendar is empty. Run the preview first: it lists the events contributing the
most dates, so a weekly event showing a single date is visible immediately.

= Legacy URLs =

Events migrated from MEC keep their old slug, and requests to the previous
single-event base (/event-archive/<slug>/ by default) are 301 redirected to the
current permalink, including any trailing /ical/ and any query string. Unknown
slugs are left to 404 rather than guessed at.



Arkon Event Manager registers an "Events" post type (abm_event) and an editable
"Event Categories" taxonomy (abm_category). Each event stores its data in
abm_-prefixed post meta — including pre-formatted display strings — so you can
build your events calendar layout directly in Cornerstone with a Looper and pull
each field through Dynamic Content with zero extra code.

= Admin =

Arkon Event Manager menu in wp-admin:
* All Events — list, sortable by date, with an All / Upcoming / Past filter.
* Add Event — title (event name), date picker, start time, end time (or "Close"),
  "Only display start date" toggle (on by default), event cost, and category
  checklist. The flyer is the post's Featured Image (set via the Featured Image
  panel); if none is set, the global placeholder is used.
* Categories — add / rename / remove categories (seeded with Music & Event).
* Settings — global flyer placeholder, default "Close" time (for calendar
  exports), currency symbol, date format, venue name and address, and the
  [abm_calendar] initial / Load More counts + category-tag visibility.
* Import — bring events in from another calendar plugin's CSV export.
* Migrate & Tools — import directly from a Modern Events Calendar install in the
  same database (recommended over CSV, see below), rebuild occurrences, and read
  a schema diagnostic.

= Import =

Event Manager > Import accepts a CSV exported from another calendar plugin and
creates events from it. The source format is auto-detected (or chosen manually).
Modern Events Calendar (MEC) is supported out of the box; the importer framework
(includes/importers/) is built so additional formats can be added as subclasses
of ABM_Importer.

MEC column mapping:
* Title           -> event title
* Start Date      -> abm_event_date
* Start Time      -> abm_event_time_start ("8:00 pm" -> 20:00; "All Day" -> blank)
* End Time        -> abm_event_time_end ("10:00 pm" -> 22:00; blank -> no end)
* Event Cost      -> abm_event_cost (door charge)
* Categories      -> abm_category terms ("Event, Music" split; created if missing)
* Featured Image  -> the event's Featured Image (reused from the Media Library
                     or downloaded; MEC's own flyer-placeholder image is skipped
                     so the global placeholder is used)
* Description     -> post content
* Link            -> post slug reused from .../event-archive/<slug>/ to preserve URLs
* ID              -> stored as abm_import_source_id for de-duplication

Options: Publish or Draft; reuse images already in the Media Library; download
images on/off; update events previously imported from the same source (matched
by source ID -- events whose details are unchanged are skipped rather than
re-saved); and a dry run that reports counts without writing anything, including
how many flyers would be reused vs. downloaded.

A completed import shows an Import Complete screen summarizing created, updated
and skipped events plus flyers reused / downloaded, with links to view the
events or import another file. A dry run instead reports the same counts inline
so you can review them, untick "Dry run" and proceed.

Reuse existing images: when moving from another plugin on the SAME site, the
flyers are already in your Media Library. With this on, the importer matches each
event's image URL to an existing attachment (by exact URL, by the local uploads
path, then by filename so differing CDN hosts/paths still match) and reuses it
instead of downloading a duplicate. If no match is found and "download" is also
on, it falls back to downloading.

Importing is restricted to administrators; the upload is validated (.csv, tab/
comma/semicolon auto-detected, BOM-tolerant, 5 MB cap) and only HTTP(S) image
URLs are fetched.

= "Only display start date" (per event) =

Set on each event (Event Details box), on by default. When enabled, an event that
runs past midnight (e.g. 8:00 PM – 2:00 AM) is clamped to 11:59 PM of the start
day in the Google Calendar / iCal export, so it stays on its start date instead
of bleeding onto the next day. Uncheck it for a show you genuinely want to span
two days. Stored as the abm_display_start_only meta key.

Each published event gets its own page at /music-and-events/event-title/ (the
same base as the calendar page; the post type has no separate archive so the
/music-and-events/ page itself keeps resolving normally).

= Frontend meta keys (Cornerstone Dynamic Content) =

Reference these inside a Looper Consumer with {{dc:post:meta key="..."}}:

* abm_date_display     "26 Jun" (uses the global Date Format setting)
* abm_time_display     "8:00 PM - Close"
* abm_cost_display     "$10" (door cost; empty when free/unset)
* abm_flyer_url        flyer URL = Featured Image (falls back to the placeholder)
* abm_ical             per-event .ics download link
* abm_gcal             Google Calendar "add event" link
* abm_event_date       Y-m-d (raw — use for Looper sorting / upcoming filters)
* abm_event_time_start H:i (raw)
* abm_event_time_end   H:i or "close" (raw)
* abm_flyer_id         flyer attachment ID (mirrors the Featured Image)

= Cornerstone Looper setup (events calendar page) =

1. Add a Looper Provider element. Set Query Builder:
   * Post Type: Event (abm_event)
   * Order By: Meta Value, Meta Key: abm_event_date, Order: ASC
   * (Upcoming only) Meta Query: key abm_event_date, compare >=, value {{dc:date format="Y-m-d"}}, type DATE
2. Inside the Consumer, add elements and bind via Dynamic Content:
   * Image source:  {{dc:post:meta key="abm_flyer_url"}}
   * Heading:       {{dc:post:title}}
   * Date text:     {{dc:post:meta key="abm_date_display"}}
   * Time text:     {{dc:post:meta key="abm_time_display"}}
   * Category:      {{dc:post:terms taxonomy="abm_category"}}  (or [abm_event_category])
   * Link to event: {{dc:post:permalink}}

= Calendar shortcode =

[abm_calendar]

Outputs a month-grouped list of upcoming events (flyer, title, date, time,
category, cost) with a "Load More" button that pulls the next batch over AJAX.
The number loaded initially and per Load More click are set under Settings >
Calendar Shortcode, and can be overridden per placement:

  [abm_calendar initial="8" more="6"]

Category tags (Music, Event, …) in the list are controlled by a global toggle
(Settings > Calendar Shortcode > Category Tags). Each event can override it under
Event Details > Category Tag: Default (follow the global setting), Show, or Hide.

Recolor the accent (icons / Load More hover) by overriding the CSS variable, e.g.
  .abm-calendar { --abm-accent: #d6006e; }

= Field shortcodes (Dynamic Content bridge + export buttons) =

Use inside a Looper Consumer (they read the current event), or pass id="123":

* [abm_event_date]                 -> global Date Format (e.g. "26 Jun")
* [abm_event_date format="l, F jS"] -> per-tag override, e.g. "Saturday, June 21st"
* [abm_event_time]                 -> "8:00 PM - Close"
* [abm_event_category sep=", "]    -> category names
* [abm_cost]                       -> formatted door cost
* [abm_flyer_url size="large"]     -> flyer/placeholder URL
* [abm_flyer size="large"]         -> <img> flyer/placeholder
* [abm_ical]                       -> .ics URL
* [abm_gcal]                       -> Google Calendar URL
* [abm_event_export]               -> Google Calendar + iCal buttons

== Notes ==

* Times and exports use the site timezone (Settings > General).
* "Close" is shown literally on the frontend; calendar exports substitute the
  default "Close" time from Settings and roll past midnight automatically.
* Uninstalling removes only the settings option; events are preserved.
