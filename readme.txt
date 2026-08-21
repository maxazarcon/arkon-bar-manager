=== Arkon Event Manager ===
Contributors: arkon
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.13.1
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
  "End time is approximate" toggle (off by default), event cost, and category
  checklist. The flyer is the post's Featured Image (set via the Featured Image
  panel); if none is set, the global placeholder is used.
* Categories — add / rename / remove categories (seeded with Music & Event).
* Settings — global flyer placeholder, default "Close" time (for calendar
  exports), currency symbol, date format, venue name and address, how far
  ahead open-ended repeats generate dates, and the [abm_calendar] initial /
  Load More counts + category-tag visibility.
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

= "End time is approximate" (per event) =

Set on each event (Event Details box), **off by default**. It affects the
Google Calendar / iCal export only, never the listing.

The listing always shows an event on its start date, because an occurrence
carries a date and a time range rather than a start date and an end date. A show
running 8:00 PM to 1:00 AM is therefore one row, on the day it starts, reading
"8:00 PM - 1:00 AM".

Turn this on only when the end time is a placeholder or the night is genuinely
open-ended: the export then stops at 11:59 PM of the start day instead of
claiming a finish time. Leave it off when the end time is real, so the export
correctly runs past midnight. Stored as the abm_display_start_only meta key.

Each published event gets its own page at /music-and-events/event-title/ (the
same base as the calendar page; the post type has no separate archive so the
/music-and-events/ page itself keeps resolving normally).

That page is rendered by the plugin's own template unless the theme provides
one. See Settings > Event Pages. For a repeating event the page shows the date
that was clicked, resolved from the ?occ= parameter and validated against the
event's real dates, plus a list of its other upcoming nights.

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

= REST API =

Event meta is exposed on wp/v2/abm_event, so a whole event including its
recurrence rule can be created or edited in one authenticated call.

Each event also carries a read-only abm_occurrences object:

* count   how many dates this event currently has
* next    its next upcoming date (Y-m-d), or empty if it has none left
* locked  true when the dates cannot be changed by editing abm_event_date

locked is true for an event imported from another calendar: its dates were
copied verbatim and it has no recurrence rule, so regenerating it would
collapse it to a single date, and the plugin refuses. Writing abm_event_date to
such an event succeeds and changes the displayed date strings, but the calendar
keeps showing the imported dates. Give the event a recurrence rule to take over
from the import, or delete and recreate it. Everything other than the dates --
title, times, cost, categories, content, flyer -- edits normally.

Check it before editing dates:

  GET /wp-json/wp/v2/abm_event/<id>?_fields=id,abm_occurrences

The object costs one database query per event and is only computed when asked
for, so listing events with _fields that omit it is free.

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

Outputs a month-grouped list of upcoming events (flyer, title, short description,
date, time, category, cost) with a "Load More" button that pulls the next batch
over AJAX. The description is the event's excerpt, or its trimmed content when no
excerpt is set, and is omitted entirely for events that have neither.
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

== Updates ==

Releases are published on GitHub and the plugin updates itself from them, using
the "Update URI" mechanism built into WordPress 5.8 and later. No updater library
is bundled and no other plugin's update checks are affected.

Build the release artifact from a tag rather than by zipping the plugin folder:

  git archive --format=zip --prefix=arkon-bar-manager/       -o arkon-bar-manager-X.Y.Z.zip vX.Y.Z

Attach that zip to the GitHub release. It carries no repository metadata and
unpacks to the correct folder name, so WordPress updates the plugin in place.

== Notes ==

* Times and exports use the site timezone (Settings > General).
* "Close" is shown literally on the frontend; calendar exports substitute the
  default "Close" time from Settings and roll past midnight automatically.
* Uninstalling removes the plugin settings, the occurrence table and the
  scheduled task that extends it. Events, their meta and the categories are
  preserved; delete them in the admin first if you want them gone.

== Upgrade Notice ==

= 2.12.0 =
The plugin now updates itself from its GitHub releases. Set ABM_GITHUB_REPO and
the Update URI header to your repository before relying on it.

= 2.10.2 =
Fixes "Add to Google Calendar" sending mangled text. Every event's link was
affected; they repair themselves on upgrade.

= 2.7.2 =
Fixes the site navigation becoming unreadable when scrolling an event page.

= 2.4.1 =
Stops a plugin update from rebuilding every event's dates. Recommended for any
site that has imported events from another calendar.

== Changelog ==

Versioning is strict MAJOR.MINOR.PATCH. MAJOR = something that worked no longer
does. MINOR = new surface area. PATCH = it was already supposed to work that way.

= 2.13.1 =
Fix only.
* "Check again" on the Updates screen now really re-checks. Release lookups are
  cached for six hours, and WordPress's force-check clears its own update
  transient but knows nothing about that cache, so a release published in the
  last six hours stayed invisible however many times the button was pressed --
  which reads as the updater being broken rather than being cached. A forced
  check now bypasses the cache; ordinary checks still use it.

= 2.13.0 =
* Event categories in the calendar are links again, pointing at their archive.
  The previous calendar linked them, those URLs are public and indexed, and this
  taxonomy claims the same base -- rendering the names as plain text quietly
  dropped a working route rather than simplifying one.
* Category archives get a layout. Without one a theme prints excerpt fragments
  and pagination and nothing that identifies an event, which is the same failure
  single event pages had before 2.7.0 and matters more here, because those URLs
  were already being linked from every row of the old calendar.
  The archive renders the calendar shortcode filtered to the term, so there is one
  renderer rather than two that can drift, and it inherits load-more, month
  dividers and collapse. It shows upcoming dates only, matching how the archive
  is already ordered.
* A theme template named taxonomy-abm_category.php still wins, and the Event
  Pages setting switches both templates off together.

= 2.12.1 =
Fixes and housekeeping; no new surface.
* Venue name and address are joined with a comma rather than a space in calendar
  exports. "The Venue, 1 Example St" geocodes; "The Venue 1 Example St" is a
  guess. Both settings feed one field in a calendar app, and either may be empty.
  The join is now a single helper used by both the iCal and Google Calendar
  paths, which previously repeated the same expression.
* "Tested up to" raised to 7.1, which is where the plugin is actually exercised.
  It had been left at 6.8, which makes WordPress warn that a plugin is untested
  and possibly abandoned.
* Added upgrade notices for the releases worth reading before updating.

= 2.12.0 =
* The plugin updates itself from its GitHub releases. New releases appear on the
  Plugins screen and through auto-updates exactly like any other plugin.
  It uses the mechanism WordPress added in 5.8 rather than a third-party updater
  library: an "Update URI" header whose host is github.com makes core fire an
  update check for this plugin and no other, so nothing else on the site is
  touched and there is no dependency to keep current.
* Release lookups are cached for six hours, and a failed lookup is cached for one,
  so a rate limit or an outage does not mean an API call on every admin page load.
* Point it at a different repository by defining ABM_GITHUB_REPO or filtering
  abm_github_repo. The value must match the Update URI header, since that header
  is what WordPress reads to decide whether to ask at all.
* Attach a built zip to each GitHub release. The source archive GitHub generates
  automatically unpacks to a folder named after the tag, which would install a
  second copy of the plugin beside the real one instead of updating it; the plugin
  renames the folder defensively, but a release asset built with
  `git archive --prefix=` is the intended artifact.

= 2.11.1 =
Documentation only; no behaviour change.
* The changelog and the source comments described the specific site this plugin
  was first built for -- its event titles, its referrer mix, its migration
  counts. Now that the changelog is rendered inside the plugin and the plugin may
  be distributed, all of that has been generalised. The engineering detail is
  unchanged; only the identifying specifics are gone.

= 2.11.0 =
* New Changelog screen under Event Manager, alongside Settings and Migrate &
  Tools. It renders this file's changelog section rather than keeping a second
  copy in PHP, so the two cannot drift -- readme.txt is what ships and what the
  versioning convention already requires be updated on every build.
* Each release is tagged Breaking / New / Fix, derived from the version numbers
  themselves rather than from the prose, and the running version is marked
  Installed so it is obvious which build a site is on.
* The Settings screen now shows the running version and links to it.

= 2.10.3 =
Fix only.
* The Google Calendar description ran the cover charge onto the end of the
  previous sentence -- "...Noise Hop).Cover: $5". It was joined with a blank line,
  which cannot survive the trip: esc_url() strips %0d and %0a outright as a
  header-injection guard, so an encoded line break is removed from any URL
  WordPress sanitizes. Joined with a space instead.
  The .ics export is unaffected and keeps its blank line, because that payload is
  never passed through a URL escaper.

= 2.10.2 =
Fix only.
* "Add to Google Calendar" sent Google a title and description with every space
  and most punctuation removed, so an event titled "Live Band Tonight" arrived
  as "LiveBandTonight" and a description reading "Doors at 8:00pm, music at
  9:00pm" as "Doorsat800pmmusicat900pm".
  abm_gcal, abm_ical and abm_flyer_url are URLs but were registered with
  sanitize_text_field as their sanitize callback. That function contains a loop
  which deletes every percent-encoded sequence it finds -- correct for prose,
  destructive for a URL -- so each save stripped the %20, %3A and %2C out of the
  Google Calendar query string. They now use esc_url_raw.
  abm_gcal was broken on every event, since its query string is always encoded.
  abm_ical and abm_flyer_url were only affected when the URL happened to contain
  an encoded character, such as a flyer filename with a space in it.
* Existing events repair themselves on upgrade: the derived values are rebuilt by
  the resync the upgrade path already runs.
* The .ics download was never affected. It is generated per request and never
  round-trips through post meta.

= 2.10.1 =
Fix only.
* Between roughly 700px and 772px the event page stacked its columns but left the
  new heading below the details -- the one arrangement the heading exists to
  avoid. The columns stop fitting at about 772px (380 + 32 + 320 of content) while
  the heading's placement flipped at the 700px phone breakpoint, so the two
  disagreed across that band. Both are now tied to a single 800px threshold.
  Found by checking 360, 480, 720, 1080 and 1440 side by side; 720 was the only
  width that showed it.

= 2.10.0 =
* The event page now restates the event title as a plain heading in the body. The
  hero sets it in the theme's script face over a darkened flyer, which is handsome
  and not especially easy to read, particularly on a phone.
  On a wide screen the heading introduces the description beneath the flyer and
  details. On a phone everything stacks and the heading rises above the details,
  so the visitor knows what they are reading before the meta list. When the event
  has no description the heading sits beside the details at every width rather
  than stranding itself below the columns.
  It is one element placed by CSS `order`, not two copies with one hidden, so
  nothing is duplicated for screen readers.

= 2.9.4 =
Fix only.
* The calendar's "View Detail" link was a 30px touch target on phones. The mobile
  rules padded the footer around it, which makes the footer roomier but leaves the
  tappable anchor exactly as small as it was. The anchor itself is now sized.
  Completes what 2.9.3 started -- that release fixed the event page's buttons and
  missed the calendar's.

= 2.9.3 =
Fix only.
* The event page's export buttons and its "All events" link were below the 44px
  minimum touch target -- 39px and 16px respectively. Both now clear it without
  any change to how they look. The calendar's Load More was already sized this
  way; the event page had not caught up.

= 2.9.2 =
Fix only.
* On phones, an event with a long title dropped below its flyer while an event
  with a short one sat beside it, so consecutive rows had different shapes. The
  phone rules still sized the title as the row's flex child, which it stopped
  being in 2.9.1 when the title and description were wrapped together. The wrapper
  is now the sized element, so every row lays out the same way and long titles
  wrap in place.

= 2.9.1 =
Fix only.
* The event description rendered beside the title instead of underneath it. The
  row is a flex container of flyer / title / meta, and the description was added
  as a fourth child, so it became its own column. Title and description now share
  a wrapper and stack, which is what 2.9.0 intended.

= 2.9.0 =
* Event descriptions now appear in the calendar list, under the title, as a short
  blurb clamped to two lines so one long description cannot make a row tower over
  its neighbours. It uses the excerpt when there is one and trims the content
  otherwise. Typically only a minority of events carry a description, so most
  rows are unchanged.
* The description on the event page is now set as a readable paragraph with a
  capped measure, rather than an unstyled block.
* Event titles in the list are charcoal at rest and pink on hover. They previously
  inherited the theme's link colour, which on this site is a red that fought
  everything around it.
* The event page's "All events" link is now the site's own text-button form:
  uppercase, letter-spaced, with a chevron that slides on hover over 300ms on the
  same easing the theme's buttons use. Honours prefers-reduced-motion.
* The event page title uses the script face the theme uses for its other hero
  titles, sized with clamp() so it scales fluidly instead of needing a breakpoint
  set to maintain. Override with --abm-hero-font.
* "Also coming up" dates no longer inherit the theme's red link colour either.
* New CSS custom properties: --abm-ink, --abm-muted, --abm-hero-font.

= 2.8.1 =
Fix only.
* Buttons now invert to charcoal on hover, not the brand pink. 2.8.0's stated job
  was to match the surrounding site, and the site's own calendar inverts its
  "View Detail" button to #191919 with a white label -- so filling with pink was a
  misreading of the target, and too loud at button size besides.
* The two colours now have separate jobs, which is the part worth keeping:
  --abm-accent (pink) for small marks such as the meta icons and text links, and
  the new --abm-hover-fill (charcoal) for anything that fills a whole button.
  Override either.

= 2.8.0 =
* The calendar list and the event page now use the surrounding site's styling
  rather than the plugin's own. The values were read off the live site, not
  invented: the accent is the brand pink #ff129f, sampled from the active nav
  navigation and call-to-action buttons, and transitions use Cornerstone's own
  curve, 0.3s cubic-bezier(0.4, 0, 0.2, 1).
* "View Detail" is a real button again -- a quiet bordered pill that fills with
  the brand pink and turns its label white on hover. That is the same move the
  site's own navigation makes, and the same shape the previous calendar used, in
  a colour that belongs to this site rather than to the old plugin.
* The export buttons on the event page were given the identical treatment, so the
  two screens agree.
* Event cards lift on hover (border darkens, soft shadow) so a row reads as one
  target instead of several separately hoverable links. Card corners went from
  4px to 12px, matching the cards the previous calendar rendered.
* New CSS custom properties for theming: --abm-ease, --abm-card-radius and
  --abm-card-border alongside the existing --abm-accent. Overriding --abm-accent
  still recolours everything in one line.
* Behaviour change on upgrade: anything relying on the previous #d6006e accent or
  the 4px card radius will look different. Nothing functional changed.

= 2.7.2 =
Fix only.
* The site navigation became unreadable when scrolling an event page. The theme's
  navbar is transparent over the hero and relies on a scroll listener that
  measures the first element with class `hero` and then adds `navbar-scrolled`,
  which is what paints the bar dark so the white logo and menu stay visible over
  light content. The template's band was named `abm-single-hero` only, so the
  listener found nothing, the class was never added, and the menu disappeared
  against the white page body as soon as the visitor scrolled.
  The band now also carries the plain `hero` class. That class is load-bearing;
  renaming it silently reintroduces this.

= 2.7.1 =
Fix only.
* The single event template rendered its content underneath the site navigation.
  The theme this was built against uses an overlay header: header.x-masthead
  collapses to zero height and the visible nav bar inside it is positioned
  absolutely, 100px tall, so content laid out from the top of the document sits
  beneath it. 2.7.0 laid out from the top and the event title collided with the
  menu.
  The template now opens with a full-bleed title band carrying the event name,
  date and time, which is how every other page on this site clears that header.
  The band uses the event flyer as a darkened background when there is one.
* The tuning knob for this changed name with the approach: --abm-single-offset
  (a padding value) is gone, replaced by --abm-hero-min (the band's minimum
  height). Both are new in the 2.7.x line, so nothing that existed before can
  have depended on either.

= 2.7.0 =
**Superseded by 2.7.1 on the same day; see the note at the end of this entry.**

* Single event pages now render. A theme with no layout for this post type falls
  back to a generic one, which on some themes produces the page shell and nothing
  else -- no title, no date, no flyer, no description, and the event title absent
  from the markup entirely. This matters more than it sounds, because
  /event-archive/<slug>/ redirects land on exactly these pages.
  The plugin now supplies templates/single-abm_event.php showing the flyer, the
  date of the occurrence being viewed, the time range, category, cover,
  description, Google Calendar and iCal buttons, and -- for a repeating event --
  its other upcoming dates, each linking to its own night.
* Settings > Event Pages > Event Page Template switches it off. A theme file named
  single-abm_event.php always wins over it, and a Cornerstone Single Layout
  assigned to Event hooks later and takes over on its own.
* New: ABM_Occurrences::upcoming_for() returns an event's next dates, one query.

  Note on this release: two materially different builds were stamped 2.7.0 during
  development, and the second overwrote the first as arkon-bar-manager-2.7.0.zip.
  The build that reached a server had the header-overlap defect described under
  2.7.1. No artifact stamped 2.7.0 should be trusted to be a particular build;
  use 2.7.1 or later.

= 2.6.1 =
* The "Rebuild occurrences" notice no longer reads as a data-loss report. On a
  site with hundreds of imported events it might say "3 imported events kept
  their original dates", which invites the conclusion that the rest lost theirs.
  Only an event holding more than one date needs protecting -- a single-date
  event regenerates to the same date -- so a low number is both correct and
  alarming. The notice now explains what the number counts and points at the
  stored total as the check.

= 2.6.0 =
* REST: events carry a read-only abm_occurrences object ({count, next, locked}).
  Since 2.4.0 an imported event's dates are protected from being regenerated,
  but nothing in the REST representation said so: writing abm_event_date to one
  returns 200, updates the meta and the display strings, and leaves the calendar
  rendering the original dates. There was no field a client could check. There
  is now.
* stats_for() answers count, next date and lock state in a single query, and
  is_protected_explicit() accepts a count the caller already has, so the new
  field costs one query per event rather than three.

= 2.5.0 =
* Settings > Repeating Events > Generate Ahead: how far ahead an open-ended
  repeat materializes dates, 1-120 months, default 24. The plugin has always
  read this value (recur_horizon_months) and the documentation has always
  described it as configurable, but there was no way to set it. Saving the
  screen re-expands every open-ended event immediately, which already happened
  automatically on any settings change.
  Note this governs rule-driven events only. Events imported from MEC hold
  their dates verbatim and are unaffected; use "Skip dates before" on Migrate
  & Tools to bound those.

= 2.4.1 =
Fixes only.
* A plugin update no longer rebuilds every event's occurrences. The upgrade and
  activation paths now call generate_missing(), which materializes only events
  that have no date rows at all. rebuild_all() regenerates from the recurrence
  rule, and an imported event has no rule, so running it on a finished migration
  collapsed each event to a single date. 2.4.0 stopped that from destroying data
  but left the call in place; this removes it.
* Saving Settings no longer deletes plugin settings that have no field on that
  screen. sanitize_settings() rebuilt the option from scratch, so
  recur_horizon_months, legacy_base and calendar_page_id were wiped on every
  save -- including the recurrence horizon documented as configurable.
* CSV importer: "End time is approximate" is now set only for events with no end
  time, matching the database importer. It was hardcoded on, which truncated a
  genuine 8 PM - 1 AM show at 11:59 PM in every export it produced. (The same fix
  landed for the database importer in 2.1.0; the CSV path was missed.)
* CSV importer: records abm_legacy_slug, so /event-archive/<slug>/ redirects keep
  resolving after an imported event is renamed. Previously only the database
  importer recorded it, and the CSV path relied on the post slug still matching.
* Removed the [abm_calendar collapsed="..."] attribute, which was accepted and
  then ignored.
* readme: added this changelog, corrected the "End time is approximate" default
  (off, not on) and the uninstall description.

= 2.4.0 =
* Imported dates are no longer destroyed by a rebuild. set_explicit() marks an
  event with abm_occurrences_explicit, and generate_for_post() refuses to
  regenerate a multi-date verbatim set that has no recurrence rule. Setting a
  rule clears the mark and lets the rule take over.
* Rebuild occurrences reports how many imported events kept their original dates.
* Events list: the Date column, the default sort and the Upcoming/Past filter all
  read the occurrence table instead of the abm_event_date meta, which is the
  series start -- a weekly night running since 2019 sorted to the top for ever
  and was filed under Past even though it runs this Monday. Recurring events show
  a repeat marker.
  (These were written as 2.3.2 but never cut as their own build; they shipped
  inside 2.4.0. Recorded here so the released artifacts are the ones listed.)

= 2.3.1 =
* is_recurring() memoized per request and answered with LIMIT 2 instead of
  COUNT(*). A 100-item Looper run went from 101 queries to 1.
* Uninstall drops the occurrence table and unschedules the cron event instead of
  orphaning both.
* Category archive orders by each event's next upcoming date rather than the
  series start.

= 2.3.0 =
* Event meta reachable over the REST API, so a complete event including its
  recurrence rule is one authenticated call. Requires custom-fields support on
  the post type; occurrence generation moved to rest_after_insert_abm_event
  because REST writes meta after wp_update_post().

= 2.2.0 =
* Cornerstone / Pro Looper Provider ("Bar Events (occurrences)") that iterates
  occurrences rather than posts, with 29 fields per item.

= 2.1.0 =
* MEC database import runs in resumable batches of 20 with a progress bar. The
  single-request import wrote every event correctly but never returned a
  response, which is indistinguishable from a dead import.
* "End time is approximate" now defaults off. Defaulting it on truncated a
  genuine 8 PM - 1 AM export at 11:59 PM.
  (That fix was a pure bug fix and should have shipped on its own as 2.0.1,
  ahead of the batched importer, so anyone on 2.0.0 could take it alone.)

= 2.0.0 =
* Occurrence model: one row per event per date, in {prefix}abm_occurrences.
  Everything downstream reads occurrences, not posts, so a weekly event renders
  on every date rather than once.
* Keyset cursor pagination, collapsible month headings, MEC database importer,
  /event-archive/ legacy redirects.
* Breaking: new data model.
