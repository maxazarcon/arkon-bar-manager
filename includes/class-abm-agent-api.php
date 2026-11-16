<?php
/**
 * The agent API: everything a person can do to events in the admin, over REST,
 * under one rule about what is applied and what waits for approval.
 *
 * Routes live under abm/v1 and are thin: each one hands its parameters to
 * ABM_Agent_Ops and turns the outcome into a status code.
 *
 *   200 / 201  applied
 *   202        approval_required: nothing changed; a person decides in the admin
 *   409        refused: nothing changed, and approval would not help
 *
 * This file also defines the Event Agent role. An account with that role can
 * reach this API and nothing else: not the ordinary wp/v2 routes, which would
 * let it write events without the rule, and not the admin.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Agent_API {

	const NS   = 'abm/v1';
	const ROLE = 'abm_agent';

	/** Largest flyer accepted, in bytes. */
	const MAX_UPLOAD = 5242880;

	/** Rows one bulk request may create. */
	const MAX_BULK = 100;

	/** Seconds a preview link stays valid. */
	const PREVIEW_TTL = 600;

	/** Parser notes that mean a row was guessed at, so it is kept as a draft. */
	const UNSURE_NOTES = array( 'assumed', 'no time found', 'listed as a change' );

	/** @var ABM_Agent_API|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'confine_agent' ), 1, 3 );
		add_action( 'admin_init', array( $this, 'block_admin' ), 1 );
		add_action( 'parse_request', array( $this, 'maybe_preview' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Role                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Create the Event Agent role, or bring its capabilities up to date.
	 *
	 * The delete capabilities are what WordPress requires to move a post to the
	 * trash. Nothing in this API deletes permanently, and the role cannot reach
	 * a route that does.
	 */
	public static function install_role() {
		$caps = array(
			'read'                   => true,
			'upload_files'           => true,
			'edit_posts'             => true,
			'edit_published_posts'   => true,
			'edit_others_posts'      => true,
			'edit_private_posts'     => true,
			'publish_posts'          => true,
			'delete_posts'           => true,
			'delete_published_posts' => true,
			'delete_others_posts'    => true,
		);

		$role = get_role( self::ROLE );
		if ( ! $role ) {
			add_role( self::ROLE, __( 'Event Agent', 'arkon-bar-manager' ), $caps );
			return;
		}
		foreach ( $caps as $cap => $grant ) {
			$role->add_cap( $cap, $grant );
		}
	}

	/**
	 * @param WP_User|null $user User, or null for the current one.
	 * @return bool
	 */
	public static function is_agent( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		return $user instanceof WP_User && in_array( self::ROLE, (array) $user->roles, true );
	}

	/**
	 * Keep an agent account inside this API.
	 *
	 * @param mixed           $result  Response to short-circuit with.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function confine_agent( $result, $server, $request ) {
		if ( ! self::is_agent() ) {
			return $result;
		}
		$route = (string) $request->get_route();
		if ( 0 === strpos( $route, '/' . self::NS . '/' ) || '/' . self::NS === $route ) {
			return $result;
		}
		return new WP_Error( 'abm_agent_route_forbidden', __( 'This account can only use the event agent API.', 'arkon-bar-manager' ), array( 'status' => 403 ) );
	}

	/**
	 * An agent account has no business in the admin.
	 */
	public function block_admin() {
		if ( wp_doing_ajax() || ! self::is_agent() ) {
			return;
		}
		wp_die( esc_html__( 'This account can only use the event agent API.', 'arkon-bar-manager' ), '', array( 'response' => 403 ) );
	}

	/* --------------------------------------------------------------------- */
	/* Routes                                                                */
	/* --------------------------------------------------------------------- */

	public function register_routes() {
		$read  = array( $this, 'can_read' );
		$write = array( $this, 'can_write' );
		$id    = '(?P<id>\d+)';

		$routes = array(
			array( '/occurrences', 'GET', 'get_occurrences', $read ),
			array( '/events', 'GET', 'list_events', $read ),
			array( '/events', 'POST', 'create_event', $write ),
			array( "/events/{$id}", 'GET', 'get_event', $read ),
			array( "/events/{$id}", 'POST', 'update_event', $write ),
			array( "/events/{$id}/status", 'POST', 'set_status', $write ),
			array( "/events/{$id}/trash", 'POST', 'trash_event', $write ),
			array( "/events/{$id}/restore", 'POST', 'restore_event', $write ),
			array( "/events/{$id}/skip", 'POST', 'skip_date', $write ),
			array( "/events/{$id}/unskip", 'POST', 'unskip_date', $write ),
			array( "/events/{$id}/preview", 'GET', 'preview_link', $read ),
			array( '/media', 'POST', 'upload_media', array( $this, 'can_upload' ) ),
			array( '/bulk/parse', 'POST', 'bulk_parse', $read ),
			array( '/bulk/match-flyers', 'POST', 'bulk_match', $read ),
			array( '/bulk/create', 'POST', 'bulk_create', $write ),
			array( '/categories', 'GET', 'list_categories', $read ),
			array( '/categories', 'POST', 'create_category', $write ),
			array( "/categories/{$id}", 'POST', 'rename_category', $write ),
			array( "/categories/{$id}", 'DELETE', 'delete_category', $write ),
			array( '/changes', 'GET', 'list_changes', $read ),
			array( "/changes/{$id}", 'GET', 'get_change', $read ),
			array( '/log', 'GET', 'get_log', $read ),
		);

		foreach ( $routes as $route ) {
			register_rest_route(
				self::NS,
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( $this, $route[2] ),
					'permission_callback' => $route[3],
				)
			);
		}
	}

	/** @return bool|WP_Error */
	public function can_read() {
		$type = get_post_type_object( ABM_POST_TYPE );
		if ( $type && current_user_can( $type->cap->edit_posts ) ) {
			return true;
		}
		return new WP_Error( 'abm_forbidden', __( 'You are not allowed to use the event agent API.', 'arkon-bar-manager' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/** @return bool|WP_Error */
	public function can_write() {
		return $this->can_read();
	}

	/** @return bool|WP_Error */
	public function can_upload() {
		if ( current_user_can( 'upload_files' ) ) {
			return $this->can_read();
		}
		return new WP_Error( 'abm_forbidden', __( 'You are not allowed to upload files.', 'arkon-bar-manager' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/* --------------------------------------------------------------------- */
	/* Reads                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * What is on the calendar between two dates, one row per night.
	 *
	 * Reads the occurrence table, so a weekly night appears on every night it
	 * runs, which is the only honest answer to "what is on in March".
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_occurrences( $request ) {
		global $wpdb;

		$from = abm_sanitize_date( (string) $request['from'] );
		$to   = abm_sanitize_date( (string) $request['to'] );
		if ( '' === $from || '' === $to || $to < $from ) {
			return new WP_Error( 'abm_invalid_range', __( 'from and to are required, as YYYY-MM-DD, with to on or after from.', 'arkon-bar-manager' ), array( 'status' => 400 ) );
		}
		if ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS > 366 ) {
			return new WP_Error( 'abm_invalid_range', __( 'Ask for at most a year at a time.', 'arkon-bar-manager' ), array( 'status' => 400 ) );
		}

		$statuses = $this->statuses( (string) ( $request['status'] ?? 'publish' ), false );
		if ( is_wp_error( $statuses ) ) {
			return $statuses;
		}

		$table = ABM_Occurrences::table();
		$marks = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$where = "p.post_type = %s AND o.occur_date BETWEEN %s AND %s AND p.post_status IN ({$marks})";
		$args  = array_merge( array( ABM_POST_TYPE, $from, $to ), $statuses );

		$search = trim( (string) ( $request['search'] ?? '' ) );
		if ( '' !== $search ) {
			$where .= ' AND p.post_title LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$category = absint( $request['category'] ?? 0 );
		if ( $category ) {
			$where .= " AND EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} tr
				JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tr.object_id = p.ID AND tt.taxonomy = %s AND tt.term_id = %d )";
			$args[] = ABM_TAXONOMY;
			$args[] = $category;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.occur_date, o.start_time, o.end_time, p.ID, p.post_title, p.post_status
				 FROM {$table} o JOIN {$wpdb->posts} p ON p.ID = o.post_id
				 WHERE {$where}
				 ORDER BY o.occur_date ASC, o.start_time ASC, o.id ASC LIMIT 501",
				$args
			)
		);

		$out = array();
		foreach ( array_slice( (array) $rows, 0, 500 ) as $row ) {
			$out[] = array(
				'date'   => (string) $row->occur_date,
				'start'  => (string) $row->start_time,
				'end'    => (string) $row->end_time,
				'id'     => (int) $row->ID,
				'title'  => (string) $row->post_title,
				'status' => (string) $row->post_status,
				'link'   => (string) get_permalink( (int) $row->ID ),
			);
		}

		return rest_ensure_response(
			array(
				'occurrences' => $out,
				'truncated'   => count( (array) $rows ) > 500,
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_events( $request ) {
		$statuses = $this->statuses( (string) ( $request['status'] ?? 'publish' ), true );
		if ( is_wp_error( $statuses ) ) {
			return $statuses;
		}

		$per_page = max( 1, min( 100, (int) ( $request['per_page'] ?? 20 ) ) );
		$query    = new WP_Query(
			array(
				'post_type'      => ABM_POST_TYPE,
				'post_status'    => $statuses,
				's'              => (string) ( $request['search'] ?? '' ),
				'posts_per_page' => $per_page,
				'paged'          => max( 1, (int) ( $request['page'] ?? 1 ) ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);

		$events = array();
		foreach ( $query->posts as $post_id ) {
			$event = ABM_Agent_Ops::event( (int) $post_id );
			if ( ! is_wp_error( $event ) ) {
				// The date list is the heavy part and belongs to the single read.
				unset( $event['description'], $event['occurrences']['dates'] );
				$events[] = $event;
			}
		}

		return rest_ensure_response(
			array(
				'events'      => $events,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_event( $request ) {
		$event = ABM_Agent_Ops::event( (int) $request['id'] );
		return is_wp_error( $event ) ? $event : rest_ensure_response( $event );
	}

	/**
	 * @return WP_REST_Response
	 */
	public function list_categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => ABM_TAXONOMY,
				'hide_empty' => false,
			)
		);

		$out = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$out[] = array(
				'id'    => (int) $term->term_id,
				'name'  => (string) $term->name,
				'slug'  => (string) $term->slug,
				'count' => (int) $term->count,
			);
		}
		return rest_ensure_response( $out );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_changes( $request ) {
		return rest_ensure_response( ABM_Agent_Store::list_changes( (string) ( $request['status'] ?? '' ), (int) ( $request['per_page'] ?? 50 ) ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_change( $request ) {
		$change = ABM_Agent_Store::get_change( (int) $request['id'] );
		if ( ! $change ) {
			return new WP_Error( 'abm_not_found', __( 'No such change.', 'arkon-bar-manager' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $change );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_log( $request ) {
		return rest_ensure_response( ABM_Agent_Store::get_log( (int) ( $request['per_page'] ?? 100 ), absint( $request['event'] ?? 0 ) ) );
	}

	/* --------------------------------------------------------------------- */
	/* Writes                                                                */
	/* --------------------------------------------------------------------- */

	/** @param WP_REST_Request $request Request. */
	public function create_event( $request ) {
		return $this->respond( 'create', $this->params( $request ), $request, 201 );
	}

	/** @param WP_REST_Request $request Request. */
	public function update_event( $request ) {
		return $this->respond( 'update', $this->params( $request ), $request );
	}

	/** @param WP_REST_Request $request Request. */
	public function set_status( $request ) {
		return $this->respond( 'set_status', $this->params( $request ), $request );
	}

	/** @param WP_REST_Request $request Request. */
	public function trash_event( $request ) {
		return $this->respond( 'trash', $this->params( $request ), $request );
	}

	/** @param WP_REST_Request $request Request. */
	public function restore_event( $request ) {
		return $this->respond( 'restore', $this->params( $request ), $request );
	}

	/** @param WP_REST_Request $request Request. */
	public function skip_date( $request ) {
		return $this->respond( 'skip', $this->params( $request ), $request );
	}

	/** @param WP_REST_Request $request Request. */
	public function unskip_date( $request ) {
		return $this->respond( 'unskip', $this->params( $request ), $request );
	}

	/** @param WP_REST_Request $request Request. */
	public function create_category( $request ) {
		return $this->respond( 'category_create', $this->params( $request ), $request, 201 );
	}

	/** @param WP_REST_Request $request Request. */
	public function rename_category( $request ) {
		return $this->respond( 'category_rename', $this->params( $request ), $request );
	}

	/** @param WP_REST_Request $request Request. */
	public function delete_category( $request ) {
		return $this->respond( 'category_delete', $this->params( $request ), $request );
	}

	/**
	 * Run an operation and turn its outcome into a response.
	 *
	 * @param string          $op      Operation.
	 * @param array           $params  Parameters.
	 * @param WP_REST_Request $request Request, for dry_run.
	 * @param int             $applied Status code when applied.
	 * @return WP_REST_Response|WP_Error
	 */
	private function respond( $op, array $params, $request, $applied = 200 ) {
		$result = ABM_Agent_Ops::run( $op, $params, array( 'dry_run' => $this->is_dry( $request ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$code = $applied;
		if ( ! empty( $result['details']['dry_run'] ) ) {
			$code = 200;
		} elseif ( ABM_Change_Classifier::APPROVAL === $result['outcome'] ) {
			$code = 202;
		} elseif ( ABM_Change_Classifier::REFUSED === $result['outcome'] ) {
			$code = 409;
		}

		return new WP_REST_Response( $result, $code );
	}

	/**
	 * Body and query parameters with the route's ID folded in and the flags
	 * that are not event data taken out.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private function params( $request ) {
		$json   = $request->get_json_params();
		$params = is_array( $json ) ? $json : (array) $request->get_body_params();

		unset( $params['dry_run'], $params['id'] );
		if ( isset( $request['id'] ) ) {
			$params['id'] = (int) $request['id'];
		}
		return $params;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private function is_dry( $request ) {
		return rest_sanitize_boolean( $request->get_param( 'dry_run' ) );
	}

	/**
	 * @param string $status      Requested status, or "any".
	 * @param bool   $allow_trash Whether trash may be asked for.
	 * @return string[]|WP_Error
	 */
	private function statuses( $status, $allow_trash ) {
		$live = array( 'publish', 'future', 'draft', 'pending', 'private' );
		if ( 'any' === $status ) {
			return $live;
		}
		if ( in_array( $status, $live, true ) || ( $allow_trash && 'trash' === $status ) ) {
			return array( $status );
		}
		return new WP_Error( 'abm_invalid_status', __( 'status must be publish, future, draft, pending, private or any.', 'arkon-bar-manager' ), array( 'status' => 400 ) );
	}

	/* --------------------------------------------------------------------- */
	/* Flyers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Add an image to the Media Library.
	 *
	 * Accepts a multipart upload in a field named "file", or JSON carrying
	 * filename and base64. The type is read from the bytes, not from what the
	 * caller says it is.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_media( $request ) {
		$files = $request->get_file_params();
		$name  = '';
		$bytes = '';

		if ( ! empty( $files['file']['tmp_name'] ) && is_uploaded_file( $files['file']['tmp_name'] ) ) {
			if ( (int) $files['file']['size'] > self::MAX_UPLOAD ) {
				return $this->upload_error( __( 'The image is larger than 5 MB.', 'arkon-bar-manager' ), 413 );
			}
			$name  = (string) $files['file']['name'];
			$bytes = (string) file_get_contents( $files['file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload.
		} else {
			$name = (string) $request->get_param( 'filename' );
			$b64  = (string) $request->get_param( 'base64' );
			if ( '' === $b64 ) {
				return $this->upload_error( __( 'Send the image as a multipart "file", or as JSON with filename and base64.', 'arkon-bar-manager' ), 400 );
			}
			if ( strlen( $b64 ) > (int) ceil( self::MAX_UPLOAD * 4 / 3 ) + 4 ) {
				return $this->upload_error( __( 'The image is larger than 5 MB.', 'arkon-bar-manager' ), 413 );
			}
			$bytes = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- image payload.
			if ( false === $bytes ) {
				return $this->upload_error( __( 'base64 is not valid base64.', 'arkon-bar-manager' ), 400 );
			}
		}

		if ( strlen( $bytes ) > self::MAX_UPLOAD ) {
			return $this->upload_error( __( 'The image is larger than 5 MB.', 'arkon-bar-manager' ), 413 );
		}

		$ext = self::image_extension( $bytes );
		if ( '' === $ext ) {
			return $this->upload_error( __( 'Only JPEG, PNG and WebP images are accepted.', 'arkon-bar-manager' ), 415 );
		}

		$stem = sanitize_file_name( pathinfo( $name, PATHINFO_FILENAME ) );
		$stem = '' !== $stem ? $stem : 'flyer';

		if ( $this->is_dry( $request ) ) {
			return rest_ensure_response(
				array(
					'outcome' => 'applied',
					'details' => array(
						'dry_run'  => true,
						'filename' => $stem . '.' . $ext,
					),
				)
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( $stem . '.' . $ext );
		if ( ! $tmp || false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file for sideload.
			return $this->upload_error( __( 'The image could not be saved.', 'arkon-bar-manager' ), 500 );
		}

		$attachment = media_handle_sideload(
			array(
				'name'     => $stem . '.' . $ext,
				'tmp_name' => $tmp,
			),
			0
		);

		if ( is_wp_error( $attachment ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return $this->upload_error( $attachment->get_error_message(), 500 );
		}

		ABM_Agent_Store::log(
			array(
				'user_id' => get_current_user_id(),
				'op'      => 'upload',
				'post_id' => 0,
				'outcome' => 'applied',
				'summary' => array(
					/* translators: %s: file name. */
					'text'    => sprintf( __( 'Upload image %s', 'arkon-bar-manager' ), $stem . '.' . $ext ),
					'details' => array( 'media_id' => (int) $attachment ),
				),
			)
		);

		return new WP_REST_Response(
			array(
				'outcome' => 'applied',
				'id'      => (int) $attachment,
				'url'     => (string) wp_get_attachment_url( (int) $attachment ),
			),
			201
		);
	}

	/**
	 * The image type the bytes actually are.
	 *
	 * @param string $bytes File contents.
	 * @return string jpg, png, webp, or '' for anything else.
	 */
	public static function image_extension( $bytes ) {
		if ( 0 === strncmp( $bytes, "\xFF\xD8\xFF", 3 ) ) {
			return 'jpg';
		}
		if ( 0 === strncmp( $bytes, "\x89PNG\r\n\x1A\n", 8 ) ) {
			return 'png';
		}
		if ( strlen( $bytes ) > 12 && 'RIFF' === substr( $bytes, 0, 4 ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
			return 'webp';
		}
		return '';
	}

	/**
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private function upload_error( $message, $status ) {
		return new WP_Error( 'abm_upload', $message, array( 'status' => $status ) );
	}

	/* --------------------------------------------------------------------- */
	/* Bulk                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Read a booking list into rows. Writes nothing.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_parse( $request ) {
		$text = (string) $request->get_param( 'text' );
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'abm_invalid_text', __( 'text is required.', 'arkon-bar-manager' ), array( 'status' => 400 ) );
		}

		$parsed = ABM_Bulk::instance()->parse_text( $text, (string) $request->get_param( 'today' ) );
		$flyers = ABM_Bulk::instance()->match_flyers( $parsed['rows'] );

		return rest_ensure_response(
			array(
				'rows'    => $flyers['rows'],
				'actions' => $parsed['actions'],
				'skipped' => $parsed['skipped'],
				'flyers'  => array(
					'matched'   => (int) $flyers['matched'],
					'ambiguous' => (int) $flyers['ambiguous'],
				),
			)
		);
	}

	/**
	 * Pair rows with flyers already in the Media Library, by date.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_match( $request ) {
		$rows = $this->bulk_rows( $request );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		return rest_ensure_response( ABM_Bulk::instance()->match_flyers( $rows ) );
	}

	/**
	 * Create events from rows.
	 *
	 * Each row goes through the same rule as a single create, so a duplicate is
	 * refused and a look-alike is held, row by row. A row the parser had to
	 * guess at is created as a draft whatever status was asked for.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_create( $request ) {
		$rows = $this->bulk_rows( $request );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$status = (string) ( $request->get_param( 'status' ) ?? 'draft' );
		if ( ! in_array( $status, array( 'draft', 'publish' ), true ) ) {
			return new WP_Error( 'abm_invalid_status', __( 'status must be draft or publish.', 'arkon-bar-manager' ), array( 'status' => 400 ) );
		}

		$dry     = $this->is_dry( $request );
		$results = array();
		$counts  = array(
			'applied'           => 0,
			'approval_required' => 0,
			'refused'           => 0,
			'failed'            => 0,
		);

		foreach ( $rows as $i => $row ) {
			$unsure = false;
			foreach ( (array) $row['notes'] as $note ) {
				foreach ( self::UNSURE_NOTES as $marker ) {
					if ( false !== stripos( (string) $note, $marker ) ) {
						$unsure = true;
					}
				}
			}

			$params = array(
				'title'       => $row['title'],
				'date'        => $row['date'],
				'start_time'  => $row['start'],
				'end_time'    => $row['end'],
				'description' => $row['description'],
				'status'      => $unsure ? 'draft' : $status,
			);
			if ( '' !== $row['cost'] ) {
				$params['cover'] = abm_cost_is_none( $row['cost'] )
					? array( 'mode' => 'none' )
					: array(
						'mode'  => 'amount',
						'value' => $row['cost'],
					);
			}
			if ( $row['category'] ) {
				$params['categories'] = array( (int) $row['category'] );
			}
			if ( $row['flyer'] ) {
				$params['media_id'] = (int) $row['flyer'];
			}

			$entry = array(
				'index'        => (int) $i,
				'title'        => $row['title'],
				'date'         => $row['date'],
				'forced_draft' => $unsure && 'draft' !== $status,
			);

			$result = ABM_Agent_Ops::run( 'create', $params, array( 'dry_run' => $dry ) );
			if ( is_wp_error( $result ) ) {
				++$counts['failed'];
				$entry['outcome'] = 'failed';
				$entry['reasons'] = array( $result->get_error_message() );
			} else {
				++$counts[ $result['outcome'] ];
				$entry['outcome']      = $result['outcome'];
				$entry['reasons']      = $result['reasons'];
				$entry['id']           = $result['event'] ? (int) $result['event']['id'] : 0;
				$entry['status']       = $result['event'] ? $result['event']['status'] : '';
				$entry['change_id']    = (int) $result['change_id'];
				$entry['duplicate_of'] = (int) $result['duplicate_of'];
			}
			$results[] = $entry;
		}

		return rest_ensure_response(
			array(
				'dry_run' => $dry,
				'counts'  => $counts,
				'rows'    => $results,
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error Normalized rows.
	 */
	private function bulk_rows( $request ) {
		$raw = $request->get_param( 'rows' );
		if ( ! is_array( $raw ) || ! $raw ) {
			return new WP_Error( 'abm_invalid_rows', __( 'rows is required: a list of row objects as returned by bulk/parse.', 'arkon-bar-manager' ), array( 'status' => 400 ) );
		}
		if ( count( $raw ) > self::MAX_BULK ) {
			/* translators: %d: maximum rows. */
			return new WP_Error( 'abm_invalid_rows', sprintf( __( 'Send at most %d rows at a time.', 'arkon-bar-manager' ), self::MAX_BULK ), array( 'status' => 400 ) );
		}

		$rows = array();
		foreach ( array_values( $raw ) as $item ) {
			if ( ! is_array( $item ) ) {
				return new WP_Error( 'abm_invalid_rows', __( 'Each row must be an object.', 'arkon-bar-manager' ), array( 'status' => 400 ) );
			}
			$rows[] = ABM_Bulk::instance()->normalize( $item );
		}
		return $rows;
	}

	/* --------------------------------------------------------------------- */
	/* Preview                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * A short-lived link to an unpublished event's real page.
	 *
	 * A draft cannot be looked at without logging in to the admin, which an
	 * agent account cannot do. The link carries a signed token naming one event
	 * and an expiry; it shows that event and nothing else.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview_link( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || ABM_POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'abm_not_found', __( 'No event with that ID.', 'arkon-bar-manager' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'abm_forbidden', __( 'You are not allowed to do that.', 'arkon-bar-manager' ), array( 'status' => 403 ) );
		}

		if ( 'publish' === $post->post_status ) {
			return rest_ensure_response(
				array(
					'preview_url' => (string) get_permalink( $post ),
					'expires'     => 0,
				)
			);
		}
		if ( ! in_array( $post->post_status, array( 'draft', 'pending', 'future' ), true ) ) {
			return new WP_Error( 'abm_no_preview', __( 'Only draft, pending and scheduled events can be previewed.', 'arkon-bar-manager' ), array( 'status' => 409 ) );
		}

		$expires = time() + self::PREVIEW_TTL;

		return rest_ensure_response(
			array(
				'preview_url' => add_query_arg(
					array(
						'post_type'   => ABM_POST_TYPE,
						'p'           => (int) $post->ID,
						'abm_preview' => (int) $post->ID,
						'abm_token'   => self::preview_token( (int) $post->ID, $expires ),
					),
					home_url( '/' )
				),
				'expires'     => $expires,
			)
		);
	}

	/**
	 * @param int $post_id Event ID.
	 * @param int $expires Unix time.
	 * @return string "<expires>.<hmac>"
	 */
	public static function preview_token( $post_id, $expires ) {
		return (int) $expires . '.' . hash_hmac( 'sha256', (int) $post_id . '|' . (int) $expires, self::preview_key() );
	}

	/**
	 * @param int    $post_id Event ID.
	 * @param string $token   Token from the link.
	 * @return bool
	 */
	public static function preview_token_valid( $post_id, $token ) {
		$parts = explode( '.', (string) $token, 2 );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || (int) $parts[0] < time() ) {
			return false;
		}
		return hash_equals( self::preview_token( $post_id, (int) $parts[0] ), (string) $token );
	}

	/** @return string */
	private static function preview_key() {
		$key = (string) get_option( 'abm_agent_preview_key', '' );
		if ( strlen( $key ) < 64 ) {
			$key = wp_generate_password( 64, false, false );
			update_option( 'abm_agent_preview_key', $key, false );
		}
		return $key;
	}

	/**
	 * Serve a preview link.
	 *
	 * WordPress empties the result of a single-post query when the post is not
	 * public and nobody is logged in. For a request carrying a valid token for
	 * exactly this event, the one post is presented as viewable for the length
	 * of the request. Nothing is saved.
	 *
	 * @param WP $wp Request.
	 */
	public function maybe_preview( $wp ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the signed token is the credential.
		if ( empty( $_GET['abm_preview'] ) || empty( $_GET['abm_token'] ) ) {
			return;
		}
		$post_id = absint( wp_unslash( $_GET['abm_preview'] ) );
		$token   = sanitize_text_field( wp_unslash( $_GET['abm_token'] ) );
		// phpcs:enable

		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || ABM_POST_TYPE !== $post->post_type
			|| ! in_array( $post->post_status, array( 'draft', 'pending', 'future' ), true )
			|| ! self::preview_token_valid( $post_id, $token ) ) {
			return;
		}

		$wp->query_vars = array(
			'post_type' => ABM_POST_TYPE,
			'p'         => $post_id,
		);

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		remove_action( 'template_redirect', 'redirect_canonical' );

		$real = (string) $post->post_status;

		add_filter(
			'posts_results',
			static function ( $posts, $query ) use ( $post_id ) {
				if ( $query->is_main_query() && 1 === count( $posts ) && (int) $posts[0]->ID === $post_id ) {
					$posts[0]->post_status = 'publish';
				}
				return $posts;
			},
			10,
			2
		);

		// Put the real status back once the visibility check has passed and
		// before WordPress caches the row. Leaving it would store a draft in the
		// object cache as published, which on a persistent cache outlives this
		// request.
		add_filter(
			'the_posts',
			static function ( $posts, $query ) use ( $post_id, $real ) {
				if ( $query->is_main_query() && 1 === count( $posts ) && (int) $posts[0]->ID === $post_id ) {
					$posts[0]->post_status = $real;
				}
				return $posts;
			},
			10,
			2
		);
	}
}
