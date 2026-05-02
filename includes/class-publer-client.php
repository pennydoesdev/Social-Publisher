<?php
namespace Social_Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin Publer API client.
 *
 * Auth: `Authorization: Bearer-API <api_key>` and `Publer-Workspace-Id: <workspace_id>`.
 * Endpoints used:
 *   GET  /v1/workspaces/{workspace_id}/accounts (cached 1h)
 *   POST /v1/posts/schedule/publish              (creates drafts; state=draft)
 *
 * Both endpoints and request bodies can be filtered via the
 * `social_publisher_publer_*` filters.
 */
class Publer_Client {

	const BASE        = 'https://app.publer.io/api';
	const ACCT_CACHE  = 'social_publisher_accounts_'; // suffixed by workspace
	const ACCT_TTL    = HOUR_IN_SECONDS;

	/** @var string */
	private $api_key;

	/** @var string */
	private $workspace_id;

	public function __construct( string $api_key, string $workspace_id ) {
		$this->api_key      = $api_key;
		$this->workspace_id = $workspace_id;
	}

	public function is_configured(): bool {
		return '' !== $this->api_key && '' !== $this->workspace_id;
	}

	/**
	 * @param bool $force Bypass cache.
	 * @return array<int, array<string,mixed>> Accounts list.
	 */
	public function get_accounts( bool $force = false ): array {
		if ( ! $this->is_configured() ) {
			return [];
		}

		$cache_key = self::ACCT_CACHE . md5( $this->workspace_id );
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$path = sprintf( '/v1/workspaces/%s/accounts', rawurlencode( $this->workspace_id ) );
		$path = apply_filters( 'social_publisher_publer_accounts_path', $path, $this->workspace_id );

		$resp = $this->request( 'GET', $path );
		if ( is_wp_error( $resp ) ) {
			Logger::warn( 'Publer accounts fetch failed', [ 'error' => $resp->get_error_message() ] );
			return [];
		}

		$accounts = $resp;
		if ( isset( $resp['accounts'] ) && is_array( $resp['accounts'] ) ) {
			$accounts = $resp['accounts'];
		} elseif ( isset( $resp['data'] ) && is_array( $resp['data'] ) ) {
			$accounts = $resp['data'];
		}

		if ( ! is_array( $accounts ) ) {
			$accounts = [];
		}

		set_transient( $cache_key, $accounts, self::ACCT_TTL );
		return $accounts;
	}

	public function flush_account_cache(): void {
		delete_transient( self::ACCT_CACHE . md5( $this->workspace_id ) );
	}

	/**
	 * Create a draft post on a single Publer account.
	 *
	 * @param string $account_id Publer account/profile id.
	 * @param string $text       Post body.
	 * @param string $url        Link to attach (optional).
	 * @param array  $media      Media URLs (optional).
	 * @return array|\WP_Error
	 */
	public function create_draft( string $account_id, string $text, string $url = '', array $media = [] ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'social_publisher_publer_unconfigured', __( 'Publer API key or workspace id missing.', 'social-publisher' ) );
		}

		$post = [
			'networks' => [ $account_id ],
			'state'    => 'draft',
			'details'  => array_filter( [
				'text'  => $text,
				'url'   => $url,
				'media' => $media ? array_map( static function ( $m ) {
					return is_array( $m ) ? $m : [ 'url' => $m ];
				}, $media ) : null,
			], static function ( $v ) {
				return null !== $v && '' !== $v && [] !== $v;
			} ),
		];

		$body = [
			'posts' => [ $post ],
		];

		$body = apply_filters( 'social_publisher_publer_post_body', $body, $account_id, $text, $url, $media );
		$path = apply_filters( 'social_publisher_publer_create_path', '/v1/posts/schedule/publish' );

		$resp = $this->request( 'POST', $path, $body );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		return $resp;
	}

	/**
	 * @param string       $method
	 * @param string       $path
	 * @param array|null   $body
	 * @return array|\WP_Error decoded JSON or error
	 */
	private function request( string $method, string $path, ?array $body = null ) {
		$url = self::BASE . $path;
		$args = [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'Authorization'        => 'Bearer-API ' . $this->api_key,
				'Publer-Workspace-Id'  => $this->workspace_id,
				'Accept'               => 'application/json',
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

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'social_publisher_publer_http_' . $code,
				sprintf( /* translators: %d HTTP status */ __( 'Publer API error (%d).', 'social-publisher' ), $code ),
				[ 'status' => $code, 'body' => $raw ]
			);
		}

		return is_array( $data ) ? $data : [];
	}
}
