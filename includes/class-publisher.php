<?php
namespace Social_Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core orchestrator: schedules cron on publish, runs the per-post pipeline.
 *
 * Pipeline:
 *   1. Build post payload (title, excerpt, plain content, link, tags).
 *   2. Optionally short.io shorten the permalink.
 *   3. Auto-discover Publer accounts in the workspace.
 *   4. Single OpenAI JSON call -> { facebook: ..., instagram: ..., ... }.
 *   5. For each connected account whose provider is enabled, create a Publer DRAFT.
 */
class Publisher {

	/** @var Settings */
	private $settings;

	/**
	 * Provider mapping. Publer's account.provider field roughly matches these keys.
	 * Filterable for installs with custom providers.
	 *
	 * @var array<string,string[]>
	 */
	private $provider_aliases = [
		'facebook'  => [ 'facebook', 'fb', 'facebook_page', 'facebook_group' ],
		'instagram' => [ 'instagram', 'ig' ],
		'threads'   => [ 'threads' ],
		'twitter'   => [ 'twitter', 'x', 'twitter_x' ],
		'linkedin'  => [ 'linkedin', 'linkedin_page', 'linkedin_profile' ],
		'tiktok'    => [ 'tiktok' ],
		'youtube'   => [ 'youtube' ],
	];

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'transition_post_status', [ $this, 'on_transition' ], 10, 3 );
		add_action( Plugin::CRON_HOOK, [ $this, 'run_for_post' ], 10, 1 );
	}

	/**
	 * Hook target: schedule a one-shot cron event when a post becomes 'publish'.
	 */
	public function on_transition( string $new_status, string $old_status, $post ): void {
		if ( 'publish' !== $new_status ) {
			return;
		}
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( ! $this->is_eligible( $post ) ) {
			return;
		}
		if ( get_post_meta( $post->ID, Plugin::META_SKIP, true ) ) {
			Logger::info( 'Skip flag set; not scheduling.', [ 'post_id' => $post->ID ] );
			return;
		}

		$delay     = (int) $this->settings->get( 'cron_delay', 60 );
		$delay     = max( 0, $delay );
		$timestamp = time() + $delay;

		$args = [ (int) $post->ID ];
		if ( ! wp_next_scheduled( Plugin::CRON_HOOK, $args ) ) {
			wp_schedule_single_event( $timestamp, Plugin::CRON_HOOK, $args );
			Logger::info( 'Scheduled run', [ 'post_id' => $post->ID, 'in_seconds' => $delay ] );
		}
	}

	/**
	 * @param int $post_id
	 * @return true|\WP_Error true on full success, or an error describing the failure.
	 */
	public function run_for_post( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			Logger::warn( 'Post not found', [ 'post_id' => $post_id ] );
			return new \WP_Error( 'social_publisher_no_post', __( 'Post not found.', 'social-publisher' ) );
		}

		if ( get_post_meta( $post->ID, Plugin::META_SKIP, true ) ) {
			Logger::info( 'Skip flag set at run time; aborting.', [ 'post_id' => $post->ID ] );
			return new \WP_Error( 'social_publisher_skipped', __( 'Skip flag set on post.', 'social-publisher' ) );
		}

		$publer  = new Publer_Client( $this->settings->get_secret( 'publer_api_key' ), $this->settings->get( 'publer_workspace_id', '' ) );
		$openai  = new OpenAI_Client( $this->settings->get_secret( 'openai_api_key' ), $this->settings->get( 'openai_model', 'gpt-4o-mini' ) );
		$shortio = new ShortIO_Client( $this->settings->get_secret( 'shortio_api_key' ), $this->settings->get( 'shortio_domain', '' ) );

		if ( ! $publer->is_configured() ) {
			$err = new \WP_Error( 'social_publisher_publer_unconfigured', __( 'Publer not configured.', 'social-publisher' ) );
			$this->record_result( $post->ID, [ 'ok' => false, 'error' => $err->get_error_message() ] );
			return $err;
		}
		if ( ! $openai->is_configured() ) {
			$err = new \WP_Error( 'social_publisher_openai_unconfigured', __( 'OpenAI not configured.', 'social-publisher' ) );
			$this->record_result( $post->ID, [ 'ok' => false, 'error' => $err->get_error_message() ] );
			return $err;
		}

		$accounts = $publer->get_accounts();
		if ( empty( $accounts ) ) {
			$err = new \WP_Error( 'social_publisher_no_accounts', __( 'No connected Publer accounts found.', 'social-publisher' ) );
			$this->record_result( $post->ID, [ 'ok' => false, 'error' => $err->get_error_message() ] );
			return $err;
		}

		$enabled_platforms = $this->settings->get_enabled_platforms();
		$account_groups    = $this->group_accounts_by_platform( $accounts, $enabled_platforms );
		$active_platforms  = array_keys( array_filter( $account_groups, static function ( $list ) { return ! empty( $list ); } ) );

		if ( empty( $active_platforms ) ) {
			$err = new \WP_Error( 'social_publisher_no_platforms', __( 'No enabled platforms with connected accounts.', 'social-publisher' ) );
			$this->record_result( $post->ID, [ 'ok' => false, 'error' => $err->get_error_message() ] );
			return $err;
		}

		$permalink = get_permalink( $post );
		$link_url  = $shortio->is_configured() ? $shortio->shorten( $permalink ) : $permalink;
		$payload   = $this->build_post_payload( $post, $link_url );

		$copy = $openai->generate_copy( $payload, $active_platforms );
		if ( is_wp_error( $copy ) ) {
			Logger::error( 'OpenAI failure', [ 'post_id' => $post->ID, 'error' => $copy->get_error_message() ] );
			$this->record_result( $post->ID, [ 'ok' => false, 'error' => $copy->get_error_message() ] );
			return $copy;
		}

		$drafts   = [];
		$failures = [];

		foreach ( $account_groups as $platform => $platform_accounts ) {
			if ( empty( $platform_accounts ) ) {
				continue;
			}
			$text = $copy[ $platform ] ?? '';
			if ( '' === $text ) {
				continue;
			}
			foreach ( $platform_accounts as $account ) {
				$account_id = (string) ( $account['id'] ?? $account['_id'] ?? '' );
				if ( '' === $account_id ) {
					continue;
				}
				$provider = (string) ( $account['provider'] ?? $account['network'] ?? $account['type'] ?? $platform );
				$result   = $publer->create_draft( $account_id, $provider, $text, $link_url );
				if ( is_wp_error( $result ) ) {
					$failures[] = [
						'platform' => $platform,
						'account'  => $account_id,
						'error'    => $result->get_error_message(),
					];
					Logger::warn( 'Publer draft failed', [ 'post_id' => $post->ID, 'platform' => $platform, 'error' => $result->get_error_message(), 'data' => $result->get_error_data() ] );
				} else {
					$drafts[] = [
						'platform' => $platform,
						'account'  => $account_id,
						'response' => $result,
					];
				}
			}
		}

		$summary = [
			'ok'        => empty( $failures ),
			'drafts'    => count( $drafts ),
			'failures'  => $failures,
			'platforms' => $active_platforms,
			'link'      => $link_url,
		];
		$this->record_result( $post->ID, $summary );
		Logger::info( 'Run complete', [ 'post_id' => $post->ID, 'drafts' => count( $drafts ), 'failed' => count( $failures ) ] );

		if ( ! empty( $failures ) ) {
			return new \WP_Error(
				'social_publisher_partial',
				sprintf( /* translators: %d count */ __( 'Some Publer drafts failed (%d).', 'social-publisher' ), count( $failures ) ),
				$summary
			);
		}
		return true;
	}

	private function record_result( int $post_id, array $summary ): void {
		update_post_meta( $post_id, Plugin::META_RESULT, $summary );
		update_post_meta( $post_id, Plugin::META_RUN_AT, time() );
	}

	private function is_eligible( \WP_Post $post ): bool {
		$types = $this->settings->get_enabled_post_types();
		if ( empty( $types ) ) {
			$types = $this->discover_post_types();
		}
		$eligible = in_array( $post->post_type, $types, true );
		return (bool) apply_filters( 'social_publisher_is_eligible', $eligible, $post );
	}

	/**
	 * @return string[] Public post type slugs (auto-discovery).
	 */
	public function discover_post_types(): array {
		$types = get_post_types( [ 'public' => true ], 'names' );
		unset( $types['attachment'] );
		$types = array_values( $types );
		return (array) apply_filters( 'social_publisher_post_types', $types );
	}

	/**
	 * @param array $accounts          Raw account list from Publer.
	 * @param array $enabled_platforms Platform keys enabled in settings.
	 * @return array<string,array<int,array>> platform => accounts[]
	 */
	private function group_accounts_by_platform( array $accounts, array $enabled_platforms ): array {
		$aliases = apply_filters( 'social_publisher_provider_aliases', $this->provider_aliases );
		$groups  = array_fill_keys( $enabled_platforms, [] );

		foreach ( $accounts as $account ) {
			$provider = strtolower( (string) ( $account['provider'] ?? $account['network'] ?? $account['type'] ?? '' ) );
			if ( '' === $provider ) {
				continue;
			}
			foreach ( $aliases as $platform => $candidates ) {
				if ( ! isset( $groups[ $platform ] ) ) {
					continue;
				}
				if ( in_array( $provider, $candidates, true ) ) {
					$groups[ $platform ][] = $account;
					break;
				}
			}
		}
		return $groups;
	}

	private function build_post_payload( \WP_Post $post, string $link ): array {
		$plain = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$plain = preg_replace( '/\s+/', ' ', $plain );
		$plain = mb_substr( trim( $plain ), 0, 4000 );

		$tags = wp_get_post_tags( $post->ID, [ 'fields' => 'names' ] );
		$cats = wp_get_post_categories( $post->ID, [ 'fields' => 'names' ] );

		return [
			'title'      => get_the_title( $post ),
			'excerpt'    => has_excerpt( $post ) ? wp_strip_all_tags( get_the_excerpt( $post ) ) : '',
			'content'    => $plain,
			'permalink'  => $link,
			'tags'       => is_array( $tags ) ? $tags : [],
			'categories' => is_array( $cats ) ? $cats : [],
			'site_name'  => get_bloginfo( 'name' ),
		];
	}

}
