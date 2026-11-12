<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page: Settings → Facebook Feed.
 */
class GDFF_Admin {

	const SLUG = 'gdff';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_gdff_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_gdff_choose_page', array( __CLASS__, 'handle_choose_page' ) );
		add_action( 'admin_post_gdff_refresh', array( __CLASS__, 'handle_refresh' ) );
		add_action( 'admin_post_gdff_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( GDFF_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Settings</a>' );
		return $links;
	}

	private static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'options-general.php' ) );
	}

	public static function menu() {
		add_options_page( 'Facebook Feed', 'Facebook Feed', 'manage_options', self::SLUG, array( __CLASS__, 'render_page' ) );
	}

	public static function register() {
		register_setting(
			'gdff',
			GDFF_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$old = GDFF_Settings::get();
		$out = $old;

		$out['mode']           = ( isset( $in['mode'] ) && 'embed' === $in['mode'] ) ? 'embed' : 'api';
		$out['layout']         = ( isset( $in['layout'] ) && 'list' === $in['layout'] ) ? 'list' : 'grid';
		$out['page_url']       = isset( $in['page_url'] ) ? esc_url_raw( trim( $in['page_url'] ) ) : $old['page_url'];
		$out['page_id']        = isset( $in['page_id'] ) ? preg_replace( '/[^0-9A-Za-z._-]/', '', $in['page_id'] ) : $old['page_id'];
		$out['api_version']    = ( isset( $in['api_version'] ) && preg_match( '/^v\d+\.\d+$/', trim( $in['api_version'] ) ) ) ? trim( $in['api_version'] ) : $old['api_version'];
		$out['limit']          = isset( $in['limit'] ) ? max( 1, min( 50, (int) $in['limit'] ) ) : $old['limit'];
		$out['columns']        = isset( $in['columns'] ) ? max( 1, min( 4, (int) $in['columns'] ) ) : $old['columns'];
		$out['excerpt_length'] = isset( $in['excerpt_length'] ) ? max( 0, (int) $in['excerpt_length'] ) : $old['excerpt_length'];
		$out['cache_minutes']  = isset( $in['cache_minutes'] ) ? max( 5, (int) $in['cache_minutes'] ) : $old['cache_minutes'];
		$out['embed_height']   = isset( $in['embed_height'] ) ? max( 300, min( 2000, (int) $in['embed_height'] ) ) : $old['embed_height'];
		$color                 = isset( $in['accent_color'] ) ? sanitize_hex_color( $in['accent_color'] ) : '';
		$out['accent_color']   = $color ? $color : $old['accent_color'];
		$out['read_more_text'] = isset( $in['read_more_text'] ) ? sanitize_text_field( $in['read_more_text'] ) : $old['read_more_text'];
		$out['follow_text']    = isset( $in['follow_text'] ) ? sanitize_text_field( $in['follow_text'] ) : $old['follow_text'];

		foreach ( array( 'show_images', 'show_header', 'show_follow', 'new_tab' ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}

		// Token: on the settings form a blank field keeps the saved one.
		// Programmatic updates (connect / disconnect) set it directly.
		$from_form = ! empty( $in['_form'] );
		if ( isset( $in['access_token'] ) && ( '' !== trim( $in['access_token'] ) || ! $from_form ) ) {
			$out['access_token'] = sanitize_text_field( trim( $in['access_token'] ) );
		}
		unset( $out['_form'] );

		if ( $out['page_id'] !== $old['page_id'] || $out['access_token'] !== $old['access_token'] || $out['api_version'] !== $old['api_version'] ) {
			GDFF_Feed::clear_cache();
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * Actions
	 * ---------------------------------------------------------------- */

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( $action );
	}

	private static function notice( $type, $message ) {
		set_transient( 'gdff_notice_' . get_current_user_id(), array( $type, $message ), 120 );
	}

	private static function post_field( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( trim( wp_unslash( $_POST[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function handle_connect() {
		self::guard( 'gdff_connect' );

		$app_id     = self::post_field( 'app_id' );
		$app_secret = self::post_field( 'app_secret' );
		$user_token = self::post_field( 'user_token' );

		if ( '' === $app_id || '' === $app_secret || '' === $user_token ) {
			self::notice( 'error', 'Please fill in App ID, App Secret and the User Access Token.' );
			wp_safe_redirect( self::url() );
			exit;
		}

		$pages = GDFF_API::exchange_for_page_tokens( $app_id, $app_secret, $user_token );
		if ( is_wp_error( $pages ) ) {
			self::notice( 'error', $pages->get_error_message() );
			wp_safe_redirect( self::url() );
			exit;
		}

		$current = (string) GDFF_Settings::get( 'page_id' );
		$pick    = null;
		foreach ( $pages as $p ) {
			if ( $p['id'] === $current ) {
				$pick = $p;
				break;
			}
		}
		if ( ! $pick && 1 === count( $pages ) ) {
			$pick = $pages[0];
		}

		if ( $pick ) {
			self::save_page( $pick );
			wp_safe_redirect( self::url() );
			exit;
		}

		// Several pages: let the user choose. Tokens stay server-side.
		set_transient( 'gdff_pages_' . get_current_user_id(), $pages, 15 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url( array( 'gdff_choose' => 1 ) ) );
		exit;
	}

	public static function handle_choose_page() {
		self::guard( 'gdff_choose_page' );
		$id    = self::post_field( 'page_id' );
		$pages = get_transient( 'gdff_pages_' . get_current_user_id() );

		foreach ( (array) $pages as $p ) {
			if ( isset( $p['id'] ) && $p['id'] === $id ) {
				delete_transient( 'gdff_pages_' . get_current_user_id() );
				self::save_page( $p );
				wp_safe_redirect( self::url() );
				exit;
			}
		}
		self::notice( 'error', 'That selection expired. Please connect again.' );
		wp_safe_redirect( self::url() );
		exit;
	}

	private static function save_page( array $p ) {
		$values = array(
			'page_id'      => $p['id'],
			'access_token' => $p['access_token'],
			'mode'         => 'api',
		);
		if ( ! empty( $p['link'] ) ) {
			$values['page_url'] = $p['link'];
		}
		GDFF_Settings::update( $values );
		GDFF_Feed::clear_cache();

		$data = GDFF_Feed::refresh();
		if ( is_wp_error( $data ) ) {
			self::notice( 'error', 'Connected to "' . $p['name'] . '", but loading posts failed: ' . $data->get_error_message() );
		} else {
			self::notice( 'success', sprintf( 'Connected to "%s". Loaded %d posts. Add [fb_posts] to any page.', $p['name'], count( $data['posts'] ) ) );
		}
	}

	public static function handle_refresh() {
		self::guard( 'gdff_refresh' );
		delete_transient( GDFF_Feed::LOCK );
		$data = GDFF_Feed::refresh();
		if ( is_wp_error( $data ) ) {
			self::notice( 'error', $data->get_error_message() );
		} else {
			self::notice( 'success', sprintf( 'Connection OK. Loaded %d posts from "%s".', count( $data['posts'] ), $data['page']['name'] ) );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function handle_disconnect() {
		self::guard( 'gdff_disconnect' );
		GDFF_Settings::update( array( 'access_token' => '' ) );
		GDFF_Feed::clear_cache();
		self::notice( 'success', 'Disconnected. The saved access token was removed.' );
		wp_safe_redirect( self::url() );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Page
	 * ---------------------------------------------------------------- */

	private static function action_button( $action, $label, $class = 'button' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 6px 6px 0">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( $action ); ?>
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s      = GDFF_Settings::get();
		$cache  = get_option( GDFF_CACHE_OPTION );
		$error  = get_option( GDFF_ERROR_OPTION );
		$notice = get_transient( 'gdff_notice_' . get_current_user_id() );
		$choose = isset( $_GET['gdff_choose'] ) ? get_transient( 'gdff_pages_' . get_current_user_id() ) : false; // phpcs:ignore WordPress.Security.NonceVerification
		$name   = GDFF_OPTION;

		if ( $notice ) {
			delete_transient( 'gdff_notice_' . get_current_user_id() );
		}
		?>
		<div class="wrap gdff-admin">
			<h1>Facebook Feed</h1>
			<style>
				.gdff-admin .card{max-width:860px;padding:16px 20px}
				.gdff-admin .gdff-status{display:inline-block;padding:2px 10px;border-radius:999px;font-weight:600}
				.gdff-admin .ok{background:#d1f0d7;color:#0a5c1f}.gdff-admin .bad{background:#fbe2e2;color:#8a1f1f}
				.gdff-admin code.big{font-size:14px;padding:4px 8px}
				.gdff-admin details summary{cursor:pointer;font-weight:600}
				.gdff-admin ol li{margin-bottom:6px}
			</style>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo 'success' === $notice[0] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>

			<?php if ( is_array( $choose ) && $choose ) : ?>
				<div class="card">
					<h2>Choose your Facebook Page</h2>
					<p>Your account manages several Pages. Which one should be shown?</p>
					<?php foreach ( $choose as $p ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:6px">
							<input type="hidden" name="action" value="gdff_choose_page">
							<input type="hidden" name="page_id" value="<?php echo esc_attr( $p['id'] ); ?>">
							<?php wp_nonce_field( 'gdff_choose_page' ); ?>
							<button class="button button-primary" type="submit"><?php echo esc_html( $p['name'] ); ?></button>
							<code><?php echo esc_html( $p['id'] ); ?></code>
						</form>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<!-- Status -->
			<div class="card">
				<h2>Status</h2>
				<?php if ( 'embed' === $s['mode'] ) : ?>
					<p><span class="gdff-status ok">Embed mode</span> Showing Facebook's own timeline widget. Switch to <em>Gallery</em> mode below for the styled gallery.</p>
				<?php elseif ( GDFF_Settings::is_connected() && is_array( $cache ) && ! $error ) : ?>
					<p><span class="gdff-status ok">Connected</span>
						Page: <strong><?php echo esc_html( $cache['page']['name'] ); ?></strong> ·
						<?php echo (int) count( $cache['posts'] ); ?> posts cached ·
						updated <?php echo esc_html( human_time_diff( (int) $cache['fetched'] ) ); ?> ago
					</p>
				<?php elseif ( GDFF_Settings::is_connected() ) : ?>
					<p><span class="gdff-status <?php echo $error ? 'bad' : 'ok'; ?>"><?php echo $error ? 'Problem' : 'Connected'; ?></span>
						<?php echo $error ? esc_html( $error['message'] ) : 'Token saved. Click “Test connection” to load posts.'; ?>
					</p>
				<?php else : ?>
					<p><span class="gdff-status bad">Not connected</span> Follow the steps in “Connect your Facebook Page” below. Until then visitors see the Facebook embed.</p>
				<?php endif; ?>

				<p>Shortcode — paste into any page (or a “Shortcode” block):<br><code class="big">[fb_posts]</code></p>
				<p class="description">Options: <code>[fb_posts limit="9" columns="3" excerpt="200" images="yes" header="yes" follow="yes" layout="grid" mode="api"]</code></p>

				<?php
				if ( GDFF_Settings::is_connected() ) {
					self::action_button( 'gdff_refresh', 'Test connection / Refresh now', 'button button-primary' );
					self::action_button( 'gdff_disconnect', 'Disconnect' );
				}
				?>
			</div>

			<!-- Connect -->
			<div class="card">
				<h2>Connect your Facebook Page</h2>
				<details <?php echo GDFF_Settings::is_connected() ? '' : 'open'; ?>>
					<summary>Step-by-step (about 5 minutes, done once)</summary>
					<ol>
						<li>Go to <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">developers.facebook.com/apps</a> (log in with the account that is admin of your Page) → <strong>Create app</strong>. Pick the use case for managing a Facebook <strong>Page</strong>. The app can stay in development mode — no review is needed because you only read your own Page.</li>
						<li>In the app open <strong>App settings → Basic</strong> and copy the <strong>App ID</strong> and <strong>App Secret</strong>.</li>
						<li>Open the <a href="https://developers.facebook.com/tools/explorer/" target="_blank" rel="noopener">Graph API Explorer</a>, select your app, choose <strong>User Token</strong>, add the permissions <code>pages_show_list</code> and <code>pages_read_engagement</code>, then click <strong>Generate Access Token</strong> and allow access to your Page.</li>
						<li>Copy the token and paste all three values below, then click <strong>Connect</strong>. The plugin turns it into a permanent Page token. The App Secret is used once and is <em>not</em> saved.</li>
					</ol>
				</details>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="gdff_connect">
					<?php wp_nonce_field( 'gdff_connect' ); ?>
					<table class="form-table" role="presentation">
						<tr><th><label for="gdff_app_id">App ID</label></th><td><input id="gdff_app_id" name="app_id" type="text" class="regular-text" autocomplete="off"></td></tr>
						<tr><th><label for="gdff_app_secret">App Secret</label></th><td><input id="gdff_app_secret" name="app_secret" type="password" class="regular-text" autocomplete="off"></td></tr>
						<tr><th><label for="gdff_user_token">User Access Token</label></th><td><textarea id="gdff_user_token" name="user_token" rows="3" class="large-text code" autocomplete="off"></textarea></td></tr>
					</table>
					<?php submit_button( GDFF_Settings::is_connected() ? 'Reconnect' : 'Connect', 'primary', 'submit', false ); ?>
				</form>
			</div>

			<!-- Settings -->
			<div class="card">
				<h2>Display settings</h2>
				<form method="post" action="options.php">
					<?php settings_fields( 'gdff' ); ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>[_form]" value="1">
					<table class="form-table" role="presentation">
						<tr>
							<th>Mode</th>
							<td>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="api" <?php checked( $s['mode'], 'api' ); ?>> <strong>Gallery</strong> — styled cards that match your site (needs the connection above)</label><br>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="embed" <?php checked( $s['mode'], 'embed' ); ?>> <strong>Facebook embed</strong> — Facebook's own widget, no setup</label>
							</td>
						</tr>
						<tr>
							<th>Layout</th>
							<td>
								<select name="<?php echo esc_attr( $name ); ?>[layout]">
									<option value="grid" <?php selected( $s['layout'], 'grid' ); ?>>Gallery grid</option>
									<option value="list" <?php selected( $s['layout'], 'list' ); ?>>Single column</option>
								</select>
							</td>
						</tr>
						<tr><th><label for="gdff_limit">Number of posts</label></th><td><input id="gdff_limit" type="number" min="1" max="50" name="<?php echo esc_attr( $name ); ?>[limit]" value="<?php echo esc_attr( $s['limit'] ); ?>" class="small-text"></td></tr>
						<tr><th><label for="gdff_cols">Columns on desktop</label></th><td><input id="gdff_cols" type="number" min="1" max="4" name="<?php echo esc_attr( $name ); ?>[columns]" value="<?php echo esc_attr( $s['columns'] ); ?>" class="small-text"> <span class="description">Automatically 2 on tablets and 1 on phones.</span></td></tr>
						<tr><th><label for="gdff_excerpt">Text length</label></th><td><input id="gdff_excerpt" type="number" min="0" name="<?php echo esc_attr( $name ); ?>[excerpt_length]" value="<?php echo esc_attr( $s['excerpt_length'] ); ?>" class="small-text"> characters <span class="description">(0 = full text)</span></td></tr>
						<tr>
							<th>Show</th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_images]" value="1" <?php checked( $s['show_images'] ); ?>> Images</label><br>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_header]" value="1" <?php checked( $s['show_header'] ); ?>> Page name, picture and date</label><br>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_follow]" value="1" <?php checked( $s['show_follow'] ); ?>> “Follow us” button under the gallery</label><br>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[new_tab]" value="1" <?php checked( $s['new_tab'] ); ?>> Open posts in a new tab</label>
							</td>
						</tr>
						<tr><th><label for="gdff_accent">Accent colour</label></th><td><input id="gdff_accent" type="color" name="<?php echo esc_attr( $name ); ?>[accent_color]" value="<?php echo esc_attr( $s['accent_color'] ); ?>"></td></tr>
						<tr><th><label for="gdff_more">“Read more” text</label></th><td><input id="gdff_more" type="text" name="<?php echo esc_attr( $name ); ?>[read_more_text]" value="<?php echo esc_attr( $s['read_more_text'] ); ?>" class="regular-text"></td></tr>
						<tr><th><label for="gdff_follow">“Follow” button text</label></th><td><input id="gdff_follow" type="text" name="<?php echo esc_attr( $name ); ?>[follow_text]" value="<?php echo esc_attr( $s['follow_text'] ); ?>" class="regular-text"></td></tr>
						<tr><th><label for="gdff_url">Facebook Page URL</label></th><td><input id="gdff_url" type="url" name="<?php echo esc_attr( $name ); ?>[page_url]" value="<?php echo esc_attr( $s['page_url'] ); ?>" class="large-text"> <p class="description">Used by the embed and the “Follow” button.</p></td></tr>
					</table>

					<details style="margin:8px 0 16px">
						<summary>Advanced</summary>
						<table class="form-table" role="presentation">
							<tr><th><label for="gdff_pid">Page ID</label></th><td><input id="gdff_pid" type="text" name="<?php echo esc_attr( $name ); ?>[page_id]" value="<?php echo esc_attr( $s['page_id'] ); ?>" class="regular-text"> <span class="description">Filled in automatically when you connect.</span></td></tr>
							<tr><th><label for="gdff_tok">Page Access Token</label></th><td><input id="gdff_tok" type="password" name="<?php echo esc_attr( $name ); ?>[access_token]" value="" placeholder="<?php echo $s['access_token'] ? esc_attr( '•••••••• saved (…' . substr( $s['access_token'], -4 ) . ')' ) : 'Not set'; ?>" class="large-text" autocomplete="off"> <p class="description">Leave blank to keep the saved token. Only paste one here if you created a Page token yourself.</p></td></tr>
							<tr><th><label for="gdff_cache">Refresh every</label></th><td><input id="gdff_cache" type="number" min="5" name="<?php echo esc_attr( $name ); ?>[cache_minutes]" value="<?php echo esc_attr( $s['cache_minutes'] ); ?>" class="small-text"> minutes</td></tr>
							<tr><th><label for="gdff_ver">Graph API version</label></th><td><input id="gdff_ver" type="text" name="<?php echo esc_attr( $name ); ?>[api_version]" value="<?php echo esc_attr( $s['api_version'] ); ?>" class="small-text"></td></tr>
							<tr><th><label for="gdff_eh">Embed height</label></th><td><input id="gdff_eh" type="number" min="300" max="2000" name="<?php echo esc_attr( $name ); ?>[embed_height]" value="<?php echo esc_attr( $s['embed_height'] ); ?>" class="small-text"> px</td></tr>
						</table>
					</details>

					<?php submit_button( 'Save settings' ); ?>
				</form>
			</div>
		</div>
		<?php
	}
}
