<?php
namespace Social_Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page (Settings -> Social Publisher) and accessor for stored config.
 *
 * Secrets can be hard-locked via wp-config constants:
 *   - SOCIAL_PUBLISHER_OPENAI_KEY
 *   - SOCIAL_PUBLISHER_PUBLER_KEY
 *   - SOCIAL_PUBLISHER_SHORTIO_KEY
 *
 * When a constant is defined, the corresponding settings field is read-only
 * and the constant value is always used at runtime.
 */
class Settings {

	const OPTION = 'social_publisher_settings';

	const PLATFORMS = [
		'facebook'  => 'Facebook',
		'instagram' => 'Instagram',
		'threads'   => 'Threads',
		'twitter'   => 'X / Twitter',
		'linkedin'  => 'LinkedIn',
		'tiktok'    => 'TikTok',
		'youtube'   => 'YouTube',
	];

	const SECRET_CONSTANTS = [
		'openai_api_key'  => 'SOCIAL_PUBLISHER_OPENAI_KEY',
		'publer_api_key'  => 'SOCIAL_PUBLISHER_PUBLER_KEY',
		'shortio_api_key' => 'SOCIAL_PUBLISHER_SHORTIO_KEY',
	];

	public static function defaults(): array {
		return [
			'openai_api_key'        => '',
			'openai_model'          => 'gpt-4o-mini',
			'publer_api_key'        => '',
			'publer_workspace_id'   => '',
			'shortio_api_key'       => '',
			'shortio_domain'        => '',
			'cron_delay'            => 60,
			'enabled_platforms'     => array_keys( self::PLATFORMS ),
			'enabled_post_types'    => [], // empty = all auto-discovered public types
		];
	}

	public function register(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_social_publisher_flush_cache', [ $this, 'handle_flush_cache' ] );
	}

	public function register_settings(): void {
		register_setting( 'social_publisher', self::OPTION, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize' ],
			'default'           => self::defaults(),
		] );
	}

	public function register_menu(): void {
		add_options_page(
			__( 'Social Publisher', 'social-publisher' ),
			__( 'Social Publisher', 'social-publisher' ),
			'manage_options',
			'social-publisher',
			[ $this, 'render_page' ]
		);
	}

	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : [];
		$current  = get_option( self::OPTION, self::defaults() );
		$current  = is_array( $current ) ? $current : self::defaults();

		$out = self::defaults();

		// Secrets: ignore inbound value if a constant is defined; otherwise sanitize.
		foreach ( self::SECRET_CONSTANTS as $key => $constant ) {
			if ( defined( $constant ) ) {
				$out[ $key ] = ''; // do not store; constant overrides at runtime
			} else {
				$out[ $key ] = isset( $input[ $key ] ) ? trim( (string) sanitize_text_field( $input[ $key ] ) ) : ( $current[ $key ] ?? '' );
			}
		}

		$out['openai_model']        = isset( $input['openai_model'] ) ? sanitize_text_field( (string) $input['openai_model'] ) : 'gpt-4o-mini';
		$out['publer_workspace_id'] = isset( $input['publer_workspace_id'] ) ? sanitize_text_field( (string) $input['publer_workspace_id'] ) : '';
		$out['shortio_domain']      = isset( $input['shortio_domain'] ) ? sanitize_text_field( (string) $input['shortio_domain'] ) : '';

		$delay = isset( $input['cron_delay'] ) ? (int) $input['cron_delay'] : 60;
		$out['cron_delay'] = max( 0, min( 3600, $delay ) );

		$valid_platforms = array_keys( self::PLATFORMS );
		$selected_pl     = isset( $input['enabled_platforms'] ) && is_array( $input['enabled_platforms'] ) ? $input['enabled_platforms'] : [];
		$out['enabled_platforms'] = array_values( array_intersect( $valid_platforms, array_map( 'sanitize_key', $selected_pl ) ) );

		$selected_pt = isset( $input['enabled_post_types'] ) && is_array( $input['enabled_post_types'] ) ? $input['enabled_post_types'] : [];
		$out['enabled_post_types'] = array_values( array_unique( array_map( 'sanitize_key', $selected_pt ) ) );

		return $out;
	}

	/**
	 * Get a non-secret setting.
	 */
	public function get( string $key, $default = '' ) {
		$opts = get_option( self::OPTION, self::defaults() );
		$opts = is_array( $opts ) ? $opts : self::defaults();
		return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
	}

	/**
	 * Get a secret. Constants always win.
	 */
	public function get_secret( string $key ): string {
		$constant = self::SECRET_CONSTANTS[ $key ] ?? '';
		if ( $constant && defined( $constant ) ) {
			return (string) constant( $constant );
		}
		return (string) $this->get( $key, '' );
	}

	public function is_secret_locked( string $key ): bool {
		$constant = self::SECRET_CONSTANTS[ $key ] ?? '';
		return $constant && defined( $constant );
	}

	public function get_enabled_platforms(): array {
		$enabled = (array) $this->get( 'enabled_platforms', array_keys( self::PLATFORMS ) );
		$enabled = array_values( array_intersect( array_keys( self::PLATFORMS ), $enabled ) );
		return (array) apply_filters( 'social_publisher_enabled_platforms', $enabled );
	}

	public function get_enabled_post_types(): array {
		$enabled = (array) $this->get( 'enabled_post_types', [] );
		if ( empty( $enabled ) ) {
			$types = get_post_types( [ 'public' => true ], 'names' );
			unset( $types['attachment'] );
			$enabled = array_values( $types );
		}
		return (array) apply_filters( 'social_publisher_enabled_post_types', $enabled );
	}

	public function handle_flush_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'social-publisher' ) );
		}
		check_admin_referer( 'social_publisher_flush_cache' );

		$publer = new Publer_Client( $this->get_secret( 'publer_api_key' ), $this->get( 'publer_workspace_id', '' ) );
		$publer->flush_account_cache();

		wp_safe_redirect( add_query_arg( [ 'page' => 'social-publisher', 'flushed' => 1 ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$opts          = get_option( self::OPTION, self::defaults() );
		$opts          = is_array( $opts ) ? $opts : self::defaults();
		$post_types    = get_post_types( [ 'public' => true ], 'objects' );
		unset( $post_types['attachment'] );
		$selected_pt   = (array) ( $opts['enabled_post_types'] ?? [] );
		$selected_pl   = (array) ( $opts['enabled_platforms'] ?? array_keys( self::PLATFORMS ) );
		$flushed       = ! empty( $_GET['flushed'] );

		// Live preview: connected Publer accounts.
		$accounts      = [];
		$publer_error  = null;
		$publer        = new Publer_Client( $this->get_secret( 'publer_api_key' ), $opts['publer_workspace_id'] ?? '' );
		if ( $publer->is_configured() ) {
			$accounts     = $publer->get_accounts( true ); // force-refresh on settings page so users see live state
			$publer_error = $publer->get_last_error();
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Social Publisher', 'social-publisher' ); ?></h1>
			<?php if ( $flushed ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Publer account cache flushed.', 'social-publisher' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'social_publisher' ); ?>

				<h2><?php esc_html_e( 'OpenAI', 'social-publisher' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sp_openai_api_key"><?php esc_html_e( 'API Key', 'social-publisher' ); ?></label></th>
						<td><?php $this->render_secret_input( 'openai_api_key', $opts['openai_api_key'] ?? '' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="sp_openai_model"><?php esc_html_e( 'Model', 'social-publisher' ); ?></label></th>
						<td>
							<input type="text" id="sp_openai_model" name="<?php echo esc_attr( self::OPTION ); ?>[openai_model]" value="<?php echo esc_attr( $opts['openai_model'] ?? 'gpt-4o-mini' ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Any OpenAI chat model that supports JSON mode (e.g. gpt-4o-mini, gpt-4o).', 'social-publisher' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Publer', 'social-publisher' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sp_publer_api_key"><?php esc_html_e( 'API Key', 'social-publisher' ); ?></label></th>
						<td><?php $this->render_secret_input( 'publer_api_key', $opts['publer_api_key'] ?? '' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="sp_publer_workspace_id"><?php esc_html_e( 'Workspace ID', 'social-publisher' ); ?></label></th>
						<td>
							<input type="text" id="sp_publer_workspace_id" name="<?php echo esc_attr( self::OPTION ); ?>[publer_workspace_id]" value="<?php echo esc_attr( $opts['publer_workspace_id'] ?? '' ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Connected Accounts', 'social-publisher' ); ?></th>
						<td>
							<?php if ( ! $publer->is_configured() ) : ?>
								<p><em><?php esc_html_e( 'Add a Publer API key and Workspace ID, then save to load accounts.', 'social-publisher' ); ?></em></p>
							<?php elseif ( $publer_error instanceof \WP_Error ) :
								$data    = $publer_error->get_error_data();
								$status  = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
								$body    = is_array( $data ) ? (string) ( $data['body'] ?? '' ) : '';
								$req_url = is_array( $data ) ? (string) ( $data['url'] ?? '' ) : '';
								?>
								<div class="notice notice-error inline" style="margin:0 0 8px;">
									<p><strong><?php esc_html_e( 'Publer API error', 'social-publisher' ); ?></strong></p>
									<p><?php echo esc_html( $publer_error->get_error_message() ); ?></p>
									<?php if ( $req_url ) : ?>
										<p><code><?php echo esc_html( $req_url ); ?></code></p>
									<?php endif; ?>
									<?php if ( $body ) : ?>
										<details><summary><?php esc_html_e( 'Response body', 'social-publisher' ); ?></summary>
											<pre style="white-space:pre-wrap;word-break:break-all;"><?php echo esc_html( $body ); ?></pre>
										</details>
									<?php endif; ?>
									<?php if ( 401 === $status ) : ?>
										<p><?php esc_html_e( 'HTTP 401 → Check the API key. It must be a Publer-issued key, not your account password.', 'social-publisher' ); ?></p>
									<?php elseif ( 403 === $status ) : ?>
										<p><?php esc_html_e( 'HTTP 403 → The API key likely lacks access to this workspace. Confirm the Workspace ID and that the key was issued for it.', 'social-publisher' ); ?></p>
									<?php elseif ( 404 === $status ) : ?>
										<p><?php esc_html_e( 'HTTP 404 → Workspace ID not found, or the endpoint URL is wrong.', 'social-publisher' ); ?></p>
									<?php endif; ?>
								</div>
							<?php elseif ( empty( $accounts ) ) : ?>
								<p><em><?php esc_html_e( 'Authenticated successfully, but no active social accounts are connected to this workspace in Publer.', 'social-publisher' ); ?></em></p>
							<?php else : ?>
								<ul style="margin:0;">
									<?php foreach ( $accounts as $account ) :
										$name     = (string) ( $account['name'] ?? $account['username'] ?? $account['id'] ?? '' );
										$provider = (string) ( $account['provider'] ?? $account['network'] ?? '' );
										$type     = (string) ( $account['type'] ?? '' );
									?>
										<li>
											<strong><?php echo esc_html( $name ); ?></strong>
											&mdash; <code><?php echo esc_html( $provider ); ?><?php echo $type ? '/' . esc_html( $type ) : ''; ?></code>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<p>
								<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=social_publisher_flush_cache' ), 'social_publisher_flush_cache' ) ); ?>">
									<?php esc_html_e( 'Flush account cache', 'social-publisher' ); ?>
								</a>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Short.io (optional)', 'social-publisher' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sp_shortio_api_key"><?php esc_html_e( 'API Key', 'social-publisher' ); ?></label></th>
						<td><?php $this->render_secret_input( 'shortio_api_key', $opts['shortio_api_key'] ?? '' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="sp_shortio_domain"><?php esc_html_e( 'Domain', 'social-publisher' ); ?></label></th>
						<td>
							<input type="text" id="sp_shortio_domain" name="<?php echo esc_attr( self::OPTION ); ?>[shortio_domain]" value="<?php echo esc_attr( $opts['shortio_domain'] ?? '' ); ?>" class="regular-text" placeholder="links.example.com" />
							<p class="description"><?php esc_html_e( 'Leave blank to skip shortlinking and fall back to permalinks.', 'social-publisher' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Behavior', 'social-publisher' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sp_cron_delay"><?php esc_html_e( 'Run delay (seconds)', 'social-publisher' ); ?></label></th>
						<td>
							<input type="number" min="0" max="3600" id="sp_cron_delay" name="<?php echo esc_attr( self::OPTION ); ?>[cron_delay]" value="<?php echo esc_attr( (string) ( $opts['cron_delay'] ?? 60 ) ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Delay between publish and async run (default 60).', 'social-publisher' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Platforms', 'social-publisher' ); ?></th>
						<td>
							<?php foreach ( self::PLATFORMS as $key => $label ) : ?>
								<label style="display:inline-block;min-width:160px;">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enabled_platforms][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected_pl, true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Post types', 'social-publisher' ); ?></th>
						<td>
							<?php foreach ( $post_types as $pt ) : ?>
								<label style="display:inline-block;min-width:160px;">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enabled_post_types][]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $selected_pt, true ) ); ?> />
									<?php echo esc_html( $pt->labels->singular_name ?? $pt->name ); ?> <code><?php echo esc_html( $pt->name ); ?></code>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Leave all unchecked to auto-include every public post type.', 'social-publisher' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Recent activity', 'social-publisher' ); ?></h2>
			<?php $entries = Logger::recent( 30 ); ?>
			<?php if ( empty( $entries ) ) : ?>
				<p><em><?php esc_html_e( 'No activity yet.', 'social-publisher' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Time', 'social-publisher' ); ?></th><th><?php esc_html_e( 'Level', 'social-publisher' ); ?></th><th><?php esc_html_e( 'Message', 'social-publisher' ); ?></th><th><?php esc_html_e( 'Context', 'social-publisher' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( array_reverse( $entries ) as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) ( $entry['time'] ?? 0 ) ) ); ?></td>
								<td><?php echo esc_html( (string) ( $entry['level'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $entry['message'] ?? '' ) ); ?></td>
								<td><code><?php echo esc_html( wp_json_encode( $entry['context'] ?? [] ) ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_secret_input( string $key, string $value ): void {
		$locked      = $this->is_secret_locked( $key );
		$id          = 'sp_' . $key;
		$constant    = self::SECRET_CONSTANTS[ $key ] ?? '';
		$placeholder = $value ? str_repeat( '•', 12 ) : '';

		if ( $locked ) {
			printf(
				'<input type="text" id="%1$s" value="%2$s" class="regular-text" disabled /> <p class="description">%3$s <code>%4$s</code></p>',
				esc_attr( $id ),
				esc_attr( __( 'Locked by wp-config constant', 'social-publisher' ) ),
				esc_html__( 'Set in', 'social-publisher' ),
				esc_html( $constant )
			);
			return;
		}

		printf(
			'<input type="password" autocomplete="new-password" id="%1$s" name="%2$s[%3$s]" value="%4$s" class="regular-text" placeholder="%5$s" />',
			esc_attr( $id ),
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);
		printf(
			' <p class="description">%s <code>%s</code></p>',
			esc_html__( 'Or hard-lock via', 'social-publisher' ),
			esc_html( $constant )
		);
	}
}
