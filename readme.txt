=== Arkon Event Manager ===
Contributors: arkon
Tags: events, event calendar, calendar, venue, ical
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.15.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An events calendar for venues. Post your shows with a flyer, times and cover charge, and let visitors add any date to their own calendar.

== Description ==

Arkon Event Manager is an events calendar for venues. Add a show with its flyer,
times, cover charge and category, then put the calendar on any page with one
shortcode.

It suits places with a regular programme: music venues, bars, theatres and
community halls, where the same nights come round every week.

= Enter a weekly night once =

Set a night to repeat and it shows up on every date it runs. You enter it once
instead of creating a new event every week, and your admin does not fill up with
fifty copies of the same show.

Events can repeat daily, weekly, monthly on a date, or monthly on a weekday. Set
an interval, give it an end date, or list the dates to skip for holidays.

= Visitors can save the whole run =

Every event has "Add to Google Calendar" and "Download iCal" buttons. On a
repeating night those save the entire series rather than the single date being
viewed, so a regular adds your Tuesday open mic once and has it every week.

= Late nights display correctly =

A show from 8pm to 1am stays on the night it starts and reads "8:00 PM - 1:00 AM"
instead of splitting across two days. Nights that run until closing can simply
say "Close".

= Features =

* Flyer, date, start and end time, cover charge, category and description
* Repeating events, with holiday dates skipped
* `[abm_calendar]` shortcode: a month by month list with a Load More button
* A page for every event, showing its other upcoming dates
* Category pages
* Google Calendar and iCal export on every event
* Import from Modern Events Calendar with every date intact
* Old event links keep working after you switch
* Works with any theme, with extra support for Themeco Pro and Cornerstone

= Who it is for =

* Music venues and bars listing shows, residencies and weekly nights
* Theatres and cinemas with a rolling programme
* Community halls, clubs and societies with regular meetings
* Anyone who wants a listings page rather than a full event management suite

= Moving from another calendar =

If you are on Modern Events Calendar, the built in importer reads its data
directly and brings every event across with all of its dates, its flyers and its
categories. Your existing event links carry on working, so nothing you have
posted or linked to breaks.

= Works with your theme =

Add the shortcode to a page and you have a calendar. Events get their own pages
too, and if your theme has its own event template the plugin steps aside and
lets it render.

Building with Themeco Pro or Cornerstone? Every field is available to Dynamic
Content, and the plugin adds a Looper Provider so you can design your own
listing layout without writing any code.

== Installation ==

1. Upload the plugin to `wp-content/plugins/` and activate it, or install the
   zip through Plugins > Add Plugin > Upload Plugin.
2. An **Events** menu appears in the admin. Add an event: title, date, start and
   end time, cost, category, and a Featured Image for the flyer.
3. Create a page for your calendar and put `[abm_calendar]` on it.
4. Visit **Events > Settings** to set the currency symbol, the date format, the
   default "Close" time used by exports, a global flyer placeholder, how far
   ahead open-ended repeats generate, and how many events the calendar loads per
   batch.
5. Moving from another calendar? Go to **Events > Migrate & Tools** and run the
   preview before importing.

The post type registers its pages under `/music-and-events/`, and the category
archives under `/event-category/`. Flush permalinks (Settings > Permalinks >
Save) if event pages 404 immediately after activating.

== Frequently Asked Questions ==

= Do I need Cornerstone or Themeco Pro? =

No. `[abm_calendar]` and the single event template work on any theme. The Looper
Provider and the Dynamic Content meta keys are there if you use Cornerstone, and
inert if you do not.

= I changed an imported event's date and the calendar did not move. Why? =

Because its dates are locked, and that is deliberate.

An event imported from another calendar holds its dates verbatim and has no
recurrence rule, so regenerating it would collapse hundreds of real dates down to
the single date in the date field. The plugin refuses to do that. Editing the
date field on such an event succeeds and changes the displayed date, but the
calendar keeps showing the imported dates.

To take over from an import, give the event a recurrence rule -- which replaces
the imported dates with the rule's -- or delete and recreate it. Everything other
than the dates edits normally. Over REST, check `abm_occurrences.locked` before
writing a date.

= Should I use the CSV importer or Migrate & Tools? =

Migrate & Tools, whenever the old install is in the same database.

A Modern Events Calendar CSV export carries one row per event, keyed on the event
ID. Every date of a repeating event therefore collapses onto a single record, and
a weekly night arrives with one date instead of hundreds. Migrate & Tools reads
the occurrence table directly and copies every date.

= What happens to my old event URLs? =

Imported events keep their original slug, and requests to the previous
single-event base (`/event-archive/<slug>/` by default) are 301 redirected to the
new permalink, including any trailing `/ical/` and any query string. Unknown
slugs are left to 404 rather than guessed at.

= How do shows that run past midnight work? =

Enter the real times. A show from 8:00 PM to 1:00 AM is one listing on the night
it starts, reading "8:00 PM - 1:00 AM", and it exports with the correct finish
time. There is no need for the same-day-midnight workaround other calendars
require, because a date here carries a time range rather than a second date.

= What does "End time is approximate" do? =

It affects the exports only, never the listing, and it is off by default. Turn it
on when the end time is a placeholder or the night is genuinely open-ended: the
export then stops at the end of the start day instead of claiming a finish time.
Leave it off when the end time is real.

= Can I change the calendar's accent colour? =

Yes, with one CSS variable:

`.abm-calendar { --abm-accent: #d6006e; }`

= What does uninstalling remove? =

The plugin's settings, the date table and the scheduled task that extends it.
Your events, their meta and your categories are preserved -- delete them in the
admin first if you want them gone.

== Reference ==

= Calendar shortcode =

`[abm_calendar]`

A month-grouped list of upcoming events -- flyer, title, short description, date,
time, category and cost -- with a Load More button that pulls the next batch over
AJAX. The description is the event's excerpt, or its trimmed content when there
is no excerpt, and is omitted for events with neither.

Batch sizes are set under Settings > Calendar Shortcode and can be overridden per
placement:

`[abm_calendar initial="8" more="6"]`

Filter to one category:

`[abm_calendar category="music"]`

Category tags in the list follow a global toggle (Settings > Calendar Shortcode >
Category Tags). Each event can override it under Event Details > Category Tag:
Default, Show, or Hide.

= Field shortcodes =

Use inside a Looper Consumer, where they read the current event, or pass
`id="123"`:

* `[abm_event_date]` -- formatted with the global Date Format
* `[abm_event_date format="l, F jS"]` -- per-tag override
* `[abm_event_time]` -- "8:00 PM - Close"
* `[abm_event_category sep=", "]` -- category names
* `[abm_cost]` -- formatted door cost
* `[abm_flyer_url size="large"]` -- flyer or placeholder URL
* `[abm_flyer size="large"]` -- flyer as an `<img>`
* `[abm_ical]` -- .ics URL
* `[abm_gcal]` -- Google Calendar URL
* `[abm_event_export]` -- both export buttons

= Meta keys for Dynamic Content =

Reference these inside a Looper Consumer with `{{dc:post:meta key="..."}}`:

* `abm_date_display` -- "26 Jun", using the global Date Format
* `abm_time_display` -- "8:00 PM - Close"
* `abm_cost_display` -- "$10", empty when free or unset
* `abm_flyer_url` -- flyer URL, falling back to the placeholder
* `abm_ical` -- per-event .ics link
* `abm_gcal` -- Google Calendar link
* `abm_event_date` -- Y-m-d, raw; use for Looper sorting and upcoming filters
* `abm_event_time_start` -- H:i, raw
* `abm_event_time_end` -- H:i or "close", raw
* `abm_flyer_id` -- flyer attachment ID, mirroring the Featured Image

Display values are generated on save. Do not write to them.

= Cornerstone Looper setup =

1. Add a Looper Provider element. Either choose the plugin's own provider, which
   loops over dates, or use Query Builder with:
   * Post Type: Event (abm_event)
   * Order By: Meta Value, Meta Key: abm_event_date, Order: ASC
   * Upcoming only: Meta Query, key `abm_event_date`, compare `>=`, value
     `{{dc:date format="Y-m-d"}}`, type DATE
2. Inside the Consumer, bind elements through Dynamic Content:
   * Image source: `{{dc:post:meta key="abm_flyer_url"}}`
   * Heading: `{{dc:post:title}}`
   * Date: `{{dc:post:meta key="abm_date_display"}}`
   * Time: `{{dc:post:meta key="abm_time_display"}}`
   * Category: `{{dc:post:terms taxonomy="abm_category"}}`
   * Link: `{{dc:post:permalink}}`

Query Builder loops over posts, so a repeating event appears once. Use the
plugin's provider, or `[abm_calendar]`, for a listing that shows every date.

= REST API =

Event meta is exposed on `wp/v2/abm_event`, so a whole event including its
recurrence rule can be created or edited in one authenticated call.

Each event also carries a read-only `abm_occurrences` object:

* `count` -- how many dates the event currently has
* `next` -- its next upcoming date (Y-m-d), or empty if it has none left
* `locked` -- true when the dates cannot be changed by editing the date field

Check it before editing dates:

`GET /wp-json/wp/v2/abm_event/<id>?_fields=id,abm_occurrences`

The object costs one query per event and is only computed when asked for, so
listing events with `_fields` that omit it is free.

= CSV import =

Events > Import accepts a CSV exported from another calendar plugin. The format
is auto-detected or chosen manually, and the importer framework
(`includes/importers/`) takes additional formats as subclasses of `ABM_Importer`.

Options: publish or draft; reuse images already in the Media Library; download
images on or off; update events previously imported from the same source, matched
by source ID; and a dry run that reports counts without writing anything.

Reusing existing images matters when moving from another plugin on the same site,
where the flyers are already in your Media Library. The importer matches each
event's image URL to an existing attachment -- by exact URL, then by local
uploads path, then by filename, so differing CDN hosts still match -- and reuses
it rather than downloading a duplicate.

Importing is restricted to administrators. The upload is validated (.csv, tab,
comma or semicolon auto-detected, BOM-tolerant, 5 MB cap) and only HTTP(S) image
URLs are fetched.

**This importer is not the right tool for Modern Events Calendar.** It keys on
the source event ID, so every date of a repeating event collapses onto one
record. Use Migrate & Tools instead.

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

= 2.14.0 =
Repeating events now export as recurring events rather than one night at a time.
Existing links keep working; nothing needs reconfiguring.

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

= 2.15.3 =
* The Description is written for someone deciding whether to install the plugin.
  It opened with the data model and argued its design decisions, which is the
  wrong thing for a plugin page to do. It now says what the plugin is, who it
  suits, and what it does, in about half the length.

= 2.15.2 =
Fix only, both in how readme.txt is rendered in the details modal.
* A paragraph that opens in bold was read as a bullet, which swallowed its first
  asterisk and left the rest of the markup showing in the text. A bullet now
  requires a space after the asterisk, which is what separates the two.
* Italics render. `*like this*` reached the page with its asterisks intact.

= 2.15.1 =
Documentation, and the details modal that renders it.
* The Description now describes the plugin. It was a technical reference written
  for whoever was maintaining the site it started on -- accurate, but it never
  said what the plugin is or who it is for, which is the one thing a plugin page
  has to do.
* That reference material was not thrown away. Setup moved to Installation, the
  things that surprise people moved to Frequently Asked Questions, and the meta
  keys, shortcodes, Looper setup and REST notes moved to Reference. All four are
  tabs in the details modal.
* Fix: numbered steps in readme.txt rendered as one run-on paragraph in the
  modal, because only "*" bullets were recognised. "1." is a numbered list now,
  and switching between the two closes the open list rather than mixing markers.

= 2.15.0 =
* "View details" on the Plugins screen opens a real details modal instead of
  reading "Plugin not found". That link asks the wordpress.org API about the
  plugin's slug, and a self-hosted plugin is not there, so the request 404s. The
  plugin now answers for its own slug and leaves every other plugin alone.
* The modal's content is read from readme.txt and the current GitHub release, so
  it cannot drift from what shipped -- the same reason the Changelog screen is
  rendered from readme.txt rather than a second copy in code.
* "View details" is now present whether or not an update is pending. WordPress
  only shows the link for a plugin it holds a slug for, and it takes that slug
  from the update check, so previously the link appeared only while an update
  was waiting.
* Fix: "Tested up to" was read from the plugin headers, which never carry it --
  WordPress does not recognise that header, so the value reported with an update
  was always empty. It comes from readme.txt now.

= 2.14.1 =
Fix only.
* The event page lays out as intended. On a wide screen the flyer and the details
  share the top row, with the plain title and then the description full-width
  beneath them; on a phone the order is flyer, title, description, details, so
  the visitor reads what the event is before the meta list.
* Source order is the phone order, which is also the order the page reads best
  in, and the wide screen lifts the details with CSS rather than the markup
  changing. The stacking width and the reordering width are the same number, so
  there is no band where one has happened and the other has not.

= 2.14.0 =
* Repeating events export as repeating events. "Download iCal" and "Add to
  Google Calendar" on a weekly night now hand over the series rather than that
  one date, so it is saved once instead of every week.
* The series is described from the night being viewed forward, never from the
  series start, so no past dates are written into anyone's calendar and DTSTART
  is a real instance of the rule as RFC 5545 requires.
* Events that carry a recurrence rule are described from the rule. Events
  imported from another calendar carry no rule -- their dates came from the
  source system rather than from a pattern -- so the pattern is recovered from
  the dates themselves: weekly and every-N-weekly, monthly on a date, and
  monthly on the nth weekday, with skipped nights kept as exceptions.
* Where no pattern fits, the .ics lists the dates outright rather than inventing
  a rule, and the Google Calendar link stays a single date. A wrong rule would
  put nights in a visitor's calendar that the venue is not open for.
* The Google Calendar link carries the rule but not the exceptions. Its format
  allows one line and a URL cannot hold a line break, so the .ics is the
  faithful export of the two.
* A series is identified by the event rather than by the night it was saved
  from, so saving the same weekly night from two different rows updates one
  entry instead of leaving every night duplicated. Single dates are unchanged.

= 2.13.2 =
Fix only.
* On a wide screen an event's details -- date, time, category, cover -- sat
  underneath the flyer instead of beside it, leaving the space to the right of
  the flyer empty. The plain restatement of the title was a fourth block in the
  same flex row and had to be full-width to read properly, which broke the row
  and pushed the details below it. The title now opens the details column
  instead, so it introduces the meta list at every width and the columns pair up
  as intended. This also retires the last of the ordering rules behind two
  earlier layout fixes.
* The event page no longer shows a Category row when categories are switched off.
  It read the terms directly while the calendar honoured the setting, so turning
  categories off hid them in one place and not the other. Both now use the same
  check, including the per-event override.

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
