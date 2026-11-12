<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings with sensible defaults.
 */
class GDFF_Settings {

	public static function defaults() {
		$lt = 0 === strpos( (string) get_locale(), 'lt' );

		return array(
			'page_id'        => '100070843854408',
			'page_url'       => 'https://www.facebook.com/profile.php?id=100070843854408',
			'access_token'   => '',
			'api_version'    => 'v26.0',
			'mode'           => 'api',   // api | embed
			'layout'         => 'grid',  // grid | list
			'limit'          => 6,
			'columns'        => 3,
			'excerpt_length' => 250,
			'show_images'    => 1,
			'show_header'    => 1,
			'show_follow'    => 1,
			'new_tab'        => 1,
			'cache_minutes'  => 60,
			'accent_color'   => '#1877f2',
			'embed_height'   => 800,
			'read_more_text' => $lt ? 'Skaityti Facebook\'e' : 'View on Facebook',
			'follow_text'    => $lt ? 'Sekite mus Facebook\'e' : 'Follow us on Facebook',
		);
	}

	/**
	 * @param string|null $key Setting key, or null for all settings.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$saved = get_option( GDFF_OPTION, array() );
		$opts  = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );

		if ( null === $key ) {
			return $opts;
		}
		return isset( $opts[ $key ] ) ? $opts[ $key ] : null;
	}

	public static function update( array $values ) {
		$current = get_option( GDFF_OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		update_option( GDFF_OPTION, array_merge( $current, $values ) );
	}

	public static function is_connected() {
		return '' !== (string) self::get( 'page_id' ) && '' !== (string) self::get( 'access_token' );
	}
}
