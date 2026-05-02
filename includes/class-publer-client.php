<?php
namespace Social_Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin Publer API client.
 *
 * Verified against https://publer.com/docs (May 2026):
 *   Host  : https://app.publer.com
 *   Auth  : Authorization: Bearer-API <api_key>
 *   WS    : Publer-Workspace-Id: <workspace_id>   (sent on every call)
 *
 * Endpoints used:
 *   GET  /api/v1/accounts          (cached 1h on success only)
 *   POST /api/v1/posts/schedule    (creates a post; bulk.state=draft for drafts)
 *
 * Endpoint paths and request bodies are filterable so the plugin can adapt
 * to API revisions without a code change.
 */
class Publer_Client {

	const BASE       = 'https://app.publer.com/api';
	const ACCT_CACHE = 'social_publisher_accounts_'; // suffixed by workspace id hash
	const ACCT_TTL   = HOUR_IN_SECONDS;

	/** @var string */
	private $api_key;

	/** @var string */
	private $workspace_id;

	/** @var \WP_Error|null Last fetch error, exposed so callers (settings UI) can surface it. */
	private $last_error = null;

	public function __construct( string $api_key, string $workspace_id ) {
		$this->api_key      = trim( $api_key );
		$this->workspace_id = trim( $workspace_id );
	}

	public function is_configured(): bool {
		return '' !== $this->api_key && '' !== $this->workspace_id;
	}

	public function get_last_error(): ?\WP_Error {
		return $this->last_error;
	}

	/**
	 * Fetch all connected accounts in the configured workspace.
	 * Successful, non-empty results are cached for 1 hour.
	 * Errors are NOT cached — fix-and-retry must work without waiting for TTL.
	 *
	 * @param bool $force Bypass cache.
	 * @return array<int, array<string,mixed>>
	 */
	public function get_accounts( bool $force = false ): array {
		$this->last_error = null;

		if ( ! $this->is_configured() ) {
			$this->last_error = new \WP_Error( 'social_publisher_publer_unconfigured', __( 'Publer API key or workspace id missing.', 'social-publisher' ) );
			return [];
		}

		$cache_key = self::ACCT_CACHE . md5( $this->workspace_id );
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		$path = apply_filters( 'social_publisher_publer_accounts_path', '/v1/accounts', $this->workspace_id );

		$resp = $this->request( 'GET', $path );
		if ( is_wp_error( $resp ) ) {
			$this->last_error = $resp;
			Logger::warn( 'Publer accounts fetch failed', [
				'error' => $resp->get_error_message(),
				'data'  => $resp->get_error_data(),
			] );
			return [];
		}

		$accounts = $this->extract_accounts( $resp );
		$accounts = array_values( array_filter( $accounts, static function ( $a ) {
			$status = strtolower( (string) ( $a['status'] ?? 'active' ) );
			return '' === $status || 'active' === $status || 'connected' === $status;
		} ) );

		if ( ! empty( $accounts ) ) {
			set_transient( $cache_key, $accounts, self::ACCT_TTL );
		}

		return $accounts;
	}

	public function flush_account_cache(): void {
		delete_transient( self::ACCT_CACHE . md5( $this->workspace_id ) );
	}

	/**
	 * Create a Publer DRAFT post (bulk.state = "draft") on a single account.
	 *
	 * @param string $account_id Publer account id.
	 * @param string $provider   Network key ("facebook", "instagram", "twitter", "linkedin", "threads", "tiktok", "youtube").
	 * @param string $text       Post copy.
	 * @param string $link       URL to inline; if non-empty and not already in $text, it will be appended.
	 * @return array|\WP_Error
	 */
	public function create_draft( string $account_id, string $provider, string $text, string $link = '' ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'social_publisher_publer_unconfigured', __( 'Publer API key or workspace id missing.', 'social-publisher' ) );
		}

		if ( '' !== $link && false === strpos( $text, $link ) ) {
			$text = trim( $text ) . ' ' . $link;
		}

		$network_key = $this->normalize_network_key( $provider );

		$post = [
			'networks' => [
				$network_key => [
					'type' => 'status',
					'text' => $text,
				],
			],
			'accounts' => [
				[ 'id' => $account_id ],
			],
		];

		$body = [
			'bulk' => [
				'state' => 'draft',
				'posts' => [ $post ],
			],
		];

		$body = apply_filters( 'social_publisher_publer_post_body', $body, $account_id, $provider, $text, $link );
		$path = apply_filters( 'social_publisher_publer_create_path', '/v1/posts/schedule' );

		$resp = $this->request( 'POST', $path, $body );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		return $resp;
	}

	/**
	 * Find the array of accounts in a Publer response that may be:
	 *   - { "accounts": [...] }            (current docs)
	 *   - { "data": [...] }                (paginated variant)
	 *   - [ ... ]                          (bare list)
	 */
	private function extract_accounts( array $resp ): array {
		if ( isset( $resp['accounts'] ) && is_array( $resp['accounts'] ) ) {
			return $resp['accounts'];
		}
		if ( isset( $resp['data'] ) && is_array( $resp['data'] ) ) {
			return $resp['data'];
		}
		if ( array_keys( $resp ) === range( 0, count( $resp ) - 1 ) ) {
			return $resp;
		}
		return [];
	}

	private function normalize_network_key( string $provider ): string {
		$provider = strtolower( trim( $provider ) );
		$map      = [
			'fb'                => 'facebook',
			'facebook_page'     => 'facebook',
			'facebook_group'    => 'facebook',
			'ig'                => 'instagram',
			'x'                 => 'twitter',
			'twitter_x'         => 'twitter',
			'linkedin_page'     => 'linkedin',
			'linkedin_profile'  => 'linkedin',
		];
		$key = $map[ $provider ] ?? $provider;
		return (string) apply_filters( 'social_publisher_network_key', $key, $provider );
	}

	/**
	 * @return array|\WP_Error decoded JSON or error
	 */
	private function request( string $method, string $path, ?array $body = null ) {
		$url  = self::BASE . $path;
		$args = [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'Authorization'       => 'Bearer-API ' . $this->api_key,
				'Publer-Workspace-Id' => $this->workspace_id,
				'Accept'              => 'application/json',
				'User-Agent'          => 'SocialPublisher/' . SOCIAL_PUBLISHER_VERSION . '; ' . home_url(),
			],
		];
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = $this->extract_error_message( $data, $raw );
			return new \WP_Error(
				'social_publisher_publer_http_' . $code,
				sprintf(
					/* translators: 1: HTTP status, 2: server message */
					__( 'Publer API error (HTTP %1$d): %2$s', 'social-publisher' ),
					$code,
					$message
				),
				[
					'status' => $code,
					'url'    => $url,
					'body'   => mb_substr( (string) $raw, 0, 500 ),
				]
			);
		}

		return is_array( $data ) ? $data : [];
	}

	private function extract_error_message( $data, string $raw ): string {
		if ( is_array( $data ) ) {
			foreach ( [ 'message', 'error', 'error_description' ] as $k ) {
				if ( ! empty( $data[ $k ] ) && is_string( $data[ $k ] ) ) {
					return $data[ $k ];
				}
			}
			if ( ! empty( $data['errors'] ) ) {
				return is_string( $data['errors'] ) ? $data['errors'] : wp_json_encode( $data['errors'] );
			}
		}
		$snippet = trim( mb_substr( (string) $raw, 0, 200 ) );
		return $snippet ?: __( 'no response body', 'social-publisher' );
	}
}
