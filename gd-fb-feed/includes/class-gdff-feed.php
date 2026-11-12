<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Caching + front-end rendering of the [fb_posts] shortcode.
 */
class GDFF_Feed {

	const CRON_HOOK = 'gdff_refresh_event';
	const LOCK      = 'gdff_refresh_lock';

	public static function init() {
		add_shortcode( 'fb_posts', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh' ) );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function register_assets() {
		wp_register_style( 'gdff', GDFF_URL . 'assets/feed.css', array(), GDFF_VERSION );
	}

	/* ------------------------------------------------------------------
	 * Data + caching
	 * ---------------------------------------------------------------- */

	/**
	 * Pull fresh data from Facebook and store it. Keeps the old copy on failure.
	 *
	 * @return array|WP_Error
	 */
	public static function refresh() {
		if ( get_transient( self::LOCK ) ) {
			$cached = get_option( GDFF_CACHE_OPTION );
			return is_array( $cached ) ? $cached : new WP_Error( 'gdff_locked', 'A refresh is already running.' );
		}
		set_transient( self::LOCK, 1, 60 );

		$data = GDFF_API::fetch_feed();

		if ( is_wp_error( $data ) ) {
			update_option(
				GDFF_ERROR_OPTION,
				array(
					'message' => $data->get_error_message(),
					'time'    => time(),
				),
				false
			);
		} else {
			update_option( GDFF_CACHE_OPTION, $data, false );
			delete_option( GDFF_ERROR_OPTION );
		}

		delete_transient( self::LOCK );
		return $data;
	}

	public static function clear_cache() {
		delete_option( GDFF_CACHE_OPTION );
		delete_option( GDFF_ERROR_OPTION );
		delete_transient( self::LOCK );
	}

	/**
	 * Cached data. Stale data is served immediately while a background refresh runs,
	 * so visitors never wait for Facebook.
	 *
	 * @return array|WP_Error
	 */
	public static function get_data() {
		$cached = get_option( GDFF_CACHE_OPTION );

		if ( ! is_array( $cached ) || empty( $cached['fetched'] ) ) {
			return self::refresh();
		}

		$max_age = max( 5, (int) GDFF_Settings::get( 'cache_minutes' ) ) * MINUTE_IN_SECONDS;
		if ( time() - (int) $cached['fetched'] > $max_age && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}

		return $cached;
	}

	/* ------------------------------------------------------------------
	 * Shortcode
	 * ---------------------------------------------------------------- */

	public static function shortcode( $atts ) {
		$s = GDFF_Settings::get();

		$a = shortcode_atts(
			array(
				'limit'   => $s['limit'],
				'columns' => $s['columns'],
				'layout'  => $s['layout'],
				'mode'    => $s['mode'],
				'excerpt' => $s['excerpt_length'],
				'images'  => $s['show_images'] ? 'yes' : 'no',
				'header'  => $s['show_header'] ? 'yes' : 'no',
				'follow'  => $s['show_follow'] ? 'yes' : 'no',
			),
			$atts,
			'fb_posts'
		);

		wp_enqueue_style( 'gdff' );

		if ( 'embed' === $a['mode'] ) {
			return self::render_embed( $s );
		}

		$data = self::get_data();

		if ( is_wp_error( $data ) ) {
			$out = '';
			if ( current_user_can( 'manage_options' ) ) {
				$out .= '<div class="gdff-admin-error"><strong>FB Posts Feed:</strong> ' . esc_html( $data->get_error_message() )
					. ' <a href="' . esc_url( admin_url( 'options-general.php?page=gdff' ) ) . '">Open settings</a>'
					. '<br><small>Only administrators see this message. Visitors see the Facebook embed below instead.</small></div>';
			}
			// Visitors still get something useful.
			return $out . self::render_embed( $s );
		}

		return self::render_posts( $data, $a, $s );
	}

	private static function bool( $v ) {
		return in_array( strtolower( (string) $v ), array( '1', 'yes', 'true', 'on' ), true );
	}

	private static function render_posts( array $data, array $a, array $s ) {
		$limit   = max( 1, min( 50, (int) $a['limit'] ) );
		$columns = max( 1, min( 4, (int) $a['columns'] ) );
		$layout  = 'list' === $a['layout'] ? 'list' : 'grid';
		$excerpt = max( 0, (int) $a['excerpt'] );
		$images  = self::bool( $a['images'] );
		$header  = self::bool( $a['header'] );
		$follow  = self::bool( $a['follow'] );
		$posts   = array_slice( (array) $data['posts'], 0, $limit );
		$page    = $data['page'];
		$accent  = sanitize_hex_color( $s['accent_color'] );
		$accent  = $accent ? $accent : '#1877f2';
		$target  = ! empty( $s['new_tab'] ) ? ' target="_blank" rel="noopener"' : '';

		if ( empty( $posts ) ) {
			return '';
		}

		$style = sprintf( '--gdff-cols:%d;--gdff-accent:%s;', $columns, $accent );

		ob_start();
		?>
		<div class="gdff gdff--<?php echo esc_attr( $layout ); ?> gdff--cols-<?php echo (int) $columns; ?>" style="<?php echo esc_attr( $style ); ?>">
			<div class="gdff-gallery">
				<?php foreach ( $posts as $post ) : ?>
					<?php echo self::render_post( $post, $page, $excerpt, $images, $header, $s, $target ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php endforeach; ?>
			</div>
			<?php if ( $follow && ! empty( $page['link'] ) ) : ?>
				<div class="gdff-follow">
					<a class="gdff-follow__btn" href="<?php echo esc_url( $page['link'] ); ?>"<?php echo $target; // phpcs:ignore ?>>
						<?php echo self::fb_icon(); // phpcs:ignore ?>
						<span><?php echo esc_html( $s['follow_text'] ); ?></span>
					</a>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * One gallery card. The whole card is a single link to the Facebook post.
	 */
	private static function render_post( array $post, array $page, $excerpt, $images, $header, array $s, $target ) {
		$text_html = self::format_message( $post['message'], $excerpt );
		$has_image = $images && '' !== $post['image'];

		// A shared link without its own text: use the link title as the text.
		if ( '' === $text_html && '' !== $post['link_title'] ) {
			$text_html = esc_html( $post['link_title'] );
		}

		$classes = 'gdff-card ' . ( $has_image ? 'gdff-card--image' : 'gdff-card--text' );
		$label   = '' !== $post['message'] ? wp_trim_words( $post['message'], 12, '…' ) : $s['read_more_text'];

		ob_start();
		?>
		<a class="<?php echo esc_attr( $classes ); ?>" href="<?php echo esc_url( $post['permalink'] ); ?>"<?php echo $target; // phpcs:ignore ?> aria-label="<?php echo esc_attr( $label ); ?>">
			<?php if ( $has_image ) : ?>
				<span class="gdff-card__media<?php echo $post['is_video'] ? ' is-video' : ''; ?>">
					<img src="<?php echo esc_url( $post['image'] ); ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">
					<?php if ( $post['is_video'] ) : ?>
						<span class="gdff-card__play" aria-hidden="true"></span>
					<?php endif; ?>
					<?php if ( $post['album_count'] > 1 ) : ?>
						<span class="gdff-card__count">+<?php echo (int) ( $post['album_count'] - 1 ); ?></span>
					<?php endif; ?>
				</span>
			<?php endif; ?>

			<span class="gdff-card__body">
				<?php if ( $header ) : ?>
					<span class="gdff-card__head">
						<?php if ( ! empty( $page['picture'] ) ) : ?>
							<img class="gdff-card__avatar" src="<?php echo esc_url( $page['picture'] ); ?>" alt="" width="32" height="32" loading="lazy" referrerpolicy="no-referrer">
						<?php endif; ?>
						<span class="gdff-card__meta">
							<span class="gdff-card__page"><?php echo esc_html( $page['name'] ); ?></span>
							<?php if ( $post['created'] ) : ?>
								<time class="gdff-card__date" datetime="<?php echo esc_attr( gmdate( 'c', $post['created'] ) ); ?>"><?php echo esc_html( self::format_date( $post['created'] ) ); ?></time>
							<?php endif; ?>
						</span>
					</span>
				<?php endif; ?>

				<?php if ( '' !== $text_html ) : ?>
					<span class="gdff-card__text"><?php echo $text_html; // phpcs:ignore -- escaped in format_message() ?></span>
				<?php endif; ?>

				<?php if ( '' !== $post['link_url'] && '' !== $post['message'] ) : ?>
					<span class="gdff-card__link"><?php echo esc_html( (string) wp_parse_url( $post['link_url'], PHP_URL_HOST ) ); ?></span>
				<?php endif; ?>

				<span class="gdff-card__more"><?php echo esc_html( $s['read_more_text'] ); ?> <span aria-hidden="true">&rarr;</span></span>
			</span>
		</a>
		<?php
		return ob_get_clean();
	}

	/**
	 * Escape and truncate the post text. No inner links: the whole card is the link.
	 *
	 * @return string Safe HTML.
	 */
	public static function format_message( $text, $max ) {
		$text = trim( str_replace( "\r\n", "\n", (string) $text ) );
		if ( '' === $text ) {
			return '';
		}

		if ( $max > 0 && mb_strlen( $text ) > $max ) {
			$cut   = mb_substr( $text, 0, $max );
			$space = mb_strrpos( $cut, ' ' );
			if ( false !== $space && $space > $max * 0.6 ) {
				$cut = mb_substr( $cut, 0, $space );
			}
			$text = rtrim( $cut, " \t\n.,;:!?-" ) . '…';
		}

		$html = esc_html( $text );

		// Highlight #hashtags.
		$html = preg_replace( '/(^|[\s(])#([\p{L}\p{N}_]+)/u', '$1<span class="gdff-tag">#$2</span>', $html );

		$html = preg_replace( "/\n{3,}/", "\n\n", $html );
		return nl2br( $html, false );
	}

	private static function format_date( $ts ) {
		$diff = time() - $ts;
		if ( $diff >= 0 && $diff < WEEK_IN_SECONDS ) {
			/* translators: %s: human-readable time difference. Uses WordPress core translation. */
			return sprintf( __( '%s ago' ), human_time_diff( $ts ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
		}
		return wp_date( get_option( 'date_format' ), $ts );
	}

	/* ------------------------------------------------------------------
	 * Embed (no-token fallback using Facebook's Page Plugin)
	 * ---------------------------------------------------------------- */

	public static function render_embed( array $s ) {
		$page_url = trim( (string) $s['page_url'] );
		if ( '' === $page_url ) {
			return '';
		}
		$height = max( 300, min( 2000, (int) $s['embed_height'] ) );

		$src = add_query_arg(
			array(
				'href'                  => rawurlencode( $page_url ),
				'tabs'                  => 'timeline',
				'width'                 => 500,
				'height'                => $height,
				'small_header'          => 'false',
				'adapt_container_width' => 'true',
				'hide_cover'            => 'false',
				'show_facepile'         => 'false',
				'locale'                => str_replace( '-', '_', get_locale() ),
			),
			'https://www.facebook.com/plugins/page.php'
		);

		return sprintf(
			'<div class="gdff-embed"><iframe src="%s" width="500" height="%d" style="border:none;overflow:hidden" scrolling="no" frameborder="0" allowfullscreen="true" loading="lazy" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share" title="Facebook"></iframe></div>',
			esc_url( $src ),
			$height
		);
	}

	public static function fb_icon() {
		return '<svg class="gdff-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.95.93-1.95 1.88v2.26h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z"/></svg>';
	}
}
