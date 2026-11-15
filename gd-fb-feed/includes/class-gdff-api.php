<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin client for the Facebook Graph API.
 */
class GDFF_API {

	const BASE = 'https://graph.facebook.com/';

	/**
	 * GET a Graph API path.
	 *
	 * @return array|WP_Error Decoded JSON body or error.
	 */
	public static function request( $path, array $args = array(), $version = null ) {
		$version = $version ? $version : GDFF_Settings::get( 'api_version' );
		$version = preg_match( '/^v\d+\.\d+$/', (string) $version ) ? $version : 'v26.0';

		$url = self::BASE . $version . '/' . ltrim( $path, '/' ) . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );

		$res = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; FB Posts Feed/' . GDFF_VERSION,
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'gdff_bad_response', sprintf( 'Unexpected response from Facebook (HTTP %d).', $code ) );
		}

		if ( isset( $body['error'] ) ) {
			$e   = $body['error'];
			$msg = isset( $e['message'] ) ? $e['message'] : 'Unknown error';
			$c   = isset( $e['code'] ) ? $e['code'] : $code;
			return new WP_Error( 'gdff_fb_' . $c, sprintf( 'Facebook error %s: %s', $c, $msg ), $e );
		}

		if ( $code >= 400 ) {
			return new WP_Error( 'gdff_http_' . $code, sprintf( 'Facebook returned HTTP %d.', $code ) );
		}

		return $body;
	}

	/**
	 * Fetch the Page info and its latest posts, normalised for rendering.
	 *
	 * @return array|WP_Error
	 */
	public static function fetch_feed() {
		$s = GDFF_Settings::get();

		if ( ! GDFF_Settings::is_connected() ) {
			return new WP_Error( 'gdff_not_configured', 'The plugin is not connected to a Facebook Page yet.' );
		}

		$token = $s['access_token'];
		$limit = min( 50, max( 25, (int) $s['limit'] ) );

		$page = self::request(
			$s['page_id'],
			array(
				'fields'       => 'id,name,link,picture.type(large){url}',
				'access_token' => $token,
			)
		);
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		// Facebook may return fewer posts per page than asked for, so follow
		// the "after" cursor until we have enough (max 5 requests).
		$wanted = $limit;
		$posts  = array();
		$after  = '';
		for ( $i = 0; $i < 5 && count( $posts ) < $wanted; $i++ ) {
			$args = array(
				'fields'       => 'id,message,created_time,permalink_url,full_picture,status_type,attachments{media_type,type,url,title,subattachments.limit(50){media_type}}',
				'limit'        => $limit,
				'access_token' => $token,
			);
			if ( '' !== $after ) {
				$args['after'] = $after;
			}

			$res = self::request( $s['page_id'] . '/posts', $args );
			if ( is_wp_error( $res ) ) {
				if ( $i > 0 ) {
					break; // Keep what we already have.
				}
				return $res;
			}

			foreach ( (array) ( isset( $res['data'] ) ? $res['data'] : array() ) as $raw ) {
				$post = self::normalize_post( $raw );
				if ( $post ) {
					$posts[] = $post;
				}
			}

			$after = isset( $res['paging']['cursors']['after'] ) ? (string) $res['paging']['cursors']['after'] : '';
			if ( '' === $after || empty( $res['paging']['next'] ) || empty( $res['data'] ) ) {
				break;
			}
		}

		return array(
			'page'    => array(
				'id'      => isset( $page['id'] ) ? (string) $page['id'] : '',
				'name'    => isset( $page['name'] ) ? (string) $page['name'] : '',
				'link'    => ! empty( $page['link'] ) ? (string) $page['link'] : $s['page_url'],
				'picture' => isset( $page['picture']['data']['url'] ) ? (string) $page['picture']['data']['url'] : '',
			),
			'posts'   => $posts,
			'fetched' => time(),
		);
	}

	/**
	 * Facebook's placeholder for shared content that is no longer visible.
	 */
	public static function is_unavailable_text( $text ) {
		$text = mb_strtolower( trim( (string) $text ) );
		if ( '' === $text ) {
			return false;
		}
		$needles = array(
			'this content isn\'t available',
			'this content isn’t available',
			'this content is not available',
			'šis turinys šiuo metu nepasiekiamas',
			'šis turinys nepasiekiamas',
		);
		foreach ( $needles as $n ) {
			if ( 0 === strpos( $text, $n ) ) {
				return true;
			}
		}
		return false;
	}

	private static function normalize_post( array $raw ) {
		$message = isset( $raw['message'] ) ? (string) $raw['message'] : '';
		$image   = isset( $raw['full_picture'] ) ? (string) $raw['full_picture'] : '';
		$att     = isset( $raw['attachments']['data'][0] ) ? $raw['attachments']['data'][0] : array();

		$media_type = isset( $att['media_type'] ) ? strtolower( (string) $att['media_type'] ) : '';
		$att_type   = isset( $att['type'] ) ? strtolower( (string) $att['type'] ) : '';
		$status     = isset( $raw['status_type'] ) ? (string) $raw['status_type'] : '';

		// Shared content that was deleted or made private: Facebook returns a placeholder
		// ("This content isn't available right now"). Ignore the attachment.
		$att_title = isset( $att['title'] ) ? (string) $att['title'] : '';
		if ( in_array( $att_type, array( 'native_templates', 'unavailable' ), true ) || self::is_unavailable_text( $att_title ) || self::is_unavailable_text( $message ) ) {
			$att = array();
			if ( self::is_unavailable_text( $message ) ) {
				$message = '';
			}
		}

		// Skip posts with nothing to show (e.g. some page-update events, unavailable shares).
		if ( '' === trim( $message ) && '' === $image && empty( $att ) ) {
			return null;
		}

		$is_video = false !== strpos( $media_type, 'video' ) || false !== strpos( $att_type, 'video' ) || 'added_video' === $status;

		$album_count = 0;
		if ( ! empty( $att['subattachments']['data'] ) ) {
			$album_count = count( $att['subattachments']['data'] );
		}

		// Shared external link preview.
		$link_url   = '';
		$link_title = '';
		if ( 'share' === $att_type || 'link' === $media_type ) {
			$link_url   = isset( $att['url'] ) ? (string) $att['url'] : '';
			$link_title = isset( $att['title'] ) ? (string) $att['title'] : '';
		}

		$id = isset( $raw['id'] ) ? (string) $raw['id'] : '';

		return array(
			'id'          => $id,
			'message'     => $message,
			'created'     => isset( $raw['created_time'] ) ? (int) strtotime( $raw['created_time'] ) : 0,
			'permalink'   => ! empty( $raw['permalink_url'] ) ? (string) $raw['permalink_url'] : 'https://www.facebook.com/' . rawurlencode( $id ),
			'image'       => $image,
			'is_video'    => $is_video,
			'album_count' => $album_count,
			'link_url'    => $link_url,
			'link_title'  => $link_title,
		);
	}

	/**
	 * Turn a short-lived user token into a list of never-expiring Page tokens.
	 *
	 * @return array|WP_Error List of pages: [ ['id','name','link','access_token'], ... ]
	 */
	public static function exchange_for_page_tokens( $app_id, $app_secret, $user_token ) {
		$token = $user_token;

		// With App ID + Secret: turn a short-lived user token into a long-lived one.
		// Without them (e.g. a non-expiring System User token from Business Settings): use the token as-is.
		if ( '' !== (string) $app_id && '' !== (string) $app_secret ) {
			$long = self::request(
				'oauth/access_token',
				array(
					'grant_type'        => 'fb_exchange_token',
					'client_id'         => $app_id,
					'client_secret'     => $app_secret,
					'fb_exchange_token' => $user_token,
				)
			);
			if ( is_wp_error( $long ) ) {
				return $long;
			}
			if ( empty( $long['access_token'] ) ) {
				return new WP_Error( 'gdff_no_token', 'Facebook did not return a long-lived token.' );
			}
			$token = $long['access_token'];
		}

		$accounts = self::request(
			'me/accounts',
			array(
				'fields'       => 'id,name,link,access_token',
				'limit'        => 100,
				'access_token' => $token,
			)
		);

		// The pasted token may already be a Page token: then "me" is the Page itself.
		if ( is_wp_error( $accounts ) ) {
			$me = self::request(
				'me',
				array(
					'fields'       => 'id,name,link',
					'access_token' => $token,
				)
			);
			if ( ! is_wp_error( $me ) && ! empty( $me['id'] ) ) {
				$check = self::request(
					$me['id'] . '/posts',
					array(
						'limit'        => 1,
						'access_token' => $token,
					)
				);
				if ( ! is_wp_error( $check ) ) {
					return array(
						array(
							'id'           => (string) $me['id'],
							'name'         => isset( $me['name'] ) ? (string) $me['name'] : (string) $me['id'],
							'link'         => isset( $me['link'] ) ? (string) $me['link'] : '',
							'access_token' => $token,
						),
					);
				}
			}
			return $accounts;
		}

		$pages = array();
		foreach ( (array) ( isset( $accounts['data'] ) ? $accounts['data'] : array() ) as $p ) {
			if ( empty( $p['id'] ) || empty( $p['access_token'] ) ) {
				continue;
			}
			$pages[] = array(
				'id'           => (string) $p['id'],
				'name'         => isset( $p['name'] ) ? (string) $p['name'] : $p['id'],
				'link'         => isset( $p['link'] ) ? (string) $p['link'] : '',
				'access_token' => (string) $p['access_token'],
			);
		}

		// me/accounts can be empty for System Users even when the Page is assigned:
		// ask for the configured Page directly.
		$page_id = (string) GDFF_Settings::get( 'page_id' );
		if ( empty( $pages ) && '' !== $page_id ) {
			$direct = self::request(
				$page_id,
				array(
					'fields'       => 'id,name,link,access_token',
					'access_token' => $token,
				)
			);
			if ( ! is_wp_error( $direct ) && ! empty( $direct['id'] ) ) {
				$page_token = ! empty( $direct['access_token'] ) ? (string) $direct['access_token'] : $token;
				$check      = self::request(
					$direct['id'] . '/posts',
					array(
						'limit'        => 1,
						'access_token' => $page_token,
					)
				);
				if ( ! is_wp_error( $check ) ) {
					$pages[] = array(
						'id'           => (string) $direct['id'],
						'name'         => isset( $direct['name'] ) ? (string) $direct['name'] : $page_id,
						'link'         => isset( $direct['link'] ) ? (string) $direct['link'] : '',
						'access_token' => $page_token,
					);
				}
			}
		}

		if ( empty( $pages ) ) {
			$who = self::request(
				'me',
				array(
					'fields'       => 'id,name',
					'access_token' => $token,
				)
			);
			$who = ( ! is_wp_error( $who ) && ! empty( $who['name'] ) ) ? ' The token belongs to "' . $who['name'] . '" (ID ' . $who['id'] . '), and no Page is assigned to it.' : '';

			return new WP_Error(
				'gdff_no_pages',
				'No Facebook Pages were found for this token.' . $who . ' For a System User token: in Business Settings → Users → System users → select the user → Assign assets → Pages → tick your Page, then generate a new token and leave App ID / App Secret empty. For a Graph API Explorer token: make sure your Page was ticked in the Facebook popup.'
			);
		}

		return $pages;
	}
}
