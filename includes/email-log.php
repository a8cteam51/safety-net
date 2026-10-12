<?php
/**
 * Email log: records the emails stop_emails() blocks or lets through, and shows them on the Email log tab of Tools > Safety Net.
 *
 * @package SafetyNet
 */

namespace SafetyNet\EmailLog;

use function SafetyNet\Utilities\get_admin_emails;

const LOG_OPTION        = 'safety_net_email_log_toggle';
const CLEANUP_OPTION    = 'safety_net_email_log_cleanup_toggle';
const DB_VERSION_OPTION = 'safety_net_email_log_db_version';
const DB_VERSION        = '1';
const CLEANUP_HOOK      = 'safety_net_email_log_cleanup';
const MAX_AGE           = '-6 months';
const SETTINGS_GROUP    = 'safety-net-email-log';
const SETTINGS_PAGE     = 'safety_net_email_log';
const PER_PAGE          = 20;
const TEXT_BYTES        = 65535;

add_action( 'init', __NAMESPACE__ . '\schedule_cleanup' );
add_action( 'init', __NAMESPACE__ . '\add_admin_hooks' );
add_action( CLEANUP_HOOK, __NAMESPACE__ . '\run_cleanup' );
add_filter( 'wpmu_drop_tables', __NAMESPACE__ . '\drop_with_site', 10, 2 );

/**
 * Whether emails are logged. On unless turned off on the Email log tab.
 *
 * @return bool
 */
function is_logging_enabled(): bool {
	return 'on' === get_option( LOG_OPTION, 'on' );
}

/**
 * Whether entries older than six months are deleted. On unless turned off on the Email log tab.
 *
 * @return bool
 */
function is_cleanup_enabled(): bool {
	return 'on' === get_option( CLEANUP_OPTION, 'on' );
}

/**
 * Returns the name of the current site's email log table.
 *
 * @return string
 */
function table_name(): string {
	global $wpdb;

	return $wpdb->prefix . 'safety_net_email_log';
}

/**
 * Whether the current site's email log table was created.
 *
 * @return bool
 */
function table_exists(): bool {
	return (bool) get_option( DB_VERSION_OPTION );
}

/**
 * Whether the current site's email log table is in the database. Forgets a table that is gone, so the next email creates it again.
 *
 * @return bool
 */
function verify_table(): bool {
	global $wpdb;

	$table = table_name();
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return true;
	}

	if ( table_exists() ) {
		delete_option( DB_VERSION_OPTION );
	}

	return false;
}

/**
 * Adds a site's email log table to the tables WordPress drops when the site is deleted.
 *
 * @param string[] $tables  The tables to drop.
 * @param int      $site_id The deleted site's ID.
 *
 * @return string[]
 */
function drop_with_site( $tables, $site_id ): array {
	global $wpdb;

	$tables                         = (array) $tables;
	$tables['safety_net_email_log'] = $wpdb->get_blog_prefix( (int) $site_id ) . 'safety_net_email_log';

	return $tables;
}

/**
 * Creates the current site's email log table if it does not exist yet.
 *
 * @return bool Whether the table exists.
 */
function create_table(): bool {
	global $wpdb;
	static $failed = array();

	if ( table_exists() ) {
		return true;
	}

	$table = table_name();
	if ( isset( $failed[ $table ] ) ) {
		return false;
	}

	// Not dbDelta(): it needs wp-admin/includes/upgrade.php, and the first email can come before init on a front-end request.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from $wpdb.
	$created = $wpdb->query(
		"CREATE TABLE IF NOT EXISTS {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			logged_at datetime NOT NULL,
			status varchar(20) NOT NULL default '',
			recipients text NOT NULL,
			subject text NOT NULL,
			message longtext NOT NULL,
			headers text NOT NULL,
			from_address varchar(255) NOT NULL default '',
			content_type varchar(100) NOT NULL default '',
			attachments text NOT NULL,
			PRIMARY KEY  (id),
			KEY logged_at (logged_at)
		) {$wpdb->get_charset_collate()}"
	);
	// phpcs:enable

	if ( false === $created ) {
		$failed[ $table ] = true;
		return false;
	}

	update_option( DB_VERSION_OPTION, DB_VERSION );

	return true;
}

/**
 * Whether the next email stop_emails() sees is a copy of a logged email for the current administrator, or sets it.
 *
 * @param bool|null $sending True just before the copy is sent, false once stop_emails() has seen it; null only reads it.
 *
 * @return bool
 */
function sending_copy( ?bool $sending = null ): bool {
	static $current = false;

	if ( null !== $sending ) {
		$current = $sending;
	}

	return $current;
}

/**
 * Records an email stop_emails() decided on. Never throws, so it cannot change that decision.
 *
 * @param string $status 'blocked', 'blocked_recipients' or 'allowed'.
 * @param array  $args   The wp_mail() arguments.
 *
 * @return void
 */
function log_email( string $status, $args ): void {
	global $wpdb;

	if ( ! is_array( $args ) || ! is_logging_enabled() ) {
		return;
	}

	try {
		if ( ! create_table() ) {
			return;
		}

		$sender = get_sender( $args['headers'] ?? '' );
		$entry  = array(
			'logged_at'    => current_time( 'mysql', true ),
			'status'       => $status,
			'recipients'   => to_column( implode( ', ', to_lines( $args['to'] ?? '', ',' ) ), TEXT_BYTES ),
			'subject'      => to_column( to_text( $args['subject'] ?? '' ), TEXT_BYTES ),
			'message'      => to_column( redact_keys( to_text( $args['message'] ?? '' ) ) ),
			'headers'      => to_column( implode( "\n", to_lines( $args['headers'] ?? '', "\n" ) ), TEXT_BYTES ),
			'from_address' => mb_substr( to_column( $sender['from'] ), 0, 255 ),
			'content_type' => mb_substr( to_column( $sender['content_type'] ), 0, 100 ),
			'attachments'  => to_column( implode( "\n", get_attachment_names( $args['attachments'] ?? array() ) ), TEXT_BYTES ),
		);

		// The table can be gone although its option exists (e.g. only the options table was copied over); that is handled below, not reported by wpdb.
		$suppressed = $wpdb->suppress_errors( true );
		try {
			$inserted = $wpdb->insert( table_name(), $entry, '%s' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}

		if ( false === $inserted ) {
			error_log( 'Safety Net: the email log could not record an email: ' . $wpdb->last_error ); // phpcs:ignore -- Logging is okay here.
			verify_table();
		}
	} catch ( \Throwable $e ) {
		error_log( 'Safety Net: the email log could not record an email: ' . $e->getMessage() ); // phpcs:ignore -- Logging is okay here.
	}
}

/**
 * Returns a scalar as a string, and anything else as an empty string.
 *
 * @param mixed $value The value.
 *
 * @return string
 */
function to_text( $value ): string {
	return is_scalar( $value ) ? (string) $value : '';
}

/**
 * Makes a value fit its column, since wpdb refuses the whole row when a value has invalid UTF-8 or is too long.
 *
 * @param string $value The value.
 * @param int    $bytes The column's size in bytes, or 0 for no limit.
 *
 * @return string
 */
function to_column( string $value, int $bytes = 0 ): string {
	$value = (string) wp_check_invalid_utf8( $value, true );

	return $bytes ? mb_strcut( $value, 0, $bytes, 'UTF-8' ) : $value;
}

/**
 * Splits a wp_mail() list, given as a string or an array, into its non-empty trimmed entries.
 *
 * @param mixed  $value     The list.
 * @param string $separator What separates the entries of a string list.
 *
 * @return string[]
 */
function to_lines( $value, string $separator ): array {
	if ( ! is_array( $value ) ) {
		$value = explode( $separator, str_replace( "\r\n", "\n", to_text( $value ) ) );
	}

	$lines = array();
	foreach ( $value as $line ) {
		$line = trim( to_text( $line ) );
		if ( '' !== $line ) {
			$lines[] = $line;
		}
	}

	return $lines;
}

/**
 * Returns the file names of an email's attachments, never their paths.
 *
 * @param mixed $attachments Newline-separated paths, or an array of paths, keyed by file name where one is given.
 *
 * @return string[]
 */
function get_attachment_names( $attachments ): array {
	if ( ! is_array( $attachments ) ) {
		$attachments = explode( "\n", str_replace( "\r\n", "\n", to_text( $attachments ) ) );
	}

	$names = array();
	foreach ( $attachments as $name => $path ) {
		$path = trim( to_text( $path ) );
		if ( '' === $path ) {
			continue;
		}

		$name = trim( is_string( $name ) ? $name : wp_basename( $path ) );
		if ( '' !== $name ) {
			$names[] = $name;
		}
	}

	return $names;
}

/**
 * Returns the sender and content type wp_mail() would use, while senders such as WooCommerce still have their wp_mail_* filters added.
 *
 * @param mixed $headers The wp_mail() headers, a string or an array.
 *
 * @return array{from: string, content_type: string}
 */
function get_sender( $headers ): array {
	$from_name    = null;
	$from_email   = null;
	$content_type = null;
	$charset      = null;

	if ( ! empty( $headers ) ) {
		foreach ( is_array( $headers ) ? $headers : explode( "\n", str_replace( "\r\n", "\n", to_text( $headers ) ) ) as $header ) {
			$header = to_text( $header );
			if ( ! str_contains( $header, ':' ) ) {
				continue;
			}

			list( $name, $content ) = explode( ':', trim( $header ), 2 );
			$content                = trim( $content );

			switch ( strtolower( trim( $name ) ) ) {
				case 'from':
					$bracket_pos = strpos( $content, '<' );
					if ( false !== $bracket_pos ) {
						if ( $bracket_pos > 0 ) {
							$from_name = trim( str_replace( '"', '', substr( $content, 0, $bracket_pos ) ) );
						}
						$from_email = trim( str_replace( '>', '', substr( $content, $bracket_pos + 1 ) ) );
					} elseif ( '' !== $content ) {
						$from_email = $content;
					}
					break;
				case 'content-type':
					if ( str_contains( $content, ';' ) ) {
						list( $type, $charset_content ) = explode( ';', $content );
						$content_type                   = trim( $type );
						if ( false !== stripos( $charset_content, 'charset=' ) ) {
							$charset = trim( str_replace( array( 'charset=', '"' ), '', $charset_content ) );
						} elseif ( false !== stripos( $charset_content, 'boundary=' ) ) {
							$boundary = trim( str_replace( array( 'BOUNDARY=', 'boundary=', '"' ), '', $charset_content ) );
							$charset  = '';
							if ( preg_match( '~^multipart/(\S+)~', $content_type, $matches ) ) {
								$content_type = 'multipart/' . strtolower( $matches[1] ) . '; boundary="' . $boundary . '"';
							}
						}
					} elseif ( '' !== $content ) {
						$content_type = $content;
					}
					break;
			}
		}
	}

	if ( null === $from_email ) {
		$sitename   = wp_parse_url( network_home_url(), PHP_URL_HOST );
		$from_email = 'wordpress@';
		if ( is_string( $sitename ) ) {
			$from_email .= str_starts_with( $sitename, 'www.' ) ? substr( $sitename, 4 ) : $sitename;
		}
	}

	$from_email   = to_text( apply_filters( 'wp_mail_from', $from_email ) );
	$from_name    = to_text( apply_filters( 'wp_mail_from_name', $from_name ?? 'WordPress' ) );
	$content_type = to_text( apply_filters( 'wp_mail_content_type', $content_type ?? 'text/plain' ) );
	$charset      = to_text( apply_filters( 'wp_mail_charset', $charset ?? get_bloginfo( 'charset' ) ) );

	if ( '' !== $charset && false === stripos( $content_type, 'charset=' ) ) {
		$content_type .= '; charset=' . $charset;
	}

	return array(
		'from'         => '' !== $from_name ? "{$from_name} <{$from_email}>" : $from_email,
		'content_type' => $content_type,
	);
}

/**
 * Replaces the value of every key= and confirm_key= URL parameter, such as password reset keys, in plain text and in HTML.
 *
 * @param string $message The email's message.
 *
 * @return string
 */
function redact_keys( string $message ): string {
	return (string) preg_replace( '/([?&](?:amp;|#0?38;)?(?:confirm_)?key=)[^&\s"\'<>]+/i', '$1[redacted]', $message );
}

/**
 * Returns the charset of an entry whose message wp_mail() sends as HTML, or null when the message is not HTML.
 *
 * @param string $content_type The stored content type.
 *
 * @return string|null
 */
function get_html_charset( string $content_type ): ?string {
	$type = strtolower( $content_type );

	// A multipart type without a boundary has an HTML message: WooCommerce adds the plain-text part only inside PHPMailer.
	if ( ! str_starts_with( $type, 'text/html' ) && ( ! str_starts_with( $type, 'multipart/' ) || str_contains( $type, 'boundary=' ) ) ) {
		return null;
	}

	return preg_match( '/charset=([^;\s]+)/i', $content_type, $matches ) ? trim( $matches[1], '"' ) : '';
}

/**
 * Schedules the daily cleanup while it is on and there is a log, and unschedules it otherwise.
 *
 * @return void
 */
function schedule_cleanup(): void {
	$scheduled = wp_next_scheduled( CLEANUP_HOOK );

	if ( is_cleanup_enabled() && table_exists() ) {
		if ( false === $scheduled ) {
			wp_schedule_event( time(), 'daily', CLEANUP_HOOK );
		}
	} elseif ( false !== $scheduled ) {
		wp_clear_scheduled_hook( CLEANUP_HOOK );
	}
}

/**
 * Deletes the entries older than six months, from the daily cleanup event.
 *
 * @return void
 */
function run_cleanup(): void {
	if ( is_cleanup_enabled() && table_exists() && verify_table() ) {
		delete_old_entries();
	}
}

/**
 * Deletes the entries older than six months.
 *
 * @return void
 */
function delete_old_entries(): void {
	global $wpdb;

	$table = table_name();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE logged_at < %s", gmdate( 'Y-m-d H:i:s', strtotime( MAX_AGE ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Deletes every entry and keeps the table.
 *
 * @return void
 */
function clear_log(): void {
	global $wpdb;

	// Not table_exists(): importing a database can drop the version option and keep the table with its entries.
	if ( ! verify_table() ) {
		return;
	}

	$table = table_name();
	$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Returns how many entries the log holds.
 *
 * @return int
 */
function count_entries(): int {
	global $wpdb;

	if ( ! table_exists() ) {
		return 0;
	}

	$table = table_name();
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Returns one page of entries, newest first, without their message and headers.
 *
 * @param int $page The page number, from 1.
 *
 * @return object[]
 */
function get_entries( int $page ): array {
	global $wpdb;

	if ( ! table_exists() ) {
		return array();
	}

	$table = table_name();
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, logged_at, status, recipients, subject FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", PER_PAGE, ( max( 1, $page ) - 1 ) * PER_PAGE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Returns one entry, or null if it does not exist.
 *
 * @param int $id The entry ID.
 *
 * @return object|null
 */
function get_entry( int $id ): ?object {
	global $wpdb;

	if ( $id <= 0 || ! table_exists() ) {
		return null;
	}

	$table = table_name();
	$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_object( $entry ) ? $entry : null;
}

/**
 * Deletes one entry.
 *
 * @param int $id The entry ID.
 *
 * @return bool Whether an entry was deleted.
 */
function delete_entry( int $id ): bool {
	global $wpdb;

	if ( $id <= 0 || ! table_exists() ) {
		return false;
	}

	return (bool) $wpdb->delete( table_name(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

/**
 * Registers the Email log tab's settings and actions, unless the `safety_net_hide_admin` filter hides the admin page.
 *
 * @return void
 */
function add_admin_hooks(): void {
	if ( true === apply_filters( 'safety_net_hide_admin', false ) ) {
		return;
	}

	add_action( 'admin_init', __NAMESPACE__ . '\register_settings' );
	add_action( 'load-tools_page_safety_net_options', __NAMESPACE__ . '\remove_result_from_url' );
	add_action( 'admin_post_safety_net_email_log_send', __NAMESPACE__ . '\handle_send' );
	add_action( 'admin_post_safety_net_email_log_delete', __NAMESPACE__ . '\handle_delete' );
	add_action( 'admin_post_safety_net_email_log_clear', __NAMESPACE__ . '\handle_clear' );
}

/**
 * Lets wp-admin remove the result of the last action from the address bar, so reloading the page does not show its notice again.
 *
 * @return void
 */
function remove_result_from_url(): void {
	add_filter(
		'removable_query_args',
		static function ( $args ) {
			$args   = (array) $args;
			$args[] = 'result';
			return $args;
		}
	);
}

/**
 * Registers the two switches, in a settings group of their own so that saving the Tools tab leaves them alone.
 *
 * @return void
 */
function register_settings(): void {
	foreach ( array( LOG_OPTION, CLEANUP_OPTION ) as $option ) {
		register_setting(
			SETTINGS_GROUP,
			$option,
			array(
				'type'              => 'string',
				'sanitize_callback' => __NAMESPACE__ . '\sanitize_toggle',
			)
		);
	}

	add_settings_section( 'safety_net_email_log_settings', '', null, SETTINGS_PAGE );

	add_settings_field(
		LOG_OPTION,
		esc_html__( 'Log emails', 'safety-net' ),
		'SafetyNet\Admin\render_field',
		SETTINGS_PAGE,
		'safety_net_email_log_settings',
		array(
			'type'        => 'checkbox',
			'name'        => LOG_OPTION,
			'class'       => 'safety-net-toggle',
			'label_for'   => LOG_OPTION,
			'value'       => get_option( LOG_OPTION, 'on' ),
			'description' => __( 'Keep a log of the emails Safety Net blocks or lets through.', 'safety-net' ),
		)
	);

	add_settings_field(
		CLEANUP_OPTION,
		esc_html__( 'Delete old entries', 'safety-net' ),
		'SafetyNet\Admin\render_field',
		SETTINGS_PAGE,
		'safety_net_email_log_settings',
		array(
			'type'        => 'checkbox',
			'name'        => CLEANUP_OPTION,
			'class'       => 'safety-net-toggle',
			'label_for'   => CLEANUP_OPTION,
			'value'       => get_option( CLEANUP_OPTION, 'on' ),
			'description' => __( 'Delete log entries older than 6 months, once a day.', 'safety-net' ),
		)
	);
}

/**
 * Stores a switch as 'on' or 'off'; an unchecked box posts nothing.
 *
 * @param mixed $value The submitted value.
 *
 * @return string
 */
function sanitize_toggle( $value ): string {
	return 'on' === $value ? 'on' : 'off';
}

/**
 * Returns the URL of the Email log tab.
 *
 * @param array $args Query arguments to add.
 *
 * @return string
 */
function tab_url( array $args = array() ): string {
	return add_query_arg( $args, admin_url( 'tools.php?page=safety_net_options&tab=email-log' ) );
}

/**
 * Returns the label of an entry's status.
 *
 * @param string $status The stored status.
 *
 * @return string
 */
function status_label( string $status ): string {
	return match ( $status ) {
		'blocked'            => __( 'Blocked', 'safety-net' ),
		'blocked_recipients' => __( 'Blocked: not only administrators', 'safety-net' ),
		'allowed'            => __( 'Let through', 'safety-net' ),
		default              => $status,
	};
}

/**
 * Formats an entry's UTC time in the site's timezone and date and time formats.
 *
 * @param string $logged_at The stored UTC time.
 *
 * @return string
 */
function format_date( string $logged_at ): string {
	$timestamp = strtotime( $logged_at . ' UTC' );
	$date      = false === $timestamp ? false : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );

	return false === $date ? $logged_at : $date;
}

/**
 * Renders the Email log tab: the switches and the list, or one entry.
 *
 * @return void
 */
function render_tab(): void {
	if ( table_exists() && verify_table() && is_cleanup_enabled() ) {
		delete_old_entries();
	}

	$entry = isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?>
	<h3><?php esc_html_e( 'Email log', 'safety-net' ); ?></h3>
	<?php
	render_result_notice();

	// The pagination links and the settings form's referer are built from it, and would show the notice again.
	if ( isset( $_SERVER['REQUEST_URI'] ) ) {
		$_SERVER['REQUEST_URI'] = remove_query_arg( 'result', $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Only removes an argument, as wp-admin/edit.php does.
	}

	if ( $entry ) {
		render_entry( $entry );
		return;
	}

	settings_errors();
	?>
	<form action="options.php" method="post">
		<?php
		settings_fields( SETTINGS_GROUP );
		do_settings_sections( SETTINGS_PAGE );
		submit_button();
		?>
	</form>
	<?php
	render_list();
}

/**
 * Shows the result of the last action on the log, which the action passes in the URL.
 *
 * @return void
 */
function render_result_notice(): void {
	$results = array(
		'sent'      => array( 'success', __( 'A copy was sent to your email address.', 'safety-net' ) ),
		'not-sent'  => array( 'error', __( 'The copy was not sent. wp_mail() failed, or Safety Net blocked it because it would also have gone to someone who is not an administrator.', 'safety-net' ) ),
		'not-admin' => array( 'error', __( 'The copy was not sent: copies only go to administrators, and your email address is not an administrator\'s.', 'safety-net' ) ),
		'deleted'   => array( 'success', __( 'The log entry was deleted.', 'safety-net' ) ),
		'cleared'   => array( 'success', __( 'The email log was cleared.', 'safety-net' ) ),
		'not-found' => array( 'error', __( 'That log entry does not exist.', 'safety-net' ) ),
	);

	$result = isset( $_GET['result'] ) ? sanitize_key( $_GET['result'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $results[ $result ] ) ) {
		return;
	}

	printf( '<div class="notice notice-%1$s safety-net-email-log-result"><p>%2$s</p></div>', esc_attr( $results[ $result ][0] ), esc_html( $results[ $result ][1] ) );
}

/**
 * Renders one page of the log, newest first, with the Clear log button.
 *
 * @return void
 */
function render_list(): void {
	$total = count_entries();
	$pages = max( 1, (int) ceil( $total / PER_PAGE ) );
	$page  = isset( $_GET['paged'] ) ? min( max( 1, absint( $_GET['paged'] ) ), $pages ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?>
	<p class="description"><?php esc_html_e( 'Only emails sent through wp_mail() appear here. Plugins that send email another way are not logged, and Safety Net does not block them.', 'safety-net' ); ?></p>
	<p class="description"><?php esc_html_e( 'The log is emptied whenever Safety Net deletes users and orders.', 'safety-net' ); ?></p>
	<?php if ( ! $total ) : ?>
		<p class="safety-net-email-log-empty"><?php esc_html_e( 'No emails have been logged.', 'safety-net' ); ?></p>
		<?php
		return;
	endif;
	?>
	<table class="wp-list-table widefat fixed striped safety-net-email-log">
		<thead>
			<tr>
				<th scope="col" class="column-date"><?php esc_html_e( 'Date', 'safety-net' ); ?></th>
				<th scope="col"><?php esc_html_e( 'To', 'safety-net' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Subject', 'safety-net' ); ?></th>
				<th scope="col" class="column-status"><?php esc_html_e( 'Status', 'safety-net' ); ?></th>
				<th scope="col" class="column-view"><span class="screen-reader-text"><?php esc_html_e( 'View', 'safety-net' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( get_entries( $page ) as $row ) : ?>
				<tr class="safety-net-email-log-row status-<?php echo esc_attr( $row->status ); ?>">
					<td><?php echo esc_html( format_date( $row->logged_at ) ); ?></td>
					<td><?php echo esc_html( $row->recipients ); ?></td>
					<td><?php echo esc_html( '' !== $row->subject ? $row->subject : __( '(no subject)', 'safety-net' ) ); ?></td>
					<td><?php echo esc_html( status_label( $row->status ) ); ?></td>
					<td><a href="<?php echo esc_url( tab_url( array( 'entry' => (int) $row->id ) ) ); ?>"><?php esc_html_e( 'View', 'safety-net' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<div class="tablenav bottom">
		<div class="tablenav-pages">
			<span class="displaying-num">
				<?php
				/* translators: %s: the number of log entries */
				echo esc_html( sprintf( _n( '%s entry', '%s entries', $total, 'safety-net' ), number_format_i18n( $total ) ) );
				?>
			</span>
			<?php
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => tab_url( array( 'paged' => '%#%' ) ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			);
			?>
		</div>
	</div>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="safety-net-email-log-clear" data-safety-net-confirm="<?php esc_attr_e( 'Delete every entry in the email log? This cannot be undone.', 'safety-net' ); ?>">
		<input type="hidden" name="action" value="safety_net_email_log_clear" />
		<?php wp_nonce_field( 'safety_net_email_log_clear', '_wpnonce', false ); ?>
		<button type="submit" class="button"><?php esc_html_e( 'Clear log', 'safety-net' ); ?></button>
	</form>
	<?php
}

/**
 * Renders one entry, with an HTML message in a sandboxed frame that runs no scripts and loads nothing remote.
 *
 * @param int $id The entry ID.
 *
 * @return void
 */
function render_entry( int $id ): void {
	$entry = get_entry( $id );
	$back  = sprintf( '<a class="button" href="%s">%s</a>', esc_url( tab_url() ), esc_html__( 'Back to the log', 'safety-net' ) );

	if ( ! $entry ) {
		printf( '<p>%s</p><p>%s</p>', esc_html__( 'That log entry does not exist.', 'safety-net' ), $back ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		return;
	}

	$attachments = '' !== $entry->attachments ? explode( "\n", $entry->attachments ) : array();
	$rows        = array(
		__( 'Date', 'safety-net' )    => format_date( $entry->logged_at ),
		__( 'Status', 'safety-net' )  => status_label( $entry->status ),
		__( 'From', 'safety-net' )    => $entry->from_address,
		__( 'To', 'safety-net' )      => $entry->recipients,
		__( 'Subject', 'safety-net' ) => $entry->subject,
	);
	?>
	<table class="widefat striped safety-net-email-log-entry">
		<tbody>
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $label ); ?></th>
					<td><?php echo esc_html( $value ); ?></td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Headers', 'safety-net' ); ?></th>
				<td>
					<?php if ( '' !== $entry->headers ) : ?>
						<pre><?php echo esc_html( $entry->headers ); ?></pre>
					<?php else : ?>
						<?php esc_html_e( 'None', 'safety-net' ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Attachments', 'safety-net' ); ?></th>
				<td>
					<?php
					echo esc_html( $attachments ? implode( ', ', $attachments ) : __( 'None', 'safety-net' ) );
					if ( $attachments ) {
						echo '<br /><span class="description">' . esc_html__( 'Only the file names are logged.', 'safety-net' ) . '</span>';
					}
					?>
				</td>
			</tr>
		</tbody>
	</table>
	<h4><?php esc_html_e( 'Message', 'safety-net' ); ?></h4>
	<?php
	$is_html = null !== get_html_charset( $entry->content_type );
	$message = ( $is_html ? '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:"><meta name="referrer" content="no-referrer">' : '' ) . $entry->message;

	// Encoded once more, since esc_attr() and esc_html() keep existing entities, which the browser would then decode.
	$message = htmlspecialchars( $message, ENT_QUOTES | ENT_SUBSTITUTE, get_option( 'blog_charset' ) );

	if ( $is_html ) {
		printf( '<iframe class="safety-net-email-log-message" sandbox="" srcdoc="%s" title="%s"></iframe>', esc_attr( $message ), esc_attr__( 'Email message', 'safety-net' ) );
	} else {
		echo '<pre class="safety-net-email-log-message">' . esc_html( $message ) . '</pre>';
	}

	$email = wp_get_current_user()->user_email;
	?>
	<div class="safety-net-email-log-actions">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="safety_net_email_log_send" />
			<input type="hidden" name="entry" value="<?php echo esc_attr( (string) $entry->id ); ?>" />
			<?php wp_nonce_field( 'safety_net_email_log_send_' . $entry->id, '_wpnonce', false ); ?>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Send to me', 'safety-net' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-safety-net-confirm="<?php esc_attr_e( 'Delete this log entry? This cannot be undone.', 'safety-net' ); ?>">
			<input type="hidden" name="action" value="safety_net_email_log_delete" />
			<input type="hidden" name="entry" value="<?php echo esc_attr( (string) $entry->id ); ?>" />
			<?php wp_nonce_field( 'safety_net_email_log_delete_' . $entry->id, '_wpnonce', false ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Delete', 'safety-net' ); ?></button>
		</form>
		<?php echo $back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>
	</div>
	<p class="description">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: the current user's email address */
				__( 'Send to me sends a copy to %s only, with the logged sender, content type and message (password reset keys stay redacted). Attachments are not included, and the original Cc, Bcc and Reply-To headers are left out.', 'safety-net' ),
				$email
			)
		);
		?>
	</p>
	<?php
}

/**
 * Stops an admin-post request from a user who cannot manage options, or without a valid nonce.
 *
 * @param string $action The nonce action.
 *
 * @return void
 */
function check_request( string $action ): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'safety-net' ), 403 );
	}

	check_admin_referer( $action );
}

/**
 * Redirects back to the Email log tab with the result of an action, and stops.
 *
 * @param string $result The result code render_result_notice() shows.
 * @param int    $entry  The entry to show, or 0 for the list.
 *
 * @return never
 */
function redirect( string $result, int $entry = 0 ): never {
	$args = array( 'result' => $result );
	if ( $entry ) {
		$args['entry'] = $entry;
	}

	wp_safe_redirect( tab_url( $args ) );
	exit;
}

/**
 * Sends a copy of one entry to the current user, who must be an administrator, past stop_emails().
 *
 * @return void
 */
function handle_send(): void {
	$id = isset( $_POST['entry'] ) ? absint( $_POST['entry'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is per entry, checked next.
	check_request( 'safety_net_email_log_send_' . $id );

	$entry = get_entry( $id );
	if ( ! $entry ) {
		redirect( 'not-found' );
	}

	$email = wp_get_current_user()->user_email;
	if ( '' === $email || ! in_array( strtolower( $email ), get_admin_emails(), true ) ) {
		redirect( 'not-admin', $id );
	}

	redirect( send_copy( $entry, $email ) ? 'sent' : 'not-sent', $id );
}

/**
 * Sends a copy of one entry to an administrator's address, past stop_emails(), with the logged sender and content type.
 *
 * @param object $entry The entry.
 * @param string $email The administrator's address.
 *
 * @return bool Whether wp_mail() sent it.
 */
function send_copy( object $entry, string $email ): bool {
	$headers = array();
	if ( '' !== $entry->from_address ) {
		$headers[] = 'From: ' . $entry->from_address;
	}

	$charset = get_html_charset( $entry->content_type );
	if ( null !== $charset ) {
		$headers[] = 'Content-Type: text/html' . ( '' !== $charset ? '; charset=' . $charset : '' );
	} elseif ( '' !== $entry->content_type ) {
		$headers[] = 'Content-Type: ' . $entry->content_type;
	}

	/* translators: %s: the logged email's subject */
	$subject = sprintf( __( '[Safety Net copy] %s', 'safety-net' ), $entry->subject );

	sending_copy( true );
	add_action( 'phpmailer_init', __NAMESPACE__ . '\keep_copy_to_admins', PHP_INT_MAX );
	try {
		return wp_mail( $email, $subject, $entry->message, $headers );
	} finally {
		sending_copy( false );
		remove_action( 'phpmailer_init', __NAMESPACE__ . '\keep_copy_to_admins', PHP_INT_MAX );
	}
}

/**
 * Removes every recipient of a copy when one is not an administrator, so that PHPMailer refuses to send it.
 *
 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer The mailer wp_mail() is about to send the copy with.
 *
 * @return void
 */
function keep_copy_to_admins( $phpmailer ): void {
	$admins = get_admin_emails();

	foreach ( array_merge( $phpmailer->getToAddresses(), $phpmailer->getCcAddresses(), $phpmailer->getBccAddresses() ) as $address ) {
		if ( ! in_array( strtolower( (string) $address[0] ), $admins, true ) ) {
			$phpmailer->clearAllRecipients();
			return;
		}
	}
}

/**
 * Deletes one entry.
 *
 * @return void
 */
function handle_delete(): void {
	$id = isset( $_POST['entry'] ) ? absint( $_POST['entry'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is per entry, checked next.
	check_request( 'safety_net_email_log_delete_' . $id );

	redirect( delete_entry( $id ) ? 'deleted' : 'not-found' );
}

/**
 * Deletes every entry.
 *
 * @return void
 */
function handle_clear(): void {
	check_request( 'safety_net_email_log_clear' );

	clear_log();

	redirect( 'cleared' );
}
