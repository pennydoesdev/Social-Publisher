<?php
namespace Social_Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI Chat Completions client. Single JSON-mode call returning per-platform copy.
 */
class OpenAI_Client {

	const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

	/** @var string */
	private $api_key;

	/** @var string */
	private $model;

	public function __construct( string $api_key, string $model = 'gpt-4o-mini' ) {
		$this->api_key = $api_key;
		$this->model   = $model ?: 'gpt-4o-mini';
	}

	public function is_configured(): bool {
		return '' !== $this->api_key;
	}

	/**
	 * Generate per-platform copy for a single post.
	 *
	 * @param array<string,string> $post_payload  Title, excerpt, content (plain), permalink, tags, categories.
	 * @param array<int,string>    $platforms     Active platform keys (facebook, instagram, threads, twitter, linkedin, tiktok, youtube).
	 * @return array<string,string>|\WP_Error      Map of platform => copy.
	 */
	public function generate_copy( array $post_payload, array $platforms ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'social_publisher_openai_unconfigured', __( 'OpenAI API key missing.', 'social-publisher' ) );
		}
		if ( empty( $platforms ) ) {
			return [];
		}

		$system = "You are a senior social media copywriter. Produce concise, on-brand drafts for each requested platform. " .
			"Match each platform's conventions (tone, length, hashtags, emoji usage). " .
			"Never invent facts. Stay faithful to the source post. " .
			"Return ONLY a JSON object whose top-level keys are the requested platforms, with string values containing the post copy. " .
			"No markdown fences, no commentary.";

		$limits = [
			'facebook'  => 'Up to 280 chars, friendly, 1-2 hashtags, end with a question or CTA.',
			'instagram' => 'Up to 2200 chars but aim for ~150 of hook + body, then 5-10 hashtags on a new line.',
			'threads'   => 'Up to 500 chars, conversational, 0-2 hashtags.',
			'twitter'   => 'Strictly under 270 chars (leave room for the link), punchy, 0-2 hashtags.',
			'linkedin'  => 'Up to 1300 chars, professional voice, value-first opening line, 0-3 hashtags at end.',
			'tiktok'    => 'Up to 150 chars, hook-driven, 3-5 trending-style hashtags.',
			'youtube'   => 'Treat as a Community post: up to 300 chars, casual, 0-2 hashtags.',
		];

		$lines = [];
		foreach ( $platforms as $p ) {
			$lines[] = '- ' . $p . ': ' . ( $limits[ $p ] ?? 'Concise, on-brand.' );
		}
		$instructions = "Platforms and constraints:\n" . implode( "\n", $lines );

		$user = wp_json_encode( [
			'instructions' => $instructions,
			'post'         => $post_payload,
			'platforms'    => $platforms,
		] );

		$body = [
			'model'           => $this->model,
			'temperature'     => 0.7,
			'response_format' => [ 'type' => 'json_object' ],
			'messages'        => [
				[ 'role' => 'system', 'content' => $system ],
				[ 'role' => 'user',   'content' => $user ],
			],
		];

		$body = apply_filters( 'social_publisher_openai_request', $body, $post_payload, $platforms );

		$response = wp_remote_post( self::ENDPOINT, [
			'timeout' => 45,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'social_publisher_openai_http_' . $code,
				sprintf( /* translators: %d HTTP status */ __( 'OpenAI API error (%d).', 'social-publisher' ), $code ),
				[ 'status' => $code, 'body' => $raw ]
			);
		}

		$data    = json_decode( $raw, true );
		$content = $data['choices'][0]['message']['content'] ?? '';
		if ( '' === $content ) {
			return new \WP_Error( 'social_publisher_openai_empty', __( 'OpenAI returned empty content.', 'social-publisher' ) );
		}

		$decoded = json_decode( $content, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'social_publisher_openai_parse', __( 'OpenAI response was not valid JSON.', 'social-publisher' ), [ 'content' => $content ] );
		}

		$out = [];
		foreach ( $platforms as $p ) {
			if ( isset( $decoded[ $p ] ) && is_string( $decoded[ $p ] ) ) {
				$out[ $p ] = trim( $decoded[ $p ] );
			}
		}
		return $out;
	}
}
