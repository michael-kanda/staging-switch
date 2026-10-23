<?php
/**
 * Backend: Listen-Aktionen, Editor-Box, Einstellungen, Handler.
 *
 * @package StagingSwitch
 */

defined( 'ABSPATH' ) || exit;

final class SWS_Admin {

	const ACTIONS = array( 'create', 'switch', 'refresh', 'discard', 'token' );

	public static function init() {
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'display_post_states', array( __CLASS__, 'states' ), 10, 2 );
		add_filter( 'post_class', array( __CLASS__, 'row_class' ), 10, 3 );
		add_filter( 'the_posts', array( __CLASS__, 'group_in_list' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_sws_' . $action, array( __CLASS__, 'handle' ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Listen
	 * ---------------------------------------------------------------- */

	private static function switch_attrs() {
		return sprintf(
			'data-sws-confirm="1" data-sws-title="%s" data-sws-text="%s" data-sws-points="%s" data-sws-ok="%s"',
			esc_attr__( 'Staging live schalten?', 'staging-switch' ),
			esc_attr__( 'Der Inhalt des Staging wird auf die Live-URL übernommen. Die URL bleibt gleich. Ungespeicherte Änderungen vorher speichern.', 'staging-switch' ),
			esc_attr__( 'Die bisherige Live-Version wird zum neuen Staging|Staging bleibt noindex und nofollow|Jederzeit per Klick rückgängig', 'staging-switch' ),
			esc_attr__( 'Jetzt live schalten', 'staging-switch' )
		);
	}

	public static function row_actions( $actions, $post ) {
		if ( ! in_array( $post->post_type, SWS_Core::post_types(), true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		if ( SWS_Core::is_staging( $post->ID ) ) {
			$live = SWS_Core::live_id( $post->ID );
			if ( current_user_can( 'publish_post', $live ) ) {
				$actions['sws_switch'] = '<a href="' . esc_url( SWS_Core::action_url( 'switch', $live ) ) . '" ' . self::switch_attrs() . '>' . esc_html__( 'Live schalten', 'staging-switch' ) . '</a>';
			}
			$actions['sws_link'] = '<a href="#" data-sws-copy-text="' . esc_attr( SWS_Core::preview_url( $post->ID ) ) . '">' . esc_html__( 'Freigabe-Link kopieren', 'staging-switch' ) . '</a>';
		} else {
			$sid = SWS_Core::staging_id( $post->ID );
			if ( $sid ) {
				$actions['sws_goto'] = '<a href="' . esc_url( get_edit_post_link( $sid ) ) . '">' . esc_html__( 'Zum Staging', 'staging-switch' ) . '</a>';
			} elseif ( 'publish' === $post->post_status || 'draft' === $post->post_status || 'private' === $post->post_status ) {
				$actions['sws_create'] = '<a href="' . esc_url( SWS_Core::action_url( 'create', $post->ID ) ) . '">' . esc_html__( 'Staging erstellen', 'staging-switch' ) . '</a>';
			}
		}
		return $actions;
	}

	public static function states( $states, $post ) {
		if ( SWS_Core::is_staging( $post->ID ) ) {
			$states['sws'] = '<span class="sws-badge sws-badge-staging">' . esc_html__( 'STAGING', 'staging-switch' ) . '</span>';
		} elseif ( SWS_Core::staging_id( $post->ID ) ) {
			$states['sws'] = '<span class="sws-badge sws-badge-live">LIVE</span>';
		}
		return $states;
	}

	/** Beiträge: Staging direkt unter das Live-Original sortieren (Seiten machen das über die Hierarchie selbst). */
	public static function group_in_list( $posts, $query ) {
		global $pagenow;
		if ( 'edit.php' !== $pagenow || ! is_admin() || ! $query->is_main_query() || ! $posts ) {
			return $posts;
		}
		$ids = wp_list_pluck( $posts, 'ID' );
		$stg = array();
		foreach ( $posts as $p ) {
			if ( ! is_post_type_hierarchical( $p->post_type ) && SWS_Core::is_staging( $p->ID ) ) {
				$live = SWS_Core::live_id( $p->ID );
				if ( in_array( $live, $ids, true ) ) {
					$stg[ $live ] = $p;
				}
			}
		}
		if ( ! $stg ) {
			return $posts;
		}
		$out = array();
		foreach ( $posts as $p ) {
			if ( in_array( $p, $stg, true ) ) {
				continue;
			}
			$out[] = $p;
			if ( isset( $stg[ $p->ID ] ) ) {
				$out[] = $stg[ $p->ID ];
			}
		}
		return $out;
	}

	/** Zeilen in der Liste einfärben: Live grün, Staging gelb. */
	public static function row_class( $classes, $css_class, $post_id ) {
		if ( ! is_admin() ) {
			return $classes;
		}
		if ( SWS_Core::is_staging( $post_id ) ) {
			$classes[] = 'sws-row-staging';
			if ( ! is_post_type_hierarchical( get_post_type( $post_id ) ) ) {
				$classes[] = 'sws-indent';
			}
		} elseif ( SWS_Core::staging_id( $post_id ) ) {
			$classes[] = 'sws-row-live';
		}
		return $classes;
	}

	/* ------------------------------------------------------------------
	 * Editor-Box
	 * ---------------------------------------------------------------- */

	public static function add_box() {
		foreach ( SWS_Core::post_types() as $type ) {
			add_meta_box( 'sws_box', __( 'Staging', 'staging-switch' ), array( __CLASS__, 'render_box' ), $type, 'side', 'high' );
		}
	}

	public static function render_box( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Erst speichern, dann kann ein Staging erstellt werden.', 'staging-switch' ) . '</p>';
			return;
		}
		$is_stg = SWS_Core::is_staging( $post->ID );
		$live   = $is_stg ? SWS_Core::live_id( $post->ID ) : $post->ID;
		$sid    = $is_stg ? $post->ID : SWS_Core::staging_id( $post->ID );

		echo '<div class="sws-box">';

		if ( ! $sid ) {
			echo '<p>' . esc_html__( 'Von dieser Seite gibt es noch kein Staging.', 'staging-switch' ) . '</p>';
			echo '<a class="button button-primary" href="' . esc_url( SWS_Core::action_url( 'create', $post->ID ) ) . '">' . esc_html__( 'Staging erstellen', 'staging-switch' ) . '</a>';
			echo '</div>';
			return;
		}

		if ( $is_stg ) {
			echo '<p class="sws-note">' . esc_html(
				sprintf(
					/* translators: %s: Titel */
					__( 'Staging-Version von „%s“. Für Suchmaschinen unsichtbar (noindex, nofollow).', 'staging-switch' ),
					get_the_title( $live )
				)
			) . '</p>';
		} else {
			echo '<p><span class="sws-badge sws-badge-live">LIVE</span> ' . esc_html__( 'Diese Seite ist aktuell live.', 'staging-switch' ) . '</p>';
			echo '<p>' . esc_html__( 'Staging vorhanden:', 'staging-switch' ) . ' <a href="' . esc_url( get_edit_post_link( $sid ) ) . '">' . esc_html__( 'bearbeiten', 'staging-switch' ) . '</a></p>';
		}

		if ( current_user_can( 'publish_post', $live ) ) {
			echo '<p><a class="button button-primary sws-wide" href="' . esc_url( SWS_Core::action_url( 'switch', $live ) ) . '" ' . self::switch_attrs() . '>' . esc_html__( 'Staging live schalten', 'staging-switch' ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- attrs sind escaped.
		}

		// Freigabe-Link (gehört zur Staging-ID).
		$url     = SWS_Core::preview_url( $sid );
		$expires = (int) get_post_meta( $sid, SWS_Core::META_EXPIRES, true );
		echo '<p><label for="sws-link"><strong>' . esc_html__( 'Freigabe-Link für Kunden', 'staging-switch' ) . '</strong></label>';
		echo '<input type="text" id="sws-link" class="widefat" readonly value="' . esc_attr( $url ) . '" onfocus="this.select()">';
		echo '<button type="button" class="button sws-wide" data-sws-copy="#sws-link">' . esc_html__( 'Link kopieren', 'staging-switch' ) . '</button></p>';
		echo '<p class="description">';
		if ( $expires ) {
			if ( $expires < time() ) {
				echo esc_html__( 'Der Link ist abgelaufen.', 'staging-switch' );
			} else {
				/* translators: %s: Datum */
				echo esc_html( sprintf( __( 'Gültig bis %s.', 'staging-switch' ), wp_date( get_option( 'date_format' ), $expires ) ) );
			}
		} else {
			echo esc_html__( 'Ohne Ablaufdatum.', 'staging-switch' );
		}
		echo ' ' . esc_html__( 'Neuer Link (alter wird ungültig):', 'staging-switch' ) . ' ';
		foreach ( array(
			0  => __( 'ohne Ablauf', 'staging-switch' ),
			7  => __( '7 Tage', 'staging-switch' ),
			30 => __( '30 Tage', 'staging-switch' ),
		) as $days => $label ) {
			echo '<a href="' . esc_url( SWS_Core::action_url( 'token', $sid, array( 'days' => $days ) ) ) . '">' . esc_html( $label ) . '</a> ';
		}
		echo '</p>';

		$decision = get_post_meta( $sid, '_sws_decision', true );
		if ( is_array( $decision ) ) {
			echo '<p class="sws-note">' . esc_html(
				sprintf(
					/* translators: 1: Entscheidung, 2: Datum */
					__( 'Kunde: %1$s (%2$s)', 'staging-switch' ),
					'approve' === $decision['d'] ? __( 'freigegeben', 'staging-switch' ) : __( 'Änderungen gewünscht', 'staging-switch' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $decision['t'] )
				)
			) . '</p>';
		}

		echo '<p>';
		echo '<a class="button sws-wide" href="' . esc_url( SWS_Core::action_url( 'refresh', $live ) ) . '" data-sws-confirm="1" data-sws-title="' . esc_attr__( 'Neu von Live ziehen?', 'staging-switch' ) . '" data-sws-text="' . esc_attr__( 'Das Staging wird mit dem aktuellen Live-Stand überschrieben. Änderungen im Staging gehen verloren.', 'staging-switch' ) . '" data-sws-ok="' . esc_attr__( 'Überschreiben', 'staging-switch' ) . '" data-sws-danger="1">' . esc_html__( 'Neu von Live ziehen', 'staging-switch' ) . '</a> ';
		echo '<a class="button sws-wide sws-danger" href="' . esc_url( SWS_Core::action_url( 'discard', $live ) ) . '" data-sws-confirm="1" data-sws-title="' . esc_attr__( 'Staging verwerfen?', 'staging-switch' ) . '" data-sws-text="' . esc_attr__( 'Das Staging wird endgültig gelöscht. Die Live-Seite bleibt unverändert.', 'staging-switch' ) . '" data-sws-ok="' . esc_attr__( 'Verwerfen', 'staging-switch' ) . '" data-sws-danger="1">' . esc_html__( 'Staging verwerfen', 'staging-switch' ) . '</a>';
		echo '</p>';

		$log = get_post_meta( $live, SWS_Core::META_LOG, true );
		if ( is_array( $log ) && $log ) {
			$labels = array(
				'create'            => __( 'Staging erstellt', 'staging-switch' ),
				'switch'            => __( 'Live geschaltet', 'staging-switch' ),
				'refresh'           => __( 'Neu von Live gezogen', 'staging-switch' ),
				'discard'           => __( 'Staging verworfen', 'staging-switch' ),
				'customer_approve'  => __( 'Kunde: freigegeben', 'staging-switch' ),
				'customer_changes'  => __( 'Kunde: Änderungswunsch', 'staging-switch' ),
			);
			echo '<hr><p><strong>' . esc_html__( 'Verlauf', 'staging-switch' ) . '</strong></p><ul class="sws-log">';
			foreach ( array_slice( $log, 0, 5 ) as $entry ) {
				$user = ! empty( $entry['u'] ) ? get_userdata( (int) $entry['u'] ) : false;
				echo '<li>' . esc_html( wp_date( 'd.m. H:i', (int) $entry['t'] ) . ' · ' . ( $labels[ $entry['a'] ] ?? $entry['a'] ) . ( $user ? ' · ' . $user->display_name : '' ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/* ------------------------------------------------------------------
	 * Handler
	 * ---------------------------------------------------------------- */

	public static function handle() {
		$action = substr( (string) current_action(), strlen( 'admin_post_sws_' ) );
		$id     = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- unten geprüft.
		if ( ! in_array( $action, self::ACTIONS, true ) || ! $id || ! get_post( $id ) ) {
			wp_die( esc_html__( 'Ungültige Anfrage.', 'staging-switch' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( "sws_{$action}_{$id}" );

		$live = SWS_Core::is_staging( $id ) ? SWS_Core::live_id( $id ) : $id;
		$sid  = SWS_Core::staging_id( $live );

		$cap_ok = current_user_can( 'edit_post', $id );
		if ( 'switch' === $action ) {
			$cap_ok = current_user_can( 'publish_post', $live ) && current_user_can( 'edit_post', $live );
		}
		if ( ! $cap_ok ) {
			wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'staging-switch' ), '', array( 'response' => 403 ) );
		}

		$dest = wp_get_referer();
		switch ( $action ) {
			case 'create':
				$res  = SWS_Core::create( $id );
				$dest = is_wp_error( $res ) ? $dest : get_edit_post_link( $res, 'raw' );
				break;
			case 'switch':
				$res = SWS_Core::swap( $live );
				break;
			case 'refresh':
				$res = SWS_Core::refresh( $live );
				break;
			case 'discard':
				$res  = SWS_Core::discard( $live );
				$dest = get_edit_post_link( $live, 'raw' );
				break;
			default: // token.
				$days = isset( $_GET['days'] ) ? min( 365, absint( $_GET['days'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
				$res  = $sid ? SWS_Core::new_token( $sid, $days ) : new WP_Error( 'sws', __( 'Kein Staging vorhanden.', 'staging-switch' ) );
				break;
		}

		$dest = $dest ? $dest : get_edit_post_link( $id, 'raw' );
		if ( is_wp_error( $res ) ) {
			set_transient( 'sws_err_' . get_current_user_id(), $res->get_error_message(), 60 );
			$dest = add_query_arg( 'sws_notice', 'error', $dest );
		} else {
			$dest = add_query_arg( 'sws_notice', $action, $dest );
		}
		wp_safe_redirect( $dest );
		exit;
	}

	public static function notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['sws_notice'] ) ? sanitize_key( wp_unslash( $_GET['sws_notice'] ) ) : '';
		if ( '' === $code ) {
			return;
		}
		$map = array(
			'create'  => __( 'Staging erstellt. Du bearbeitest jetzt die Staging-Version.', 'staging-switch' ),
			'switch'  => __( 'Staging ist jetzt live. Die bisherige Live-Version ist das neue Staging.', 'staging-switch' ),
			'refresh' => __( 'Staging wurde neu vom Live-Stand gezogen.', 'staging-switch' ),
			'discard' => __( 'Staging wurde verworfen.', 'staging-switch' ),
			'token'   => __( 'Neuer Freigabe-Link erstellt. Der alte Link ist ungültig.', 'staging-switch' ),
		);
		if ( 'error' === $code ) {
			$msg = get_transient( 'sws_err_' . get_current_user_id() );
			delete_transient( 'sws_err_' . get_current_user_id() );
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $msg ? $msg : __( 'Aktion fehlgeschlagen.', 'staging-switch' ) ) );
		} elseif ( isset( $map[ $code ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $map[ $code ] ) );
		}
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'edit.php', 'post.php', 'post-new.php', 'settings_page_staging-switch' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'sws-admin', SWS_URL . 'assets/admin.css', array(), SWS_VERSION );
		wp_enqueue_script( 'sws-admin', SWS_URL . 'assets/admin.js', array(), SWS_VERSION, true );
		wp_localize_script(
			'sws-admin',
			'swsL10n',
			array(
				'cancel' => __( 'Abbrechen', 'staging-switch' ),
				'copied' => __( 'Kopiert', 'staging-switch' ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Einstellungen
	 * ---------------------------------------------------------------- */

	public static function menu() {
		add_options_page( __( 'Staging Switch', 'staging-switch' ), __( 'Staging Switch', 'staging-switch' ), 'manage_options', 'staging-switch', array( __CLASS__, 'render_settings' ) );
	}

	public static function register_settings() {
		register_setting(
			'sws_settings',
			'sws_post_types',
			array(
				'type'              => 'array',
				'sanitize_callback' => static function ( $v ) {
					return array_values( array_intersect( (array) $v, get_post_types( array( 'public' => true ) ) ) );
				},
			)
		);
		register_setting( 'sws_settings', 'sws_notify_email', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ) );
		register_setting( 'sws_settings', 'sws_default_expiry', array( 'type' => 'integer', 'sanitize_callback' => 'absint' ) );
		register_setting(
			'sws_settings',
			'sws_approval_bar',
			array(
				'type'              => 'string',
				'sanitize_callback' => static function ( $v ) {
					return $v ? '1' : '0';
				},
			)
		);
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$active = SWS_Core::post_types();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Staging Switch', 'staging-switch' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'sws_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Inhaltstypen mit Staging', 'staging-switch' ); ?></th>
						<td>
							<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) : ?>
								<?php if ( 'attachment' === $pt->name ) { continue; } ?>
								<label style="display:block"><input type="checkbox" name="sws_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $active, true ) ); ?>> <?php echo esc_html( $pt->labels->name ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sws_notify_email"><?php esc_html_e( 'Benachrichtigung bei Kundenfreigabe', 'staging-switch' ); ?></label></th>
						<td><input type="email" class="regular-text" id="sws_notify_email" name="sws_notify_email" value="<?php echo esc_attr( get_option( 'sws_notify_email', '' ) ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sws_default_expiry"><?php esc_html_e( 'Freigabe-Link gültig (Tage)', 'staging-switch' ); ?></label></th>
						<td><input type="number" min="0" class="small-text" id="sws_default_expiry" name="sws_default_expiry" value="<?php echo esc_attr( (int) get_option( 'sws_default_expiry', 0 ) ); ?>"> <span class="description"><?php esc_html_e( '0 = ohne Ablauf', 'staging-switch' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Freigabe-Leiste', 'staging-switch' ); ?></th>
						<td><label><input type="checkbox" name="sws_approval_bar" value="1" <?php checked( '1', get_option( 'sws_approval_bar', '1' ) ); ?>> <?php esc_html_e( 'Kunden sehen unten „Freigeben“ und „Änderungen wünschen“', 'staging-switch' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
