<?php
namespace Social_Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional Short.io shortlink client. Falls back gracefully on any error.
 */
class ShortIO_Client {

	const ENDPOINT = 'https://api.short.io/links';

	/** @var string */
	private $api_key;

	/** @var string */
	private $domain;

	public function __construct( string $api_key, string $domain ) {
		$this->api_key = $api_key;
		$this->domain  = $domain;
	}

	public function is_configured(): bool {
		return '' !== $this->api_key && '' !== $this->domain;
	}

	/**
	 * @return string Shortened URL on success, or the original URL on any failure.
	 */
	public function shorten( string $url ): string {
		if ( '' === $url || ! $this->is_configured() ) {
			return $url;
		}

		$cache_key = 'social_publisher_shortio_' . md5( $this->domain . '|' . $url );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post( self::ENDPOINT, [
			'timeout' => 15,
			'headers' => [
				'Authorization' => $this->api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
			'body'    => wp_json_encode( [
				'originalURL' => $url,
				'domain'      => $this->domain,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			Logger::warn( 'Short.io request failed', [ 'error' => $response->get_error_message() ] );
			return $url;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && ! empty( $data['shortURL'] ) ) {
			set_transient( $cache_key, $data['shortURL'], DAY_IN_SECONDS );
			return $data['shortURL'];
		}

		Logger::warn( 'Short.io returned non-success', [ 'code' => $code ] );
		return $url;
	}
}
