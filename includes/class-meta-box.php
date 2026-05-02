<?php
namespace Social_Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-post UI: classic meta box (skip flag) + Gutenberg sidebar panel
 * (skip toggle + manual "Generate now" for any post, including past ones).
 */
class Meta_Box {

	/** @var Settings */
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'init', [ $this, 'register_meta' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_classic_meta_box' ] );
		add_action( 'save_post', [ $this, 'save_classic_meta_box' ], 10, 2 );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_sidebar_assets' ] );
	}

	/**
	 * Expose the skip flag as a registered post meta so the Gutenberg panel
	 * can read/write it via the standard core/editor data store.
	 */
	public function register_meta(): void {
		foreach ( $this->settings->get_enabled_post_types() as $type ) {
			register_post_meta( $type, Plugin::META_SKIP, [
				'type'              => 'boolean',
				'single'            => true,
				'show_in_rest'      => true,
				'auth_callback'     => static function () {
					return current_user_can( 'edit_posts' );
				},
				'sanitize_callback' => static function ( $v ) { return $v ? 1 : 0; },
				'default'           => false,
			] );
		}
	}

	public function add_classic_meta_box(): void {
		foreach ( $this->settings->get_enabled_post_types() as $type ) {
			add_meta_box(
				'social_publisher_box',
				__( 'Social Publisher', 'social-publisher' ),
				[ $this, 'render_classic_meta_box' ],
				$type,
				'side',
				'default'
			);
		}
	}

	public function render_classic_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'social_publisher_box', 'social_publisher_box_nonce' );
		$skip   = (bool) get_post_meta( $post->ID, Plugin::META_SKIP, true );
		$result = get_post_meta( $post->ID, Plugin::META_RESULT, true );
		$run_at = (int) get_post_meta( $post->ID, Plugin::META_RUN_AT, true );
		?>
		<p>
			<label>
				<input type="checkbox" name="social_publisher_skip" value="1" <?php checked( $skip ); ?> />
				<?php esc_html_e( 'Skip social publishing for this post', 'social-publisher' ); ?>
			</label>
		</p>
		<?php if ( $run_at ) : ?>
			<p style="margin:0;"><strong><?php esc_html_e( 'Last run:', 'social-publisher' ); ?></strong>
				<?php echo esc_html( wp_date( 'Y-m-d H:i:s', $run_at ) ); ?>
			</p>
		<?php endif; ?>
		<?php if ( is_array( $result ) ) : ?>
			<p style="margin:0;">
				<strong><?php esc_html_e( 'Status:', 'social-publisher' ); ?></strong>
				<?php echo ! empty( $result['ok'] ) ? esc_html__( 'OK', 'social-publisher' ) : esc_html__( 'Error', 'social-publisher' ); ?>
				<?php if ( isset( $result['drafts'] ) ) : ?>
					&mdash; <?php echo esc_html( sprintf( /* translators: %d count */ _n( '%d draft', '%d drafts', (int) $result['drafts'], 'social-publisher' ), (int) $result['drafts'] ) ); ?>
				<?php endif; ?>
			</p>
			<?php if ( ! empty( $result['error'] ) ) : ?>
				<p style="margin:.4em 0 0;color:#b32d2e;"><?php echo esc_html( (string) $result['error'] ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
		<p>
			<em><?php esc_html_e( 'Use the sidebar panel in the block editor to generate drafts on demand for any post.', 'social-publisher' ); ?></em>
		</p>
		<?php
	}

	public function save_classic_meta_box( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['social_publisher_box_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['social_publisher_box_nonce'] ) ), 'social_publisher_box' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! empty( $_POST['social_publisher_skip'] ) ) {
			update_post_meta( $post_id, Plugin::META_SKIP, 1 );
		} else {
			delete_post_meta( $post_id, Plugin::META_SKIP );
		}
	}

	public function enqueue_sidebar_assets(): void {
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( ! in_array( $post->post_type, $this->settings->get_enabled_post_types(), true ) ) {
			return;
		}

		$handle = 'social-publisher-sidebar';
		$src    = SOCIAL_PUBLISHER_URL . 'assets/sidebar.js';
		$path   = SOCIAL_PUBLISHER_DIR . 'assets/sidebar.js';
		$ver    = file_exists( $path ) ? (string) filemtime( $path ) : SOCIAL_PUBLISHER_VERSION;

		wp_enqueue_script(
			$handle,
			$src,
			[
				'wp-plugins',
				'wp-edit-post',
				'wp-element',
				'wp-components',
				'wp-data',
				'wp-i18n',
				'wp-api-fetch',
				'wp-core-data',
			],
			$ver,
			true
		);
		wp_set_script_translations( $handle, 'social-publisher' );
	}
}
