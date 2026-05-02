<?php
namespace Social_Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {

	const OPTION = 'social_publisher_log';
	const MAX    = 200;

	public static function log( string $level, string $message, array $context = [] ): void {
		$entries   = get_option( self::OPTION, [] );
		$entries   = is_array( $entries ) ? $entries : [];
		$entries[] = [
			'time'    => time(),
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		];
		if ( count( $entries ) > self::MAX ) {
			$entries = array_slice( $entries, -self::MAX );
		}
		update_option( self::OPTION, $entries, false );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[social-publisher][%s] %s %s', $level, $message, $context ? wp_json_encode( $context ) : '' ) );
		}
	}

	public static function info( string $message, array $context = [] ): void { self::log( 'info', $message, $context ); }
	public static function warn( string $message, array $context = [] ): void { self::log( 'warn', $message, $context ); }
	public static function error( string $message, array $context = [] ): void { self::log( 'error', $message, $context ); }

	public static function recent( int $limit = 50 ): array {
		$entries = get_option( self::OPTION, [] );
		$entries = is_array( $entries ) ? $entries : [];
		return array_slice( $entries, -$limit );
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
