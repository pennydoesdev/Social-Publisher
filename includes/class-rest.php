<?php
namespace Social_Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes:
 *   POST  /wp-json/social-publisher/v1/regenerate/{post_id}
 *   GET   /wp-json/social-publisher/v1/status/{post_id}
 *   POST  /wp-json/social-publisher/v1/skip/{post_id}    body: { skip: bool }
 */
class REST {

	const NAMESPACE_ = 'social-publisher/v1';

	/** @var Publisher */
	private $publisher;

	public function __construct( Publisher $publisher ) {
		$this->publisher = $publisher;
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE_, '/regenerate/(?P<post_id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'regenerate' ],
			'permission_callback' => [ $this, 'can_edit_post' ],
			'args'                => [
				'post_id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		register_rest_route( self::NAMESPACE_, '/status/(?P<post_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'status' ],
			'permission_callback' => [ $this, 'can_edit_post' ],
			'args'                => [
				'post_id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		register_rest_route( self::NAMESPACE_, '/skip/(?P<post_id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'set_skip' ],
			'permission_callback' => [ $this, 'can_edit_post' ],
			'args'                => [
				'post_id' => [ 'type' => 'integer', 'required' => true ],
				'skip'    => [ 'type' => 'boolean', 'required' => true ],
			],
		] );
	}

	public function can_edit_post( \WP_REST_Request $request ): bool {
		$post_id = (int) $request['post_id'];
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	public function regenerate( \WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'not_found', __( 'Post not found.', 'social-publisher' ), [ 'status' => 404 ] );
		}

		$result = $this->publisher->run_for_post( $post_id );
		if ( is_wp_error( $result ) ) {
			$summary = get_post_meta( $post_id, Plugin::META_RESULT, true );
			return new \WP_REST_Response( [
				'ok'      => false,
				'error'   => $result->get_error_message(),
				'code'    => $result->get_error_code(),
				'summary' => is_array( $summary ) ? $summary : null,
			], 200 );
		}

		$summary = get_post_meta( $post_id, Plugin::META_RESULT, true );
		return new \WP_REST_Response( [
			'ok'      => true,
			'summary' => is_array( $summary ) ? $summary : null,
		], 200 );
	}

	public function status( \WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$summary = get_post_meta( $post_id, Plugin::META_RESULT, true );
		$run_at  = get_post_meta( $post_id, Plugin::META_RUN_AT, true );
		$skip    = (bool) get_post_meta( $post_id, Plugin::META_SKIP, true );

		return new \WP_REST_Response( [
			'post_id' => $post_id,
			'skip'    => $skip,
			'run_at'  => $run_at ? (int) $run_at : null,
			'summary' => is_array( $summary ) ? $summary : null,
		], 200 );
	}

	public function set_skip( \WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$skip    = (bool) $request->get_param( 'skip' );
		if ( $skip ) {
			update_post_meta( $post_id, Plugin::META_SKIP, 1 );
		} else {
			delete_post_meta( $post_id, Plugin::META_SKIP );
		}
		return new \WP_REST_Response( [ 'post_id' => $post_id, 'skip' => $skip ], 200 );
	}
}
