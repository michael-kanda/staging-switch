<?php
/**
 * Frontend: Staging ist unsichtbar (noindex/nofollow, aus Queries/Sitemaps raus) und nur per Freigabe-Link erreichbar.
 *
 * @package StagingSwitch
 */

defined( 'ABSPATH' ) || exit;

final class SWS_Frontend {

	public static function init() {
		// Zugriffsschutz und Header.
		add_action( 'template_redirect', array( __CLASS__, 'gate' ), 1 );

		// Robots (WordPress-Core und die drei großen SEO-Plugins).
		add_filter( 'wp_robots', array( __CLASS__, 'robots_core' ), 999 );
		add_filter( 'wpseo_robots', array( __CLASS__, 'robots_string' ), 999 );
		add_filter( 'wpseo_robots_array', array( __CLASS__, 'robots_yoast_array' ), 999 );
		add_filter( 'rank_math/frontend/robots', array( __CLASS__, 'robots_rankmath' ), 999 );
		add_filter( 'aioseo_robots_meta', array( __CLASS__, 'robots_aioseo' ), 999 );

		// Canonical immer auf die Live-Seite.
		add_filter( 'get_canonical_url', array( __CLASS__, 'canonical_core' ), 999, 2 );
		add_filter( 'wpseo_canonical', array( __CLASS__, 'canonical_current' ), 999 );
		add_filter( 'rank_math/frontend/canonical', array( __CLASS__, 'canonical_current' ), 999 );
		add_filter( 'aioseo_canonical_url', array( __CLASS__, 'canonical_current' ), 999 );

		// Aus Queries, Menüs, Suche, Feeds, REST, Sitemaps heraushalten.
		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_from_queries' ) );
		add_filter( 'get_pages', array( __CLASS__, 'exclude_from_pages' ) );
		add_filter( 'get_previous_post_where', array( __CLASS__, 'adjacent_where' ) );
		add_filter( 'get_next_post_where', array( __CLASS__, 'adjacent_where' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest_guard' ), 10, 3 );
		add_filter( 'oembed_request_post_id', array( __CLASS__, 'oembed_guard' ) );
		add_filter( 'redirect_guess_404_permalink', array( __CLASS__, 'no_guess_redirect' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_core' ) );
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( __CLASS__, 'merge_ids' ) );
		add_filter( 'rank_math/sitemap/posts_to_exclude', array( __CLASS__, 'merge_ids' ) );
		add_filter( 'aioseo_sitemap_posts', array( __CLASS__, 'sitemap_aioseo' ) );

		// Freigabe-Leiste für Kunden.
		add_action( 'wp_footer', array( __CLASS__, 'approval_bar' ) );
		add_action( 'admin_post_nopriv_sws_approve', array( __CLASS__, 'handle_approval' ) );
		add_action( 'admin_post_sws_approve', array( __CLASS__, 'handle_approval' ) );

		// Admin-Bar im Frontend.
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );
	}

	/* ------------------------------------------------------------------
	 * Zugriff
	 * ---------------------------------------------------------------- */

	private static function request_token() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token ist selbst das Geheimnis.
		return isset( $_GET['sws_token'] ) ? sanitize_text_field( wp_unslash( $_GET['sws_token'] ) ) : '';
	}

	private static function is_staging_request() {
		return is_singular() && SWS_Core::is_staging( get_queried_object_id() );
	}

	private static function token_ok( $id ) {
		return SWS_Core::token_valid( $id, self::request_token() );
	}

	public static function gate() {
		if ( ! self::is_staging_request() ) {
			return;
		}
		$id = get_queried_object_id();

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
		header( 'Referrer-Policy: same-origin', true );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'staging-switch' );

		if ( current_user_can( 'edit_post', $id ) || self::token_ok( $id ) ) {
			return;
		}

		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		$template = get_404_template();
		if ( $template ) {
			load_template( $template );
		} else {
			wp_die( esc_html__( 'Nicht gefunden.', 'staging-switch' ), '', array( 'response' => 404 ) );
		}
		exit;
	}

	/* ------------------------------------------------------------------
	 * Robots und Canonical
	 * ---------------------------------------------------------------- */

	public static function robots_core( $robots ) {
		if ( self::is_staging_request() ) {
			unset( $robots['index'], $robots['follow'], $robots['max-image-preview'], $robots['max-snippet'], $robots['max-video-preview'] );
			$robots['noindex']   = true;
			$robots['nofollow']  = true;
			$robots['noarchive'] = true;
		}
		return $robots;
	}

	public static function robots_string( $robots ) {
		return self::is_staging_request() ? 'noindex, nofollow, noarchive' : $robots;
	}

	public static function robots_yoast_array( $robots ) {
		if ( self::is_staging_request() && is_array( $robots ) ) {
			$robots['index']   = 'noindex';
			$robots['follow']  = 'nofollow';
			$robots['archive'] = 'noarchive';
		}
		return $robots;
	}

	public static function robots_rankmath( $robots ) {
		if ( self::is_staging_request() && is_array( $robots ) ) {
			$robots['index']   = 'noindex';
			$robots['follow']  = 'nofollow';
			$robots['archive'] = 'noarchive';
		}
		return $robots;
	}

	public static function robots_aioseo( $robots ) {
		if ( self::is_staging_request() && is_array( $robots ) ) {
			$robots['noindex']   = 'noindex';
			$robots['nofollow']  = 'nofollow';
			$robots['noarchive'] = 'noarchive';
		}
		return $robots;
	}

	public static function canonical_core( $url, $post ) {
		$post = get_post( $post );
		if ( $post && SWS_Core::is_staging( $post->ID ) ) {
			return get_permalink( SWS_Core::live_id( $post->ID ) );
		}
		return $url;
	}

	public static function canonical_current( $url ) {
		return self::is_staging_request() ? get_permalink( SWS_Core::live_id( get_queried_object_id() ) ) : $url;
	}

	/* ------------------------------------------------------------------
	 * Ausschluss
	 * ---------------------------------------------------------------- */

	public static function exclude_from_queries( $query ) {
		if ( is_admin() ) {
			return;
		}
		// Die Einzelansicht selbst läuft durch gate().
		if ( $query->is_main_query() && $query->is_singular() ) {
			return;
		}
		$ids = SWS_Core::all_staging_ids();
		if ( $ids ) {
			$query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), $ids ) );
		}
	}

	public static function exclude_from_pages( $pages ) {
		$ids = SWS_Core::all_staging_ids();
		if ( ! $ids || is_admin() ) {
			return $pages;
		}
		return array_values(
			array_filter(
				$pages,
				static function ( $p ) use ( $ids ) {
					return ! in_array( (int) $p->ID, $ids, true );
				}
			)
		);
	}

	public static function adjacent_where( $where ) {
		$ids = SWS_Core::all_staging_ids();
		return $ids ? $where . ' AND p.ID NOT IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' : $where;
	}

	public static function rest_guard( $result, $server, $request ) {
		if ( null !== $result || ! preg_match( '#^/wp/v2/[^/]+/(\d+)(/|$)#', $request->get_route(), $m ) ) {
			return $result;
		}
		$id = (int) $m[1];
		if ( SWS_Core::is_staging( $id ) && ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Ungültige Beitrags-ID.', 'staging-switch' ), array( 'status' => 404 ) );
		}
		return $result;
	}

	public static function oembed_guard( $post_id ) {
		return ( $post_id && SWS_Core::is_staging( $post_id ) ) ? 0 : $post_id;
	}

	public static function no_guess_redirect( $url ) {
		if ( $url ) {
			$id = url_to_postid( $url );
			if ( $id && SWS_Core::is_staging( $id ) ) {
				return false;
			}
		}
		return $url;
	}

	public static function sitemap_core( $args ) {
		$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), SWS_Core::all_staging_ids() );
		return $args;
	}

	public static function merge_ids( $ids ) {
		return array_values( array_unique( array_merge( (array) $ids, SWS_Core::all_staging_ids() ) ) );
	}

	public static function sitemap_aioseo( $entries ) {
		$urls = array_map( 'get_permalink', SWS_Core::all_staging_ids() );
		if ( ! $urls || ! is_array( $entries ) ) {
			return $entries;
		}
		return array_values(
			array_filter(
				$entries,
				static function ( $e ) use ( $urls ) {
					return ! isset( $e['loc'] ) || ! in_array( $e['loc'], $urls, true );
				}
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Freigabe durch den Kunden
	 * ---------------------------------------------------------------- */

	public static function approval_bar() {
		if ( ! self::is_staging_request() || '1' !== get_option( 'sws_approval_bar', '1' ) ) {
			return;
		}
		$id = get_queried_object_id();
		if ( ! self::token_ok( $id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$done = isset( $_GET['sws_done'] ) ? sanitize_key( wp_unslash( $_GET['sws_done'] ) ) : '';
		?>
		<style>
			#sws-bar{position:fixed;left:0;right:0;bottom:0;z-index:2147483000;background:#1d2327;color:#fff;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:12px 16px;box-shadow:0 -2px 12px rgba(0,0,0,.25)}
			#sws-bar form{max-width:960px;margin:0 auto;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
			#sws-bar strong{margin-right:6px}
			#sws-bar textarea{flex:1 1 240px;min-height:38px;height:38px;padding:8px 10px;border:0;border-radius:6px;font:inherit;color:#1d2327;resize:vertical}
			#sws-bar button{border:0;border-radius:6px;padding:9px 16px;font:inherit;font-weight:600;cursor:pointer}
			#sws-bar .sws-ok{background:#00a32a;color:#fff}
			#sws-bar .sws-ch{background:#fff;color:#1d2327}
			#sws-bar .sws-hp{position:absolute;left:-9999px}
			body{padding-bottom:70px}
		</style>
		<div id="sws-bar" role="region" aria-label="<?php esc_attr_e( 'Freigabe', 'staging-switch' ); ?>">
			<?php if ( 'ok' === $done ) : ?>
				<strong><?php esc_html_e( 'Danke, Ihre Rückmeldung wurde übermittelt.', 'staging-switch' ); ?></strong>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="sws_approve">
					<input type="hidden" name="sws_id" value="<?php echo esc_attr( $id ); ?>">
					<input type="hidden" name="sws_token" value="<?php echo esc_attr( self::request_token() ); ?>">
					<input type="text" name="sws_website" value="" class="sws-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
					<strong><?php esc_html_e( 'Vorschau, noch nicht live.', 'staging-switch' ); ?></strong>
					<?php if ( 'err' === $done ) : ?>
						<span><?php esc_html_e( 'Bitte beschreiben Sie Ihre Änderungswünsche.', 'staging-switch' ); ?></span>
					<?php endif; ?>
					<textarea name="sws_comment" maxlength="2000" placeholder="<?php esc_attr_e( 'Anmerkung (optional)', 'staging-switch' ); ?>"></textarea>
					<button type="submit" name="sws_decision" value="approve" class="sws-ok"><?php esc_html_e( 'Freigeben', 'staging-switch' ); ?></button>
					<button type="submit" name="sws_decision" value="changes" class="sws-ch"><?php esc_html_e( 'Änderungen wünschen', 'staging-switch' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_approval() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Der geheime Token ersetzt den Nonce (anonyme Kunden).
		$id    = isset( $_POST['sws_id'] ) ? absint( $_POST['sws_id'] ) : 0;
		$token = isset( $_POST['sws_token'] ) ? sanitize_text_field( wp_unslash( $_POST['sws_token'] ) ) : '';
		if ( ! $id || ! SWS_Core::is_staging( $id ) || ! SWS_Core::token_valid( $id, $token ) ) {
			wp_die( esc_html__( 'Dieser Freigabe-Link ist ungültig oder abgelaufen.', 'staging-switch' ), '', array( 'response' => 403 ) );
		}
		$back = SWS_Core::preview_url( $id );
		if ( ! empty( $_POST['sws_website'] ) || get_transient( 'sws_rl_' . $id ) ) {
			wp_safe_redirect( $back );
			exit;
		}
		$decision = ( isset( $_POST['sws_decision'] ) && 'approve' === $_POST['sws_decision'] ) ? 'approve' : 'changes';
		$comment  = isset( $_POST['sws_comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sws_comment'] ) ) : '';
		// phpcs:enable
		$comment = function_exists( 'mb_substr' ) ? mb_substr( $comment, 0, 2000 ) : substr( $comment, 0, 2000 );

		if ( 'changes' === $decision && '' === $comment ) {
			wp_safe_redirect( add_query_arg( 'sws_done', 'err', $back ) );
			exit;
		}
		set_transient( 'sws_rl_' . $id, 1, 30 );

		$live = SWS_Core::live_id( $id );
		update_post_meta( $id, '_sws_decision', array( 'd' => $decision, 't' => time() ) );
		SWS_Core::log( $live, 'customer_' . $decision, $comment );

		$title   = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( get_the_title( $live ) ) ) );
		$to      = sanitize_email( (string) get_option( 'sws_notify_email', '' ) );
		$to      = $to ? $to : get_option( 'admin_email' );
		$subject = ( 'approve' === $decision ? '[Freigabe] ' : '[Änderungswunsch] ' ) . $title;
		$body    = ( 'approve' === $decision ? "Der Kunde hat das Staging freigegeben.\n" : "Der Kunde wünscht Änderungen.\n" )
			. "\nSeite: " . $title
			. "\nStaging bearbeiten: " . get_edit_post_link( $id, 'raw' )
			. "\nLive bearbeiten: " . get_edit_post_link( $live, 'raw' )
			. ( '' !== $comment ? "\n\nAnmerkung:\n" . $comment : '' )
			. ( 'approve' === $decision ? "\n\nLive geschaltet wird erst, wenn Sie im Editor auf „Staging live schalten“ klicken.\n" : "\n" );
		wp_mail( $to, $subject, $body );

		wp_safe_redirect( add_query_arg( 'sws_done', 'ok', $back ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Admin-Bar
	 * ---------------------------------------------------------------- */

	public static function admin_bar( $bar ) {
		if ( is_admin() || ! is_singular() ) {
			return;
		}
		$id = get_queried_object_id();
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		if ( SWS_Core::is_staging( $id ) ) {
			$live = SWS_Core::live_id( $id );
			$bar->add_node(
				array(
					'id'    => 'sws',
					'title' => esc_html__( 'Staging (noindex)', 'staging-switch' ),
					'href'  => get_permalink( $live ),
				)
			);
			$bar->add_node(
				array(
					'parent' => 'sws',
					'id'     => 'sws-live',
					'title'  => esc_html__( 'Zur Live-Seite', 'staging-switch' ),
					'href'   => get_permalink( $live ),
				)
			);
			if ( current_user_can( 'publish_post', $live ) ) {
				$bar->add_node(
					array(
						'parent' => 'sws',
						'id'     => 'sws-switch',
						'title'  => esc_html__( 'Staging live schalten', 'staging-switch' ),
						'href'   => SWS_Core::action_url( 'switch', $live ),
					)
				);
			}
		} elseif ( SWS_Core::staging_id( $id ) ) {
			$bar->add_node(
				array(
					'id'    => 'sws',
					'title' => esc_html__( 'Zum Staging', 'staging-switch' ),
					'href'  => get_permalink( SWS_Core::staging_id( $id ) ),
				)
			);
		}
	}
}
