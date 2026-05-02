<?php
namespace Social_Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	const CRON_HOOK   = 'social_publisher_run';
	const META_SKIP   = '_social_publisher_skip';
	const META_RESULT = '_social_publisher_last_result';
	const META_RUN_AT = '_social_publisher_last_run';

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Settings */
	public $settings;

	/** @var Publisher */
	public $publisher;

	/** @var Meta_Box */
	public $meta_box;

	/** @var REST */
	public $rest;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		load_plugin_textdomain( 'social-publisher', false, dirname( SOCIAL_PUBLISHER_BASENAME ) . '/languages' );

		$this->settings  = new Settings();
		$this->publisher = new Publisher( $this->settings );
		$this->meta_box  = new Meta_Box( $this->settings );
		$this->rest      = new REST( $this->publisher );

		$this->settings->register();
		$this->publisher->register();
		$this->meta_box->register();
		$this->rest->register();

		add_filter( 'plugin_action_links_' . SOCIAL_PUBLISHER_BASENAME, [ $this, 'plugin_action_links' ] );
	}

	public function plugin_action_links( array $links ): array {
		$url = admin_url( 'options-general.php?page=social-publisher' );
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Settings', 'social-publisher' ) ) );
		return $links;
	}

	public static function on_activate(): void {
		if ( ! get_option( 'social_publisher_settings' ) ) {
			add_option( 'social_publisher_settings', Settings::defaults() );
		}
	}

	public static function on_deactivate(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}
}
