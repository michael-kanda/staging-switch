<?php
/**
 * Kernlogik: Staging anlegen, tauschen, neu ziehen, verwerfen.
 *
 * @package StagingSwitch
 */

defined( 'ABSPATH' ) || exit;

final class SWS_Core {

	const META_LIVE    = '_sws_live_id';
	const META_STAGING = '_sws_staging_id';
	const META_TOKEN   = '_sws_token';
	const META_EXPIRES = '_sws_token_expires';
	const META_LOG     = '_sws_log';

	/** @var int[]|null */
	private static $ids = null;

	/** @var bool */
	private static $busy = false;

	public static function init() {
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
	}

	/* ------------------------------------------------------------------
	 * Zustand
	 * ---------------------------------------------------------------- */

	public static function is_staging( $post_id ) {
		return (int) get_post_meta( $post_id, self::META_LIVE, true ) > 0;
	}

	public static function live_id( $staging_id ) {
		return (int) get_post_meta( $staging_id, self::META_LIVE, true );
	}

	public static function staging_id( $live_id ) {
		$sid = (int) get_post_meta( $live_id, self::META_STAGING, true );
		return ( $sid && get_post_status( $sid ) ) ? $sid : 0;
	}

	/**
	 * IDs aller Staging-Posts (pro Request gecacht).
	 *
	 * @param bool $refresh Cache neu aufbauen.
	 * @return int[]
	 */
	public static function all_staging_ids( $refresh = false ) {
		global $wpdb;
		if ( null === self::$ids || $refresh ) {
			self::$ids = array_map(
				'intval',
				$wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_LIVE ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			);
		}
		return self::$ids;
	}

	public static function post_types() {
		$types = get_option( 'sws_post_types', null );
		if ( ! is_array( $types ) ) {
			$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		}
		return (array) apply_filters( 'sws_post_types', $types );
	}

	public static function action_url( $action, $post_id, $args = array() ) {
		$url = add_query_arg(
			array_merge(
				array(
					'action' => 'sws_' . $action,
					'post'   => (int) $post_id,
				),
				$args
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, "sws_{$action}_{$post_id}" );
	}

	/**
	 * Meta-Keys, die NICHT mit dem Inhalt wandern (Robots/Canonical gehören zur Rolle, nicht zum Inhalt).
	 *
	 * @return string[]
	 */
	public static function excluded_keys() {
		return (array) apply_filters(
			'sws_excluded_meta_keys',
			array(
				'_edit_lock',
				'_edit_last',
				'_wp_old_slug',
				'_wp_desired_post_slug',
				'_wp_trash_meta_status',
				'_wp_trash_meta_time',
				self::META_LIVE,
				self::META_STAGING,
				self::META_TOKEN,
				self::META_EXPIRES,
				self::META_LOG,
				'_sws_decision',
				'_yoast_wpseo_meta-robots-noindex',
				'_yoast_wpseo_meta-robots-nofollow',
				'_yoast_wpseo_meta-robots-adv',
				'_yoast_wpseo_canonical',
				'rank_math_robots',
				'rank_math_canonical_url',
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Aktionen
	 * ---------------------------------------------------------------- */

	/**
	 * Staging-Kopie anlegen.
	 *
	 * @return int|WP_Error Staging-ID.
	 */
	public static function create( $live_id ) {
		global $wpdb;
		$live = get_post( $live_id );
		if ( ! $live || in_array( $live->post_status, array( 'auto-draft', 'trash' ), true ) ) {
			return new WP_Error( 'sws', __( 'Beitrag nicht gefunden.', 'staging-switch' ) );
		}
		if ( ! in_array( $live->post_type, self::post_types(), true ) ) {
			return new WP_Error( 'sws', __( 'Dieser Inhaltstyp ist für Staging nicht aktiviert.', 'staging-switch' ) );
		}
		if ( self::is_staging( $live_id ) || self::staging_id( $live_id ) ) {
			return new WP_Error( 'sws', __( 'Es existiert bereits ein Staging.', 'staging-switch' ) );
		}

		$parent = is_post_type_hierarchical( $live->post_type ) ? (int) $live->ID : 0;
		$slug   = wp_unique_post_slug( $live->post_name . '-staging', 0, 'publish', $live->post_type, $parent );

		$new_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'      => $live->post_type,
					'post_status'    => 'draft',
					'post_title'     => $live->post_title,
					'post_name'      => $slug,
					'post_parent'    => $parent,
					'post_author'    => get_current_user_id() ? get_current_user_id() : $live->post_author,
					'menu_order'     => $live->menu_order,
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				)
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// Direkt auf "publish" setzen: so feuern keine Veröffentlichungs-Hooks (Newsletter, Social-Autoposter, Menü-Autoadd).
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->posts,
			array(
				'post_status'   => 'publish',
				'post_date'     => current_time( 'mysql' ),
				'post_date_gmt' => current_time( 'mysql', true ),
			),
			array( 'ID' => $new_id )
		);

		update_post_meta( $new_id, self::META_LIVE, $live_id );
		update_post_meta( $live_id, self::META_STAGING, $new_id );
		self::new_token( $new_id );
		self::mark_noindex( $new_id );
		self::copy_content( $live_id, $new_id );
		self::all_staging_ids( true );
		self::log( $live_id, 'create' );

		do_action( 'sws_created', $live_id, $new_id );
		return $new_id;
	}

	/**
	 * Staging <-> Live tauschen. Live-ID und Live-URL bleiben unverändert.
	 *
	 * @return true|WP_Error
	 */
	public static function swap( $live_id ) {
		global $wpdb;
		$sid = self::staging_id( $live_id );
		if ( ! $sid ) {
			return new WP_Error( 'sws', __( 'Kein Staging vorhanden.', 'staging-switch' ) );
		}
		$lock = 'sws_lock_' . $live_id;
		if ( get_transient( $lock ) ) {
			return new WP_Error( 'sws', __( 'Ein Switch läuft bereits. Bitte kurz warten.', 'staging-switch' ) );
		}
		set_transient( $lock, 1, 60 );

		// Snapshot beider Stände (Revisionen), damit nichts verloren geht.
		wp_save_post_revision( $live_id );
		wp_save_post_revision( $sid );

		$live = get_post( $live_id );
		$stg  = get_post( $sid );
		$now  = array(
			'post_modified'     => current_time( 'mysql' ),
			'post_modified_gmt' => current_time( 'mysql', true ),
		);

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$ok = true;
		foreach ( array(
			array( $live_id, $stg ),
			array( $sid, $live ),
		) as $pair ) {
			$res = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->posts,
				array_merge( self::content_fields( $pair[1] ), $now ),
				array( 'ID' => $pair[0] )
			);
			if ( false === $res ) {
				$ok = false;
			}
		}

		// Meta tauschen, indem die Zeilen den Besitzer wechseln (kein Re-Serialisieren, Builder-JSON bleibt byte-genau).
		$keys = self::excluded_keys();
		$ph   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$ids  = array();
		foreach ( array( $live_id, $sid ) as $pid ) {
			$ids[ $pid ] = array_map(
				'intval',
				$wpdb->get_col( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key NOT IN ($ph)", array_merge( array( $pid ), $keys ) ) ) // phpcs:ignore WordPress.DB
			);
		}
		foreach ( array(
			$live_id => $sid,
			$sid     => $live_id,
		) as $from => $to ) {
			if ( $ids[ $from ] ) {
				$res = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET post_id = %d WHERE meta_id IN (" . implode( ',', $ids[ $from ] ) . ')', $to ) ); // phpcs:ignore WordPress.DB
				if ( false === $res ) {
					$ok = false;
				}
			}
		}

		if ( ! $ok ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			delete_transient( $lock );
			return new WP_Error( 'sws', __( 'Datenbankfehler beim Tauschen. Es wurde nichts verändert.', 'staging-switch' ) );
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		self::swap_terms( $live_id, $sid, $live->post_type );
		self::aioseo_swap( $live_id, $sid );
		self::after_change( array( $live_id, $sid ) );

		wp_save_post_revision( $live_id );
		wp_save_post_revision( $sid );
		self::log( $live_id, 'switch' );
		delete_transient( $lock );

		do_action( 'sws_switched', $live_id, $sid );
		return true;
	}

	/**
	 * Staging mit dem aktuellen Live-Stand überschreiben.
	 */
	public static function refresh( $live_id ) {
		$sid = self::staging_id( $live_id );
		if ( ! $sid ) {
			return new WP_Error( 'sws', __( 'Kein Staging vorhanden.', 'staging-switch' ) );
		}
		wp_save_post_revision( $sid );
		self::copy_content( $live_id, $sid );
		self::log( $live_id, 'refresh' );
		return true;
	}

	/**
	 * Staging endgültig löschen.
	 */
	public static function discard( $live_id ) {
		$sid = self::staging_id( $live_id );
		if ( ! $sid ) {
			return new WP_Error( 'sws', __( 'Kein Staging vorhanden.', 'staging-switch' ) );
		}
		self::log( $live_id, 'discard' );
		self::unlink( $live_id, $sid );
		wp_delete_post( $sid, true );
		return true;
	}

	/* ------------------------------------------------------------------
	 * Freigabe-Token
	 * ---------------------------------------------------------------- */

	public static function token( $staging_id ) {
		$token = get_post_meta( $staging_id, self::META_TOKEN, true );
		return $token ? $token : self::new_token( $staging_id );
	}

	public static function new_token( $staging_id, $days = null ) {
		if ( null === $days ) {
			$days = (int) get_option( 'sws_default_expiry', 0 );
		}
		$token = wp_generate_password( 32, false, false );
		update_post_meta( $staging_id, self::META_TOKEN, $token );
		update_post_meta( $staging_id, self::META_EXPIRES, $days > 0 ? time() + $days * DAY_IN_SECONDS : 0 );
		return $token;
	}

	public static function token_valid( $staging_id, $given ) {
		if ( ! is_string( $given ) || '' === $given ) {
			return false;
		}
		$token = get_post_meta( $staging_id, self::META_TOKEN, true );
		if ( ! $token || ! hash_equals( (string) $token, $given ) ) {
			return false;
		}
		$expires = (int) get_post_meta( $staging_id, self::META_EXPIRES, true );
		return 0 === $expires || $expires > time();
	}

	public static function preview_url( $staging_id ) {
		return add_query_arg( 'sws_token', self::token( $staging_id ), get_permalink( $staging_id ) );
	}

	/* ------------------------------------------------------------------
	 * Log
	 * ---------------------------------------------------------------- */

	public static function log( $live_id, $action, $note = '' ) {
		$log = get_post_meta( $live_id, self::META_LOG, true );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				't' => time(),
				'u' => get_current_user_id(),
				'a' => $action,
				'n' => $note,
			)
		);
		update_post_meta( $live_id, self::META_LOG, array_slice( $log, 0, 20 ) );
	}

	/* ------------------------------------------------------------------
	 * Intern
	 * ---------------------------------------------------------------- */

	private static function content_fields( $post ) {
		return array(
			'post_title'            => $post->post_title,
			'post_content'          => $post->post_content,
			'post_excerpt'          => $post->post_excerpt,
			'post_content_filtered' => $post->post_content_filtered,
		);
	}

	private static function mark_noindex( $staging_id ) {
		update_post_meta( $staging_id, '_yoast_wpseo_meta-robots-noindex', '1' );
		update_post_meta( $staging_id, '_yoast_wpseo_meta-robots-nofollow', '1' );
		update_post_meta( $staging_id, 'rank_math_robots', array( 'noindex', 'nofollow' ) );
	}

	private static function copy_content( $from, $to ) {
		global $wpdb;
		$src = get_post( $from );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->posts,
			array_merge(
				self::content_fields( $src ),
				array(
					'post_modified'     => current_time( 'mysql' ),
					'post_modified_gmt' => current_time( 'mysql', true ),
				)
			),
			array( 'ID' => $to )
		);

		$keys = self::excluded_keys();
		$ph   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key NOT IN ($ph)", array_merge( array( $to ), $keys ) ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) SELECT %d, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key NOT IN ($ph)", array_merge( array( $to, $from ), $keys ) ) ); // phpcs:ignore WordPress.DB

		foreach ( get_object_taxonomies( $src->post_type ) as $tax ) {
			$terms = wp_get_object_terms( $from, $tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $to, array_map( 'intval', $terms ), $tax );
			}
		}

		self::aioseo_copy( $from, $to );
		self::after_change( array( $to ) );
	}

	private static function swap_terms( $a, $b, $post_type ) {
		foreach ( get_object_taxonomies( $post_type ) as $tax ) {
			$ta = wp_get_object_terms( $a, $tax, array( 'fields' => 'ids' ) );
			$tb = wp_get_object_terms( $b, $tax, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $ta ) || is_wp_error( $tb ) ) {
				continue;
			}
			wp_set_object_terms( $a, array_map( 'intval', $tb ), $tax );
			wp_set_object_terms( $b, array_map( 'intval', $ta ), $tax );
		}
	}

	private static function table_exists( $table ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table; // phpcs:ignore WordPress.DB
	}

	private static function aioseo_row( $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		if ( ! self::table_exists( $table ) ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', $table, $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** AIOSEO speichert in eigener Tabelle; Robots/Canonical bleiben an der Rolle. */
	private static function aioseo_data( $row ) {
		foreach ( array_keys( $row ) as $col ) {
			if ( in_array( $col, array( 'id', 'post_id', 'created', 'updated', 'canonical_url' ), true ) || 0 === strpos( $col, 'robots_' ) ) {
				unset( $row[ $col ] );
			}
		}
		return $row;
	}

	private static function aioseo_copy( $from, $to ) {
		global $wpdb;
		$src = self::aioseo_row( $from );
		if ( ! $src ) {
			return;
		}
		$table = $wpdb->prefix . 'aioseo_posts';
		$data  = self::aioseo_data( $src );
		if ( self::aioseo_row( $to ) ) {
			$wpdb->update( $table, $data, array( 'post_id' => $to ) ); // phpcs:ignore WordPress.DB
		} else {
			$wpdb->insert( $table, array_merge( $data, array( 'post_id' => $to, 'created' => current_time( 'mysql', true ), 'updated' => current_time( 'mysql', true ) ) ) ); // phpcs:ignore WordPress.DB
		}
	}

	private static function aioseo_swap( $a, $b ) {
		global $wpdb;
		$ra = self::aioseo_row( $a );
		$rb = self::aioseo_row( $b );
		if ( ! $ra || ! $rb ) {
			return;
		}
		$table = $wpdb->prefix . 'aioseo_posts';
		$wpdb->update( $table, self::aioseo_data( $rb ), array( 'post_id' => $a ) ); // phpcs:ignore WordPress.DB
		$wpdb->update( $table, self::aioseo_data( $ra ), array( 'post_id' => $b ) ); // phpcs:ignore WordPress.DB
	}

	/** Caches leeren, damit sofort der neue Stand ausgeliefert wird. */
	private static function after_change( array $ids ) {
		global $wpdb;
		foreach ( $ids as $id ) {
			clean_post_cache( $id );
			do_action( 'litespeed_purge_post', $id );
			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $id );
			}
			if ( function_exists( 'w3tc_flush_post' ) ) {
				w3tc_flush_post( $id );
			}
		}

		// Yoast liest Meta aus Indexables: veraltete Zeilen löschen, Yoast baut sie bei Bedarf neu.
		$table = $wpdb->prefix . 'yoast_indexable';
		if ( self::table_exists( $table ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE object_type = 'post' AND object_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')', $table ) ); // phpcs:ignore WordPress.DB
		}

		// Elementor cached CSS pro Post-ID.
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	public static function unlink( $live_id, $staging_id ) {
		delete_post_meta( $live_id, self::META_STAGING );
		delete_post_meta( $staging_id, self::META_LIVE );
		self::all_staging_ids( true );
	}

	/* ------------------------------------------------------------------
	 * Lebenszyklus
	 * ---------------------------------------------------------------- */

	public static function on_trash( $post_id ) {
		if ( self::$busy ) {
			return;
		}
		if ( self::is_staging( $post_id ) ) {
			self::unlink( self::live_id( $post_id ), $post_id );
			return;
		}
		$sid = self::staging_id( $post_id );
		if ( $sid ) {
			self::$busy = true;
			self::unlink( $post_id, $sid );
			wp_trash_post( $sid );
			self::$busy = false;
		}
	}

	public static function on_delete( $post_id ) {
		if ( self::$busy ) {
			return;
		}
		if ( self::is_staging( $post_id ) ) {
			self::unlink( self::live_id( $post_id ), $post_id );
			return;
		}
		$sid = (int) get_post_meta( $post_id, self::META_STAGING, true );
		if ( $sid ) {
			self::$busy = true;
			self::unlink( $post_id, $sid );
			wp_delete_post( $sid, true );
			self::$busy = false;
		}
	}

	/** Ohne Plugin gäbe es keinen Schutz mehr: Staging bei Deaktivierung offline nehmen. */
	public static function deactivate() {
		global $wpdb;
		$ids = self::all_staging_ids( true );
		if ( $ids ) {
			$list = implode( ',', array_map( 'intval', $ids ) );
			$live = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ($list) AND post_status = 'publish'" ); // phpcs:ignore WordPress.DB
			if ( $live ) {
				$wpdb->query( "UPDATE {$wpdb->posts} SET post_status = 'draft' WHERE ID IN (" . implode( ',', array_map( 'intval', $live ) ) . ')' ); // phpcs:ignore WordPress.DB
				update_option( 'sws_deactivated_ids', array_map( 'intval', $live ), false );
				array_map( 'clean_post_cache', $live );
			}
		}
	}

	public static function activate() {
		global $wpdb;
		$ids = get_option( 'sws_deactivated_ids', array() );
		if ( is_array( $ids ) && $ids ) {
			$wpdb->query( "UPDATE {$wpdb->posts} SET post_status = 'publish' WHERE post_status = 'draft' AND ID IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore WordPress.DB
			array_map( 'clean_post_cache', $ids );
		}
		delete_option( 'sws_deactivated_ids' );
	}
}
