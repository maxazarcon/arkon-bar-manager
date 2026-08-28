<?php
/**
 * Turn a pasted list of events into rows.
 *
 * A venue's next month usually arrives as prose: a line per night with a date,
 * who is playing and a time, in whatever order and punctuation the person who
 * wrote it felt like. Something like:
 *
 *     Sep 4 - Old Codger, Pickled, Scraps - 8pm
 *     September 5 | The Blusterfields | 9pm-close | $5
 *     9/11  Trivia Night  8:00 PM  free
 *
 * This finds the date and the time in a line and treats whatever is left as the
 * title. It works that way round deliberately: dates and times have shapes worth
 * recognising, and a band name does not. Splitting on commas would be the
 * obvious approach and it is wrong here, because the title is the field most
 * likely to contain one.
 *
 * Nothing it produces is written anywhere directly. Every row goes into an
 * editable table first, which is what makes a forgiving parser safe: a wrong
 * guess costs a correction, not a bad event. Rows carry notes explaining what
 * was assumed so those corrections are easy to spot.
 *
 * Deliberately free of WordPress, so it can be exercised offline --
 * see tests/bulk-parse.php.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Bulk_Parser {

	/** Longest line worth attempting, to bound pathological input. */
	const MAX_LINE = 500;

	/** Most rows one paste may produce. */
	const MAX_ROWS = 200;

	/**
	 * Parse pasted text into rows, actions and leftovers.
	 *
	 * A booking list is usually written as instructions to a person rather than
	 * as data, and it does not only contain additions:
	 *
	 *     Add Sept 27 Kindred Eye 7:00-10:00pm
	 *     Delete July 23rd UniS
	 *     Change July 24 to July 25 2:00-5:00pm Outdoor Couch
	 *
	 * Reading every dated line as an event would turn that second line into a new
	 * show called "UniS" -- creating the very thing it asks to remove. So the verb
	 * is read first, and only additions become rows. Deletions are handed back
	 * separately as **actions**: things the list asks for that this screen will
	 * not do on its own.
	 *
	 * An entry can also span lines, with the title beneath the date:
	 *
	 *     UPDATE and add flyer: Sept 9th 7:00pm-12:00am
	 *     Hopscotch Kickoff Party
	 *     Cesar Jesus featuring Brizzl, Vessa the Floet, Dude Called Jack
	 *
	 * so a dated line opens an entry, the next untitled line supplies its title,
	 * and anything after that becomes the description -- which is where a lineup
	 * belongs anyway.
	 *
	 * Everything the parser could not place comes back in `skipped`. A month
	 * heading turning up there is harmless; a real night with a mistyped date
	 * turning up there is the whole point, because one missing show out of thirty
	 * is exactly the kind of loss nobody notices until the Friday.
	 *
	 * @param string $text  Pasted text.
	 * @param string $today Y-m-d, for resolving dates written without a year.
	 * @return array{rows:array,actions:string[],skipped:string[]}
	 */
	public static function parse( $text, $today ) {
		$rows    = array();
		$actions = array();
		$skipped = array();
		$lines   = preg_split( '/\R/', (string) $text );

		$current     = null;
		$open_action = null;

		$flush = static function () use ( &$current, &$rows, &$skipped ) {
			if ( null === $current ) {
				return;
			}
			// An entry that never found a title is not an event. That covers the
			// date headings a list is organised under ("June 1st:") and lines like
			// "Add Aug 28th flyer", which ask for something to be done to an event
			// that already exists.
			if ( '' === $current['title'] ) {
				$skipped[] = $current['original'];
			} else {
				$rows[] = $current;
			}
			$current = null;
		};

		foreach ( $lines as $raw ) {
			if ( count( $rows ) >= self::MAX_ROWS ) {
				break;
			}

			$line = trim( $raw );

			if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
				$flush();
				$open_action = null; // A blank line ends an instruction as well as an entry.
				continue;
			}
			if ( strlen( $line ) > self::MAX_LINE ) {
				$line = substr( $line, 0, self::MAX_LINE );
			}

			$row = self::parse_line( $line, $today );

			if ( $row ) {
				$flush();

				// Removals, and instructions about a flyer on an event that already
				// exists. Never created, never silently dropped.
				if ( in_array( $row['verb'], array( 'remove', 'flyer' ), true ) ) {
					$actions[]   = $line;
					$open_action = count( $actions ) - 1;
					continue;
				}
				$open_action = null;

				if ( 'change' === $row['verb'] ) {
					/*
					 * A change naming two dates -- "Change July 24 to July 25" --
					 * cannot be read confidently: the first date is the one being
					 * left and the second the one being moved to, and reading the
					 * wrong one puts the show on a night the venue is not open.
					 * Hand it back instead of guessing.
					 *
					 * A change naming one date is kept as a row. If the event
					 * already exists the create step skips it as a duplicate; if it
					 * does not, adding it is what was wanted. Flagged either way.
					 */
					if ( self::count_dates( $line, $today ) > 1 ) {
						$actions[] = $line;
						continue;
					}
					$row['notes'][] = 'listed as a change';
				}

				$current = $row;
				continue;
			}

			// No date on this line.
			if ( null !== $current ) {
				if ( '' === $current['title'] ) {
					$ignored              = '';
					$current['title']     = self::clean_title( self::strip_noise( $line, $ignored ) );
					$current['original'] .= ' / ' . $line;
					// The complaint was recorded while reading the line above, which
					// genuinely had no title on it. This line just supplied one.
					$current['notes'] = array_values(
						array_diff( $current['notes'], array( 'no title found' ) )
					);
					continue;
				}
				/*
				 * A continuation line normally belongs to the entry above it -- a
				 * lineup, a set time, a note about the night. But a line that was
				 * plainly *trying* to be its own event and failed, because its date
				 * is impossible or misspelt, must not be quietly folded into the
				 * previous event's description. That is a show going missing.
				 *
				 * The tell is a month name or a numeric date, not a time: "1pm:
				 * Dave Bender Rebellion" is a set time and belongs in the
				 * description, while "Feb 31 - Nobody - 8pm" is a broken event and
				 * belongs in front of a human.
				 */
				if ( self::looks_dated( $line ) ) {
					$skipped[] = $line;
					continue;
				}

				$current['description'] = '' === $current['description'] ? $line : $current['description'] . "\n" . $line;
				continue;
			}

			/*
			 * A line under an instruction belongs to it. An entry asking for a
			 * change often carries its title and details on the lines beneath, and
			 * splitting those across the "not read" list would leave the reader
			 * assembling one instruction from two places.
			 */
			if ( null !== $open_action ) {
				$actions[ $open_action ] .= "\n" . $line;
				continue;
			}

			$skipped[] = $line;
		}

		$flush();

		return array(
			'rows'    => $rows,
			'actions' => $actions,
			'skipped' => $skipped,
		);
	}

	/**
	 * Parse one line.
	 *
	 * @param string $line  One line of pasted text.
	 * @param string $today Y-m-d.
	 * @return array<string,mixed>|null
	 */
	public static function parse_line( $line, $today ) {
		$original = $line;
		$notes    = array();
		$verb     = '';

		$line = self::strip_noise( $line, $verb );

		// Cost first: "$10" and "free" are unambiguous and pulling them out early
		// keeps a bare "10" from being mistaken for a day of the month later.
		$cost = '';
		if ( preg_match( '/(?<![\w.])(?:\$\s?(\d+(?:\.\d{2})?)|(\d+(?:\.\d{2})?)\s*(?:dollars?|bucks)\b)/i', $line, $m ) ) {
			$cost = '' !== $m[1] ? $m[1] : $m[2];
			$line = self::cut( $line, $m[0] );
		} elseif ( preg_match( '/\b(?:free|no cover|free entry|no charge)\b/i', $line, $m ) ) {
			$cost = '0';
			$line = self::cut( $line, $m[0] );
		}

		// Strip a leading weekday name; it is decoration, and it would otherwise
		// look like part of the title once the date has been removed.
		$line = preg_replace( '/^\s*(?:mon|tues?|wed(?:nes)?|thur?s?|fri|sat(?:ur)?|sun)(?:day)?\b[\s,.\-\x{2013}\x{2014}|]*/iu', '', $line );

		$date = self::find_date( $line, $today, $notes );
		if ( null === $date ) {
			// A line with no date at all is far more likely to be a heading
			// ("SEPTEMBER", "-- week 2 --") than a real event, so it is dropped
			// rather than turned into a row needing repair.
			return null;
		}
		$line = self::cut( $line, $date['matched'] );

		$times = self::find_times( $line, $notes );
		if ( $times ) {
			$line = self::cut( $line, $times['matched'] );
		}

		$title = self::clean_title( $line );
		if ( '' === $title ) {
			$notes[] = 'no title found';
		}

		return array(
			'date'        => $date['ymd'],
			'start'       => $times ? $times['start'] : '',
			'end'         => $times ? $times['end'] : '',
			'title'       => $title,
			'cost'        => $cost,
			'description' => '',
			'verb'        => $verb,
			'notes'       => $notes,
			'original'    => $original,
		);
	}

	/**
	 * Strip the instruction wrapping off a line and report what it was asking for.
	 *
	 * A hand-written list is addressed to a person, so its lines open with verbs
	 * and close with reminders. Neither belongs in an event title: left in place,
	 * "Add Sept 27 Kindred Eye" becomes a band called "Add Kindred Eye".
	 *
	 * @param string $line Line.
	 * @param string $verb Set to '', 'add', 'change' or 'remove'.
	 * @return string
	 */
	private static function strip_noise( $line, &$verb ) {
		$verb = '';

		// Leading bullet or dash used as a list marker.
		$line = preg_replace( '/^[\s\-\x{2013}\x{2014}*\x{2022}]+/u', '', (string) $line );

		/*
		 * A removal can be asked for mid-sentence:
		 *
		 *     And Aug 28 is listed twice. Delete one of them.
		 *
		 * so the whole line is searched, not just its opening. This is the one
		 * misreading with a cost beyond a tidy-up -- it creates an event out of a
		 * note asking for one to be taken away -- and no real event title contains
		 * the word, so scanning the line for it is safe.
		 */
		if ( preg_match( '/\b(?:delete|remove|cancel(?:led)?)\b/i', $line ) ) {
			$verb = 'remove';
			return trim( $line );
		}

		// A leading instruction, up to an optional colon. Read the verb before
		// removing it. "Delete" is the one that matters most: acting on it as an
		// addition would create the thing the list is asking to be rid of.
		if ( preg_match( '/^\s*(please\s+)?(add(?:ed)?|delete|remove|change|edit|update|move)\b([^:]*?):?\s+/i', $line, $m ) ) {
			$found  = strtolower( $m[2] );
			$phrase = strtolower( $m[0] );

			if ( in_array( $found, array( 'delete', 'remove' ), true ) ) {
				$verb = 'remove';
			} elseif ( in_array( $found, array( 'change', 'edit', 'move' ), true ) ) {
				$verb = 'change';
			} elseif ( 'update' === $found ) {
				// "UPDATE and add flyer:" is asking for both. Treat it as a change,
				// which is the more cautious of the two readings.
				$verb = 'change';
			} else {
				$verb = 'add';
			}

			// Only swallow the matched run when it is instruction rather than
			// content: "Add Sept 27 ..." yes, "Added Attractions" no.
			if ( strlen( $m[0] ) < 40 && ! preg_match( '/\d/', $phrase ) ) {
				$line = substr( $line, strlen( $m[0] ) );
			}

			/*
			 * Some lines are about the artwork, not about an event:
			 *
			 *     Add flyer 9-12 Jack the Radio day party
			 *     Change flyer 9-10 day party flyer
			 *     Change 9-25 flyer (change Rodney Henry to Stephen Clair)
			 *
			 * Every one names a show that is already on the calendar and asks for
			 * its flyer to be attached or swapped. Read as additions they create
			 * events called "day party flyer" and "(change Rodney Henry to Stephen
			 * Clair)" -- worse than useless, because they look like real listings.
			 *
			 * Two shapes give it away. The verb takes "flyer" as its object
			 * ("Add flyer ..."), or the instruction is a change and mentions one at
			 * all -- changing a flyer is by definition work on an event that
			 * exists. Note "Add ... and add flyer" is neither: there the flyer is a
			 * trailing reminder on a genuine new show.
			 */
			$is_flyer_object = (bool) preg_match( '/^\s*(?:the\s+|a\s+)?flyers?\b/i', $line );
			$mentions_flyer  = (bool) preg_match( '/\bflyers?\b/i', $line );

			if ( $is_flyer_object || ( 'change' === $verb && $mentions_flyer ) ) {
				$verb = 'flyer';
			}
		}

		/*
		 * Reminders about artwork. The flyer is a real field on the event, so
		 * being told to attach one says nothing about what the event is called.
		 *
		 * Only the phrase itself is removed, never what follows it. An earlier
		 * version swallowed to the end of the sentence, which quietly ate the date
		 * out of "UPDATE and add flyer: Sept 9th 7:00pm-12:00am" and turned a real
		 * night into an unreadable line.
		 */
		$line = preg_replace( '/\(?\s*(?:and\s+|then\s+)?(?:see\s+(?:the\s+)?flyer\s+and\s+)?(?:add|attach|use)\s+(?:the\s+|a\s+)?flyers?\s*\)?/i', ' ', $line );

		return trim( (string) $line );
	}

	/**
	 * Whether a line was reaching for a date, whether or not it reached a valid
	 * one. Used to tell a broken event from a line of description.
	 *
	 * @param string $line Line.
	 * @return bool
	 */
	private static function looks_dated( $line ) {
		$names = implode( '|', array_keys( self::months() ) );
		if ( preg_match( '/\b(' . $names . ')\.?\s*\d/i', $line ) ) {
			return true;
		}
		if ( preg_match( '/\b\d{1,2}(?:st|nd|rd|th)?\s+(' . $names . ')\b/i', $line ) ) {
			return true;
		}
		// A numeric date, but not a time: 9/4 yes, 9:00 no.
		return (bool) preg_match( '#\b\d{1,2}[/\-]\d{1,2}(?:[/\-]\d{2,4})?\b#', $line );
	}

	/**
	 * How many dates a line contains.
	 *
	 * Used to tell "Change July 24 to July 25" -- which names the date being left
	 * and the date being moved to, and cannot be read confidently either way --
	 * from a single-date instruction that can.
	 *
	 * @param string $line  Line.
	 * @param string $today Y-m-d.
	 * @return int
	 */
	public static function count_dates( $line, $today ) {
		$count  = 0;
		$notes  = array();
		$cursor = (string) $line;

		while ( $count < 5 ) {
			$hit = self::find_date( $cursor, $today, $notes );
			if ( null === $hit ) {
				break;
			}
			++$count;
			$cursor = self::cut( $cursor, $hit['matched'] );
		}

		return $count;
	}

	/* --------------------------------------------------------------------- */
	/* Dates                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Month names and their numbers.
	 *
	 * Order matters and is not alphabetical: these become a regex alternation,
	 * which takes the first branch that matches, so every full name has to come
	 * before any abbreviation that prefixes it. "sep" listed before "september"
	 * would match "Sep" out of "September" and leave "tember" in the title.
	 */
	private static function months() {
		return array(
			'january'   => 1,
			'february'  => 2,
			'september' => 9,
			'october'   => 10,
			'november'  => 11,
			'december'  => 12,
			'august'    => 8,
			'march'     => 3,
			'april'     => 4,
			'june'      => 6,
			'july'      => 7,
			'sept'      => 9,
			'jan'       => 1,
			'feb'       => 2,
			'mar'       => 3,
			'apr'       => 4,
			'may'       => 5,
			'jun'       => 6,
			'jul'       => 7,
			'aug'       => 8,
			'sep'       => 9,
			'oct'       => 10,
			'nov'       => 11,
			'dec'       => 12,
		);
	}

	/**
	 * Find a date anywhere in the line.
	 *
	 * @param string   $line  Line.
	 * @param string   $today Y-m-d.
	 * @param string[] $notes Collected notes, by reference.
	 * @return array{ymd:string,matched:string}|null
	 */
	private static function find_date( $line, $today, array &$notes ) {
		// ISO first: unambiguous, and the only form that needs no guessing.
		if ( preg_match( '/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $line, $m ) ) {
			$ymd = self::build( (int) $m[1], (int) $m[2], (int) $m[3] );
			if ( $ymd ) {
				return array(
					'ymd'     => $ymd,
					'matched' => $m[0],
				);
			}
		}

		$months = self::months();
		$names  = implode( '|', array_keys( $months ) );

		// "Sep 4", "September 4th, 2026"
		if ( preg_match( '/\b(' . $names . ')\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:\s*,?\s*(\d{4}))?\b/i', $line, $m ) ) {
			$month = $months[ strtolower( $m[1] ) ];
			$day   = (int) $m[2];
			$year  = isset( $m[3] ) && '' !== $m[3] ? (int) $m[3] : self::infer_year( $month, $day, $today, $notes );
			$ymd   = self::build( $year, $month, $day );
			if ( $ymd ) {
				return array(
					'ymd'     => $ymd,
					'matched' => $m[0],
				);
			}
		}

		// "4 Sep", "4th September 2026"
		if ( preg_match( '/\b(\d{1,2})(?:st|nd|rd|th)?\s+(' . $names . ')\.?(?:\s*,?\s*(\d{4}))?\b/i', $line, $m ) ) {
			$day   = (int) $m[1];
			$month = $months[ strtolower( $m[2] ) ];
			$year  = isset( $m[3] ) && '' !== $m[3] ? (int) $m[3] : self::infer_year( $month, $day, $today, $notes );
			$ymd   = self::build( $year, $month, $day );
			if ( $ymd ) {
				return array(
					'ymd'     => $ymd,
					'matched' => $m[0],
				);
			}
		}

		// "9/4", "9/4/26", "9-4-2026". Read month-first, which is the convention
		// wherever this is used; the row is shown for confirmation either way.
		if ( preg_match( '#\b(\d{1,2})[/\-.](\d{1,2})(?:[/\-.](\d{2,4}))?\b#', $line, $m ) ) {
			$month = (int) $m[1];
			$day   = (int) $m[2];
			if ( isset( $m[3] ) && '' !== $m[3] ) {
				$year = (int) $m[3];
				if ( $year < 100 ) {
					$year += 2000;
				}
			} else {
				$year = self::infer_year( $month, $day, $today, $notes );
			}
			$ymd = self::build( $year, $month, $day );
			if ( $ymd ) {
				return array(
					'ymd'     => $ymd,
					'matched' => $m[0],
				);
			}
		}

		return null;
	}

	/**
	 * Choose a year for a date written without one: the next time that day comes
	 * round, counting today as still to come. A list typed in December for
	 * January should land in January, not eleven months in the past.
	 *
	 * @param int      $month Month.
	 * @param int      $day   Day.
	 * @param string   $today Y-m-d.
	 * @param string[] $notes Notes, by reference.
	 * @return int
	 */
	private static function infer_year( $month, $day, $today, array &$notes ) {
		$parts = explode( '-', (string) $today );
		$year  = isset( $parts[0] ) ? (int) $parts[0] : (int) gmdate( 'Y' );

		$candidate = self::build( $year, $month, $day );
		if ( $candidate && $candidate < $today ) {
			++$year;
			$notes[] = 'year assumed ' . $year;
		}

		return $year;
	}

	/**
	 * Validate and format a date, rejecting things like 31 February.
	 *
	 * @param int $year  Year.
	 * @param int $month Month.
	 * @param int $day   Day.
	 * @return string Y-m-d, or '' if impossible.
	 */
	private static function build( $year, $month, $day ) {
		if ( $year < 1970 || $year > 2200 || $month < 1 || $month > 12 || $day < 1 || $day > 31 ) {
			return '';
		}
		if ( ! checkdate( $month, $day, $year ) ) {
			return '';
		}
		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/* --------------------------------------------------------------------- */
	/* Times                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Find a time or a time range.
	 *
	 * @param string   $line  Line, with the date already removed.
	 * @param string[] $notes Notes, by reference.
	 * @return array{start:string,end:string,matched:string}|null
	 */
	private static function find_times( $line, array &$notes ) {
		$clock = '\d{1,2}(?::\d{2})?\s*(?:am|pm|a\.m\.|p\.m\.)?';
		$sep   = '\s*(?:-|\x{2013}|\x{2014}|to|until|till|\x{2192})\s*';

		// A range: "8pm-11pm", "9pm to close", "20:00 - 23:00".
		if ( preg_match( '/(' . $clock . ')' . $sep . '(' . $clock . '|close|closing|late)/iu', $line, $m ) ) {
			$start = self::to_24h( $m[1] );
			$raw   = strtolower( trim( $m[2] ) );
			if ( in_array( $raw, array( 'close', 'closing', 'late' ), true ) ) {
				$end = 'close';
			} else {
				$end = self::to_24h( $m[2] );
			}
			if ( '' !== $start ) {
				// "8-11pm": the meridiem on the end applies to both.
				if ( 'close' !== $end && '' !== $end && ! preg_match( '/(?:am|pm)/i', $m[1] ) && preg_match( '/pm/i', $m[2] ) ) {
					$start = self::apply_pm( $start );
				}
				return array(
					'start'   => $start,
					'end'     => $end,
					'matched' => $m[0],
				);
			}
		}

		// A single time: "8pm", "20:00", "8:30 PM".
		if ( preg_match( '/(?<![\d:])(' . $clock . ')(?![\d:])/i', $line, $m ) ) {
			$start = self::to_24h( $m[1] );
			if ( '' !== $start ) {
				$notes[] = 'no end time';
				return array(
					'start'   => $start,
					'end'     => '',
					'matched' => $m[0],
				);
			}
		}

		$notes[] = 'no time found';
		return null;
	}

	/**
	 * Normalize a clock reading to H:i.
	 *
	 * A bare hour with no meridiem is read as evening when it could plausibly be
	 * one: this lists gigs, and "9" on a bar's calendar has never meant 9 in the
	 * morning. 24-hour readings are left alone.
	 *
	 * @param string $raw Clock text.
	 * @return string H:i, or ''.
	 */
	private static function to_24h( $raw ) {
		$raw = strtolower( str_replace( array( '.', ' ' ), '', trim( (string) $raw ) ) );
		if ( ! preg_match( '/^(\d{1,2})(?::(\d{2}))?(am|pm)?$/', $raw, $m ) ) {
			return '';
		}

		$hour   = (int) $m[1];
		$minute = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
		$mer    = isset( $m[3] ) ? $m[3] : '';

		if ( $minute > 59 ) {
			return '';
		}

		if ( 'pm' === $mer ) {
			if ( $hour < 12 ) {
				$hour += 12;
			}
		} elseif ( 'am' === $mer ) {
			if ( 12 === $hour ) {
				$hour = 0;
			}
		} elseif ( $hour >= 1 && $hour <= 11 && ! isset( $m[2] ) ) {
			// Bare "8" with no minutes and no meridiem.
			$hour += 12;
		} elseif ( $hour >= 1 && $hour <= 11 && isset( $m[2] ) ) {
			// "8:30" is ambiguous but reads as evening on this kind of listing.
			$hour += 12;
		}

		if ( $hour > 23 ) {
			return '';
		}

		return sprintf( '%02d:%02d', $hour, $minute );
	}

	/**
	 * Push an already-normalized morning time into the afternoon, for "8-11pm".
	 *
	 * @param string $hi H:i.
	 * @return string
	 */
	private static function apply_pm( $hi ) {
		list( $h, $m ) = array_map( 'intval', explode( ':', $hi ) );
		if ( $h < 12 ) {
			$h += 12;
		}
		return sprintf( '%02d:%02d', $h, $m );
	}

	/* --------------------------------------------------------------------- */
	/* Leftovers                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Remove the first occurrence of a matched fragment.
	 *
	 * @param string $line  Line.
	 * @param string $match Fragment.
	 * @return string
	 */
	private static function cut( $line, $match ) {
		$pos = strpos( $line, $match );
		if ( false === $pos ) {
			return $line;
		}
		return substr( $line, 0, $pos ) . ' ' . substr( $line, $pos + strlen( $match ) );
	}

	/**
	 * Tidy what is left of the line into a title.
	 *
	 * @param string $line Remainder.
	 * @return string
	 */
	private static function clean_title( $line ) {
		// Collapse the separators the line was held together with.
		$line = preg_replace( '/[|\t]+/', ' ', $line );
		$line = preg_replace( '/\s{2,}/', ' ', $line );
		$line = trim( $line );
		// Strip leading and trailing punctuation left behind by the cuts, without
		// touching punctuation inside the title itself.
		$line = preg_replace( '/^[\s,;:.\-\x{2013}\x{2014}]+|[\s,;:.\-\x{2013}\x{2014}]+$/u', '', $line );

		/*
		 * These two only surface once the date and time have been lifted out, which
		 * is why they are cleaned here rather than with the rest of the noise.
		 *
		 *   "Add Aug 29th with The Blusterfields"  ->  "with The Blusterfields"
		 *   "Add Aug 28th flyer"                   ->  "flyer"
		 *
		 * The first joins the instruction to the lineup and reads as part of the
		 * band name if it is left. The second is a whole line asking for artwork on
		 * an event that already exists; removing the word empties the title, which
		 * is what stops it becoming an event of its own.
		 */
		$line = preg_replace( '/^\s*(?:with|w\/|feat(?:uring)?\.?)\s+/i', '', (string) $line );
		$line = preg_replace( '/^\s*flyers?\b\s*/i', '', (string) $line );

		return trim( (string) $line );
	}
}
