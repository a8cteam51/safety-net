<?php
/**
 * Email log: records the emails stop_emails() blocks or lets through, with what wp_mail() would do or did with them, and shows them on the Email Log admin page.
 *
 * @package SafetyNet
 */

namespace SafetyNet\EmailLog;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use function SafetyNet\Admin\is_sent_to_staff;
use function SafetyNet\Utilities\get_admin_emails;

const LOG_OPTION        = 'safety_net_email_log_toggle';
const CLEANUP_OPTION    = 'safety_net_email_log_cleanup_toggle';
const DB_VERSION_OPTION = 'safety_net_email_log_db_version';
const DB_VERSION        = '2';
const CLEANUP_HOOK      = 'safety_net_email_log_cleanup';
const MAX_AGE           = '-6 months';
const SETTINGS_GROUP    = 'safety-net-email-log';
const PAGE_SLUG         = 'safety_net_email_log';
const PAGE_HOOK         = 'toplevel_page_safety_net_email_log';
const SETTINGS_SLUG     = 'safety_net_email_log_settings';
const VIEW_NONCE        = 'safety_net_email_log_view';
const OUTCOMES          = array( 'would_send', 'would_fail', 'sent', 'failed' );
const PER_PAGE          = 20;
const TEXT_BYTES        = 65535;
const ERROR_PREVIEW     = 120;
const KEY_PATTERN       = '/([?&](?:amp;|#0?38;)?(?:confirm_)?key=)([^&\s"\'<>]+)/i';
const REDACTED          = '[redacted]';

add_action( 'init', __NAMESPACE__ . '\schedule_cleanup' );
add_action( 'init', __NAMESPACE__ . '\add_admin_hooks' );
add_action( CLEANUP_HOOK, __NAMESPACE__ . '\run_cleanup' );
add_action( 'wp_mail_succeeded', __NAMESPACE__ . '\record_sent' );
add_action( 'wp_mail_failed', __NAMESPACE__ . '\record_failed' );
add_filter( 'wpmu_drop_tables', __NAMESPACE__ . '\drop_with_site', 10, 2 );

/**
 * Whether emails are logged. On unless turned off on the Email Log Settings page.
 *
 * @return bool
 */
function is_logging_enabled(): bool {
	return 'on' === get_option( LOG_OPTION, 'on' );
}

/**
 * Whether entries older than six months are deleted. On unless turned off on the Email Log Settings page.
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
			outcome varchar(20) NOT NULL default '',
			error text NOT NULL,
			recipients text NOT NULL,
			subject text NOT NULL,
			message longtext NOT NULL,
			headers text NOT NULL,
			from_address varchar(255) NOT NULL default '',
			content_type varchar(100) NOT NULL default '',
			attachments text NOT NULL,
			secrets text,
			PRIMARY KEY  (id),
			KEY logged_at (logged_at),
			KEY outcome (outcome)
		) {$wpdb->get_charset_collate()}"
	);
	// phpcs:enable

	if ( false === $created ) {
		$failed[ $table ] = true;
		return false;
	}

	// The table can be one that Safety Net 1.11.0 created, without the secrets column, kept when only its option was lost.
	update_option( DB_VERSION_OPTION, has_secrets_column() ? DB_VERSION : '1' );

	return true;
}

/**
 * Adds the secrets column to a table that Safety Net 1.11.0 created, tried once per request.
 *
 * @return bool Whether the table has the secrets column.
 */
function upgrade_table(): bool {
	global $wpdb;
	static $failed = array();

	if ( version_compare( (string) get_option( DB_VERSION_OPTION ), DB_VERSION, '>=' ) ) {
		return true;
	}

	$table = table_name();
	if ( isset( $failed[ $table ] ) ) {
		return false;
	}

	$suppressed = $wpdb->suppress_errors( true );
	try {
		$altered = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN secrets text" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from $wpdb.
		$error   = $wpdb->last_error;
	} finally {
		$wpdb->suppress_errors( $suppressed );
	}

	// Another request can have added the column since this one read the option.
	if ( false === $altered && ! has_secrets_column() ) {
		$failed[ $table ] = true;
		// A missing table is reported by the insert, which then forgets it.
		if ( verify_table() ) {
			error_log( 'Safety Net: the email log could not add its secrets column: ' . $error ); // phpcs:ignore -- Logging is okay here.
		}
		return false;
	}

	update_option( DB_VERSION_OPTION, DB_VERSION );

	return true;
}

/**
 * Whether the current site's email log table has the secrets column, checked with a query that MySQL and SQLite both run.
 *
 * @return bool
 */
function has_secrets_column(): bool {
	global $wpdb;

	$table      = table_name();
	$suppressed = $wpdb->suppress_errors( true );
	try {
		return false !== $wpdb->query( "SELECT secrets FROM {$table} LIMIT 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from $wpdb.
	} finally {
		$wpdb->suppress_errors( $suppressed );
	}
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

		$has_secrets = upgrade_table();

		$sender = get_sender( $args['headers'] ?? '' );
		$result = 'allowed' === $status ? array(
			'outcome' => '',
			'error'   => '',
		) : dry_run( $args, $sender['mail'] );

		list( $message, $keys ) = redact_keys( to_column( to_text( $args['message'] ?? '' ) ) );

		$entry = array(
			'logged_at'    => current_time( 'mysql', true ),
			'status'       => $status,
			'outcome'      => $result['outcome'],
			'error'        => to_column( $result['error'], TEXT_BYTES ),
			'recipients'   => to_column( implode( ', ', to_lines( $args['to'] ?? '', ',' ) ), TEXT_BYTES ),
			'subject'      => to_column( to_text( $args['subject'] ?? '' ), TEXT_BYTES ),
			'message'      => $message,
			'headers'      => to_column( implode( "\n", to_lines( $args['headers'] ?? '', "\n" ) ), TEXT_BYTES ),
			'from_address' => mb_substr( to_column( $sender['from'] ), 0, 255 ),
			'content_type' => mb_substr( to_column( $sender['content_type'] ), 0, 100 ),
			'attachments'  => to_column( implode( "\n", get_attachment_names( $args['attachments'] ?? array() ) ), TEXT_BYTES ),
		);

		// Anyone can request an administrator's password reset, so a stored key would let whoever reads the database take over that account.
		$secrets = $has_secrets && $keys && ! is_sent_to_staff( $args ) ? wp_json_encode( $keys ) : false;
		// Cut to fit its column, the JSON would no longer decode, so keys that do not fit are left out.
		if ( ! is_string( $secrets ) || strlen( $secrets ) > TEXT_BYTES ) {
			$secrets = null;
		}

		// The table can be gone although its option exists (e.g. only the options table was copied over); that is handled below, not reported by wpdb.
		$suppressed = $wpdb->suppress_errors( true );
		try {
			$inserted = $wpdb->insert( table_name(), $entry, '%s' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$id       = false === $inserted ? 0 : (int) $wpdb->insert_id;
			// Not in the insert: some database drivers, such as SQLite's, put the whole query in the error logged below.
			$stored = ! $id || null === $secrets || false !== $wpdb->update( table_name(), array( 'secrets' => $secrets ), array( 'id' => $id ), '%s', '%d' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}

		if ( false === $inserted ) {
			error_log( 'Safety Net: the email log could not record an email: ' . $wpdb->last_error ); // phpcs:ignore -- Logging is okay here.
			verify_table();
		} elseif ( ! $stored ) {
			// The option can be newer than the table, e.g. when every table but the options table came from a 1.11.0 copy.
			if ( ! has_secrets_column() ) {
				update_option( DB_VERSION_OPTION, '1' );
			}
			error_log( 'Safety Net: the email log could not store the keys of an email, so its copies keep them redacted.' ); // phpcs:ignore -- Logging is okay here, without the database error, which can hold the keys.
		}

		if ( 'allowed' === $status ) {
			expect_result( $id, $args );
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
 * Parses wp_mail() headers the way wp_mail() does.
 *
 * @param mixed $headers The headers, a string or an array.
 *
 * @return array{from_name: ?string, from_email: ?string, content_type: ?string, charset: ?string, cc: string[], bcc: string[], reply_to: string[], custom: array<string, string>}
 */
function parse_headers( $headers ): array {
	$parsed = array(
		'from_name'    => null,
		'from_email'   => null,
		'content_type' => null,
		'charset'      => null,
		'cc'           => array(),
		'bcc'          => array(),
		'reply_to'     => array(),
		'custom'       => array(),
	);

	if ( empty( $headers ) ) {
		return $parsed;
	}

	foreach ( is_array( $headers ) ? $headers : explode( "\n", str_replace( "\r\n", "\n", to_text( $headers ) ) ) as $header ) {
		$header = to_text( $header );
		if ( ! str_contains( $header, ':' ) ) {
			continue;
		}

		list( $name, $content ) = explode( ':', trim( $header ), 2 );
		$name                   = trim( $name );
		$content                = trim( $content );

		switch ( strtolower( $name ) ) {
			case 'from':
				$bracket_pos = strpos( $content, '<' );
				if ( false !== $bracket_pos ) {
					if ( $bracket_pos > 0 ) {
						$parsed['from_name'] = trim( str_replace( '"', '', substr( $content, 0, $bracket_pos ) ) );
					}
					$parsed['from_email'] = trim( str_replace( '>', '', substr( $content, $bracket_pos + 1 ) ) );
				} elseif ( '' !== $content ) {
					$parsed['from_email'] = $content;
				}
				break;
			case 'content-type':
				if ( str_contains( $content, ';' ) ) {
					list( $type, $charset_content ) = explode( ';', $content );
					$parsed['content_type']         = trim( $type );
					if ( false !== stripos( $charset_content, 'charset=' ) ) {
						$parsed['charset'] = trim( str_replace( array( 'charset=', '"' ), '', $charset_content ) );
					} elseif ( false !== stripos( $charset_content, 'boundary=' ) ) {
						$boundary          = trim( str_replace( array( 'BOUNDARY=', 'boundary=', '"' ), '', $charset_content ) );
						$parsed['charset'] = '';
						if ( preg_match( '~^multipart/(\S+)~', $parsed['content_type'], $matches ) ) {
							$parsed['content_type'] = 'multipart/' . strtolower( $matches[1] ) . '; boundary="' . $boundary . '"';
						}
					}
				} elseif ( '' !== $content ) {
					$parsed['content_type'] = $content;
				}
				break;
			case 'cc':
				$parsed['cc'] = array_merge( $parsed['cc'], explode( ',', $content ) );
				break;
			case 'bcc':
				$parsed['bcc'] = array_merge( $parsed['bcc'], explode( ',', $content ) );
				break;
			case 'reply-to':
				$parsed['reply_to'] = array_merge( $parsed['reply_to'], explode( ',', $content ) );
				break;
			default:
				$parsed['custom'][ $name ] = $content;
				break;
		}
	}

	return $parsed;
}

/**
 * Returns the sender and content type wp_mail() would use, while senders such as WooCommerce still have their wp_mail_* filters added.
 *
 * @param mixed $headers The wp_mail() headers, a string or an array.
 *
 * @return array{from: string, content_type: string, mail: array} What to store, and the parsed headers and resolved values for configure_mailer().
 */
function get_sender( $headers ): array {
	$parsed     = parse_headers( $headers );
	$from_email = $parsed['from_email'];

	if ( null === $from_email ) {
		$sitename   = wp_parse_url( network_home_url(), PHP_URL_HOST );
		$from_email = 'wordpress@';
		if ( is_string( $sitename ) ) {
			$from_email .= str_starts_with( $sitename, 'www.' ) ? substr( $sitename, 4 ) : $sitename;
		}
	}

	$from_email   = to_text( apply_filters( 'wp_mail_from', $from_email ) );
	$from_name    = to_text( apply_filters( 'wp_mail_from_name', $parsed['from_name'] ?? 'WordPress' ) );
	$content_type = to_text( apply_filters( 'wp_mail_content_type', $parsed['content_type'] ?? 'text/plain' ) );
	$charset      = to_text( apply_filters( 'wp_mail_charset', $parsed['charset'] ?? get_bloginfo( 'charset' ) ) );

	$stored_type = $content_type;
	if ( '' !== $charset && false === stripos( $content_type, 'charset=' ) ) {
		$stored_type .= '; charset=' . $charset;
	}

	return array(
		'from'         => '' !== $from_name ? "{$from_name} <{$from_email}>" : $from_email,
		'content_type' => $stored_type,
		'mail'         => array(
			'from_email'   => $from_email,
			'from_name'    => $from_name,
			'content_type' => $content_type,
			'charset'      => $charset,
			'cc'           => $parsed['cc'],
			'bcc'          => $parsed['bcc'],
			'reply_to'     => $parsed['reply_to'],
			'custom'       => $parsed['custom'],
		),
	);
}

/**
 * Checks a blocked email the way PHPMailer checks it before sending, with a mailer of its own that never sends, so the log can say whether wp_mail() would have sent it.
 *
 * @param array $args The wp_mail() arguments.
 * @param array $mail The resolved sender, content type and parsed headers from get_sender().
 *
 * @return array{outcome: string, error: string} 'would_send' with notes on what wp_mail() would skip, 'would_fail' with PHPMailer's error, or '' when the check itself failed.
 */
function dry_run( array $args, array $mail ): array {
	$to              = $args['to'] ?? '';
	$mail['to']      = is_array( $to ) ? $to : explode( ',', to_text( $to ) );
	$mail['subject'] = to_text( $args['subject'] ?? '' );
	$mail['message'] = to_text( $args['message'] ?? '' );
	$notes           = array();

	try {
		with_mailer(
			static function ( $mailer ) use ( $mail, $args, &$notes ) {
				$notes = configure_mailer( $mailer, $mail );
				$notes = array_merge( $notes, get_skipped_attachments( $args['attachments'] ?? array() ) );
				$mailer->preSend();
			}
		);
	} catch ( PHPMailerException $e ) {
		array_unshift( $notes, $e->getMessage() );
		return array(
			'outcome' => 'would_fail',
			'error'   => implode( "\n", $notes ),
		);
	} catch ( \Throwable $e ) {
		error_log( 'Safety Net: the email log could not check whether an email would be sent: ' . $e->getMessage() ); // phpcs:ignore -- Logging is okay here.
		return array(
			'outcome' => '',
			'error'   => '',
		);
	}

	return array(
		'outcome' => 'would_send',
		'error'   => implode( "\n", $notes ),
	);
}

/**
 * Runs a callback with a new mailer of its own, never wp_mail()'s, that validates addresses with is_email() as wp_mail()'s does.
 *
 * @param callable $callback Receives the mailer.
 *
 * @return mixed What the callback returns.
 */
function with_mailer( callable $callback ) {
	if ( ! class_exists( PHPMailer::class, false ) ) {
		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	}
	if ( ! class_exists( PHPMailerException::class, false ) ) {
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
	}
	if ( ! class_exists( 'WP_PHPMailer', false ) && file_exists( ABSPATH . WPINC . '/class-wp-phpmailer.php' ) ) {
		require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';
	}

	// The validator is static, so it is shared with wp_mail()'s mailer and must be put back.
	$validator            = PHPMailer::$validator;
	PHPMailer::$validator = static function ( $email ) {
		return (bool) is_email( $email );
	};

	try {
		return $callback( class_exists( 'WP_PHPMailer', false ) ? new \WP_PHPMailer( true ) : new PHPMailer( true ) );
	} finally {
		PHPMailer::$validator = $validator;
	}
}

/**
 * Sets up a mailer the way wp_mail() sets up its own, up to where wp_mail() fires phpmailer_init, without attachments.
 *
 * @param PHPMailer $mailer The mailer, which must throw exceptions.
 * @param array     $mail   from_email, from_name, to, cc, bcc, reply_to, subject, message, content_type, charset and custom headers.
 *
 * @return string[] Notes on the addresses and headers wp_mail() would skip.
 *
 * @throws PHPMailerException When the sender is invalid, where wp_mail() gives up.
 */
function configure_mailer( $mailer, array $mail ): array {
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's properties.
	$notes            = array();
	$mailer->Encoding = PHPMailer::ENCODING_8BIT;

	$mailer->setFrom( $mail['from_email'], $mail['from_name'], false );

	$mailer->Subject = $mail['subject'];
	$mailer->Body    = $mail['message'];

	$kinds = array(
		'to'       => 'To',
		'cc'       => 'Cc',
		'bcc'      => 'Bcc',
		'reply_to' => 'Reply-To',
	);
	foreach ( $kinds as $kind => $label ) {
		foreach ( (array) $mail[ $kind ] as $address ) {
			$address = to_text( $address );
			$name    = '';
			if ( preg_match( '/(.*)<(.+)>/', $address, $matches ) && 3 === count( $matches ) ) {
				$name    = $matches[1];
				$address = $matches[2];
			}

			try {
				switch ( $kind ) {
					case 'to':
						$mailer->addAddress( $address, $name );
						break;
					case 'cc':
						$mailer->addCC( $address, $name );
						break;
					case 'bcc':
						$mailer->addBCC( $address, $name );
						break;
					case 'reply_to':
						$mailer->addReplyTo( $address, $name );
						break;
				}
			} catch ( PHPMailerException $e ) {
				if ( '' !== trim( $address ) ) {
					/* translators: 1: To, Cc, Bcc or Reply-To, 2: the email address */
					$notes[] = sprintf( __( 'Skipped invalid %1$s address: %2$s', 'safety-net' ), $label, trim( $address ) );
				}
			}
		}
	}

	$mailer->isMail();

	$mailer->ContentType = $mail['content_type'];
	if ( 'text/html' === $mail['content_type'] ) {
		$mailer->isHTML( true );
	}
	$mailer->CharSet = $mail['charset'];
	// phpcs:enable

	foreach ( $mail['custom'] as $name => $content ) {
		if ( in_array( $name, array( 'MIME-Version', 'X-Mailer' ), true ) ) {
			continue;
		}

		try {
			$mailer->addCustomHeader( sprintf( '%1$s: %2$s', $name, $content ) );
		} catch ( PHPMailerException $e ) {
			/* translators: %s: the header name */
			$notes[] = sprintf( __( 'Skipped invalid header: %s', 'safety-net' ), $name );
		}
	}

	return $notes;
}

/**
 * Returns notes on the attachments wp_mail() would skip, checking them as PHPMailer would without reading them.
 *
 * @param mixed $attachments Newline-separated paths, or an array of paths, keyed by file name where one is given.
 *
 * @return string[]
 */
function get_skipped_attachments( $attachments ): array {
	if ( ! is_array( $attachments ) ) {
		$attachments = explode( "\n", str_replace( "\r\n", "\n", to_text( $attachments ) ) );
	}

	$notes = array();
	foreach ( $attachments as $name => $path ) {
		$path = to_text( $path );
		if ( '' === trim( $path ) || is_attachment_readable( $path ) ) {
			continue;
		}

		/* translators: %s: the attachment's file name */
		$notes[] = sprintf( __( 'Skipped attachment that cannot be read: %s', 'safety-net' ), is_string( $name ) && '' !== trim( $name ) ? trim( $name ) : wp_basename( trim( $path ) ) );
	}

	return $notes;
}

/**
 * Whether PHPMailer would attach a file: a readable file that is not a URL or a stream wrapper path.
 *
 * @param string $path The attachment's path.
 *
 * @return bool
 */
function is_attachment_readable( string $path ): bool {
	// Refused before is_file(), which would open a phar:// or remote path.
	if ( str_contains( $path, "\0" ) || preg_match( '#^[a-z][a-z\d+.-]*://#i', $path ) ) {
		return false;
	}

	// Checking a network share on Windows would connect to its host, so it counts as readable.
	if ( '\\' === DIRECTORY_SEPARATOR && ( str_starts_with( $path, '\\\\' ) || str_starts_with( $path, '//' ) ) ) {
		return true;
	}

	// Under open_basedir, is_file() warns with the full path, which the log leaves out on purpose.
	set_error_handler( static fn() => true, E_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Only around the two checks below.
	try {
		$readable = is_file( $path );
		if ( ! str_starts_with( $path, '\\\\' ) ) {
			$readable = $readable && is_readable( $path );
		}
	} finally {
		restore_error_handler();
	}

	return $readable;
}

/**
 * Rebuilds the MIME source of an entry as wp_mail() would build it, from the logged sender, recipients, headers, date and redacted message, without attachments.
 *
 * @param object $entry The entry.
 *
 * @return string|\WP_Error The MIME source, or PHPMailer's error when it refuses the entry.
 */
function get_raw_message( object $entry ) {
	try {
		return with_mailer(
			static function ( $mailer ) use ( $entry ) {
				$headers = parse_headers( $entry->headers );
				$stored  = parse_headers( array( 'From: ' . $entry->from_address, 'Content-Type: ' . $entry->content_type ) );

				configure_mailer(
					$mailer,
					array(
						'from_email'   => $stored['from_email'] ?? '',
						'from_name'    => $stored['from_name'] ?? '',
						'to'           => explode( ',', $entry->recipients ),
						'cc'           => $headers['cc'],
						'bcc'          => $headers['bcc'],
						'reply_to'     => $headers['reply_to'],
						'custom'       => $headers['custom'],
						'subject'      => $entry->subject,
						'message'      => $entry->message,
						'content_type' => $stored['content_type'] ?? 'text/plain',
						'charset'      => $stored['charset'] ?? '',
					)
				);

				$timestamp = strtotime( $entry->logged_at . ' UTC' );
				if ( false !== $timestamp ) {
					$mailer->MessageDate = gmdate( 'D, j M Y H:i:s O', $timestamp ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				}

				$mailer->preSend();

				return $mailer->getSentMIMEMessage();
			}
		);
	} catch ( PHPMailerException $e ) {
		return new \WP_Error( 'safety_net_email_log_raw', $e->getMessage() );
	} catch ( \Throwable $e ) {
		error_log( 'Safety Net: the email log could not rebuild an email: ' . $e->getMessage() ); // phpcs:ignore -- Logging is okay here.
		return new \WP_Error( 'safety_net_email_log_raw', __( 'PHPMailer could not build the message.', 'safety-net' ) );
	}
}

/**
 * Returns the emails stop_emails() let through whose wp_mail() result has not come yet, the most recent last.
 *
 * @return array[]
 */
function &pending_results(): array {
	static $pending = array();

	return $pending;
}

/**
 * Remembers an email stop_emails() let through, so the result wp_mail() reports for it is recorded in its entry.
 *
 * @param int   $id   The entry ID, or 0 to record the result nowhere.
 * @param array $args The wp_mail() arguments.
 *
 * @return void
 */
function expect_result( int $id, array $args ): void {
	$pending   = &pending_results();
	$pending[] = array(
		'id'  => $id,
		'key' => mail_key( $args ),
	);
}

/**
 * Returns the entry a wp_mail() result is for, and forgets it with the emails sent after it, which wp_mail() has finished with.
 *
 * @param array $mail The email's recipients and subject, as wp_mail() passes them to its result hooks.
 *
 * @return int The entry ID, or 0 for none.
 */
function take_result( array $mail ): int {
	$pending = &pending_results();
	$key     = mail_key( $mail );

	for ( $i = count( $pending ) - 1; $i >= 0; $i-- ) {
		if ( $pending[ $i ]['key'] === $key ) {
			$id = $pending[ $i ]['id'];
			array_splice( $pending, $i );
			return $id;
		}
	}

	return 0;
}

/**
 * Returns what identifies an email between stop_emails() and wp_mail()'s result hooks: its recipients and subject.
 *
 * @param array $mail The wp_mail() arguments, or the data wp_mail() passes to its result hooks.
 *
 * @return string
 */
function mail_key( array $mail ): string {
	return strtolower( implode( ',', to_lines( $mail['to'] ?? '', ',' ) ) ) . "\n" . to_text( $mail['subject'] ?? '' );
}

/**
 * Records that wp_mail() sent an email Safety Net let through.
 *
 * @param mixed $mail_data The email's data, from the wp_mail_succeeded action.
 *
 * @return void
 */
function record_sent( $mail_data ): void {
	if ( is_array( $mail_data ) && pending_results() ) {
		record_result( $mail_data, 'sent', '' );
	}
}

/**
 * Records that wp_mail() failed to send an email Safety Net let through, with PHPMailer's error.
 *
 * @param mixed $error The error, from the wp_mail_failed action, with the email's data.
 *
 * @return void
 */
function record_failed( $error ): void {
	if ( ! is_wp_error( $error ) || ! pending_results() ) {
		return;
	}

	$data = $error->get_error_data();
	if ( is_array( $data ) ) {
		record_result( $data, 'failed', $error->get_error_message() );
	}
}

/**
 * Stores wp_mail()'s result in the entry of the email it is for. Never throws.
 *
 * @param array  $mail    The email's data, as wp_mail() passes it to its result hooks.
 * @param string $outcome 'sent' or 'failed'.
 * @param string $error   The error message, if it failed.
 *
 * @return void
 */
function record_result( array $mail, string $outcome, string $error ): void {
	global $wpdb;

	try {
		$id = take_result( $mail );
		if ( $id <= 0 || ! is_logging_enabled() || ! table_exists() ) {
			return;
		}

		$suppressed = $wpdb->suppress_errors( true );
		try {
			$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				table_name(),
				array(
					'outcome' => $outcome,
					'error'   => to_column( $error, TEXT_BYTES ),
				),
				array( 'id' => $id ),
				'%s',
				'%d'
			);
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}

		if ( false === $updated ) {
			error_log( 'Safety Net: the email log could not record the result of an email: ' . $wpdb->last_error ); // phpcs:ignore -- Logging is okay here.
		}
	} catch ( \Throwable $e ) {
		error_log( 'Safety Net: the email log could not record the result of an email: ' . $e->getMessage() ); // phpcs:ignore -- Logging is okay here.
	}
}

/**
 * Replaces the value of every key= and confirm_key= URL parameter, such as password reset keys, in plain text and in HTML.
 *
 * @param string $message The email's message.
 *
 * @return array{0: string, 1: string[]} The message with every key replaced, and the keys it held, in order, without values that were already redacted.
 */
function redact_keys( string $message ): array {
	$keys     = array();
	$redacted = preg_replace_callback(
		KEY_PATTERN,
		static function ( $matches ) use ( &$keys ) {
			if ( REDACTED !== $matches[2] ) {
				$keys[] = $matches[2];
			}
			return $matches[1] . REDACTED;
		},
		$message
	);

	return null === $redacted ? array( '', array() ) : array( $redacted, $keys );
}

/**
 * Returns an entry's message with its keys put back in order, or null when it has none or they do not match its redacted ones one to one.
 *
 * @param object $entry The entry.
 *
 * @return string|null
 */
function restore_keys( object $entry ): ?string {
	$keys = isset( $entry->secrets ) && is_string( $entry->secrets ) ? json_decode( $entry->secrets, true ) : null;
	if ( ! is_array( $keys ) || ! $keys || ! array_is_list( $keys ) || count( $keys ) !== count( array_filter( $keys, static fn( $key ) => is_string( $key ) && '' !== $key && REDACTED !== $key ) ) ) {
		return null;
	}

	$message = to_text( $entry->message ?? '' );
	if ( preg_match_all( KEY_PATTERN, $message, $matches ) !== count( $keys ) || array_diff( $matches[2], array( REDACTED ) ) ) {
		return null;
	}

	$restored = preg_replace_callback(
		KEY_PATTERN,
		static function ( $matches ) use ( &$keys ) {
			return $matches[1] . array_shift( $keys );
		},
		$message
	);

	return is_string( $restored ) ? $restored : null;
}

/**
 * Whether a message holds a redacted key.
 *
 * @param string $message The stored message.
 *
 * @return bool
 */
function has_redacted_keys( string $message ): bool {
	return preg_match_all( KEY_PATTERN, $message, $matches ) && in_array( REDACTED, $matches[2], true );
}

/**
 * Whether the current user gets copies with the keys put back: a super admin on multisite, and a user who can edit users on a single site.
 *
 * @return bool
 */
function can_restore_keys(): bool {
	// On multisite, edit_users also comes with manage_network_users, which can be given to users who are not super admins.
	return is_multisite() ? is_super_admin() : current_user_can( 'edit_users' );
}

/**
 * Whether an entry went to an administrator or another staff account, as To, Cc or Bcc, so its keys were not stored.
 *
 * @param object $entry The entry.
 *
 * @return bool
 */
function is_entry_to_staff( object $entry ): bool {
	return is_sent_to_staff(
		array(
			'to'      => to_text( $entry->recipients ?? '' ),
			'headers' => to_text( $entry->headers ?? '' ),
		)
	);
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
 * Returns the WHERE clause, with placeholders, and its values for entries with an outcome and matching a search.
 *
 * @param string $outcome The outcome, or '' for any.
 * @param string $search  Text to find in the recipients, subject or message, or '' for any.
 *
 * @return array{0: string, 1: array}
 */
function entries_filter( string $outcome, string $search ): array {
	global $wpdb;

	$where  = array();
	$values = array();

	if ( '' !== $outcome ) {
		$where[]  = 'outcome = %s';
		$values[] = $outcome;
	}

	if ( '' !== $search ) {
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$where[]  = '( recipients LIKE %s OR subject LIKE %s OR message LIKE %s )';
		$values[] = $like;
		$values[] = $like;
		$values[] = $like;
	}

	return array( $where ? 'WHERE ' . implode( ' AND ', $where ) : '', $values );
}

/**
 * Returns how many entries the log holds with an outcome and matching a search.
 *
 * @param string $outcome The outcome, or '' for any.
 * @param string $search  Text to find in the recipients, subject or message, or '' for any.
 *
 * @return int
 */
function count_entries( string $outcome = '', string $search = '' ): int {
	global $wpdb;

	if ( ! table_exists() ) {
		return 0;
	}

	$table                  = table_name();
	list( $where, $values ) = entries_filter( $outcome, $search );
	$sql                    = "SELECT COUNT(*) FROM {$table} {$where}";

	return (int) $wpdb->get_var( $values ? $wpdb->prepare( $sql, ...$values ) : $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
}

/**
 * Returns how many entries the log holds per outcome, '' for an unknown one.
 *
 * @return array<string, int>
 */
function count_by_outcome(): array {
	global $wpdb;

	if ( ! table_exists() ) {
		return array();
	}

	$table  = table_name();
	$counts = array();
	foreach ( (array) $wpdb->get_results( "SELECT outcome, COUNT(*) AS total FROM {$table} GROUP BY outcome" ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts[ (string) $row->outcome ] = (int) $row->total;
	}

	return $counts;
}

/**
 * Returns one page of entries, newest first, with an outcome and matching a search, without their message and headers.
 *
 * @param int    $page    The page number, from 1.
 * @param string $outcome The outcome, or '' for any.
 * @param string $search  Text to find in the recipients, subject or message, or '' for any.
 *
 * @return object[]
 */
function get_entries( int $page, string $outcome = '', string $search = '' ): array {
	global $wpdb;

	if ( ! table_exists() ) {
		return array();
	}

	$table                  = table_name();
	list( $where, $values ) = entries_filter( $outcome, $search );
	$values[]               = PER_PAGE;
	$values[]               = ( max( 1, $page ) - 1 ) * PER_PAGE;

	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, logged_at, status, outcome, error, recipients, subject FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", ...$values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
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
 * Registers the Email Log page, its settings and its actions, unless the `safety_net_hide_admin` filter hides Safety Net's admin pages.
 *
 * @return void
 */
function add_admin_hooks(): void {
	if ( true === apply_filters( 'safety_net_hide_admin', false ) ) {
		return;
	}

	add_action( 'admin_menu', __NAMESPACE__ . '\register_page' );
	add_action( 'admin_init', __NAMESPACE__ . '\register_settings' );
	add_action( 'load-' . PAGE_HOOK, __NAMESPACE__ . '\load_page' );
	add_action( 'wp_ajax_safety_net_email_log_entry', __NAMESPACE__ . '\handle_ajax_entry' );
	add_action( 'admin_post_safety_net_email_log_send', __NAMESPACE__ . '\handle_send' );
	add_action( 'admin_post_safety_net_email_log_delete', __NAMESPACE__ . '\handle_delete' );
	add_action( 'admin_post_safety_net_email_log_clear', __NAMESPACE__ . '\handle_clear' );
}

/**
 * Adds the Email Log page to the main admin menu, after Tools, with its Settings page under it.
 *
 * @return void
 */
function register_page(): void {
	add_menu_page(
		esc_html__( 'Safety Net Email Log', 'safety-net' ),
		esc_html__( 'Email Log', 'safety-net' ),
		'manage_options',
		PAGE_SLUG,
		__NAMESPACE__ . '\render_page',
		'dashicons-email-alt',
		76
	);

	add_submenu_page(
		PAGE_SLUG,
		esc_html__( 'Safety Net Email Log', 'safety-net' ),
		esc_html__( 'All Emails', 'safety-net' ),
		'manage_options',
		PAGE_SLUG,
		__NAMESPACE__ . '\render_page'
	);

	$hook = add_submenu_page(
		PAGE_SLUG,
		esc_html__( 'Email Log Settings', 'safety-net' ),
		esc_html__( 'Settings', 'safety-net' ),
		'manage_options',
		SETTINGS_SLUG,
		__NAMESPACE__ . '\render_settings_page'
	);

	if ( $hook ) {
		page_hooks( $hook );
	}
}

/**
 * Returns the hook suffixes of the Email Log pages, or adds one. The Settings page's suffix comes from the translated menu title, so it is recorded, not built.
 *
 * @param string|null $hook A hook suffix to add.
 *
 * @return string[]
 */
function page_hooks( ?string $hook = null ): array {
	static $hooks = array( PAGE_HOOK );

	if ( null !== $hook && ! in_array( $hook, $hooks, true ) ) {
		$hooks[] = $hook;
	}

	return $hooks;
}

/**
 * Prepares the Email Log page before its output starts: deletes old entries, and prepares the list, which redirects from a page past the last one.
 *
 * @return void
 */
function load_page(): void {
	remove_result_from_url();

	if ( table_exists() && verify_table() && is_cleanup_enabled() ) {
		delete_old_entries();
	}

	if ( ! current_entry_id() ) {
		get_list_table();
	}

	// The pagination links and the forms' referers are built from it, and would show the notice again.
	if ( isset( $_SERVER['REQUEST_URI'] ) ) {
		$_SERVER['REQUEST_URI'] = remove_query_arg( 'result', $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Only removes an argument, as wp-admin/edit.php does.
	}
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
 * Returns the list table of the Email Log page, prepared on the first call.
 *
 * @return Email_Log_Table
 */
function get_list_table(): Email_Log_Table {
	static $table = null;

	if ( null === $table ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once __DIR__ . '/classes/class-email-log-table.php';

		$table = new Email_Log_Table();
		$table->prepare_items();
	}

	return $table;
}

/**
 * Registers the two switches, in a settings group of their own so that saving the Tools page leaves them alone.
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

	add_settings_section( 'safety_net_email_log_settings', '', null, SETTINGS_SLUG );

	add_settings_field(
		LOG_OPTION,
		esc_html__( 'Log emails', 'safety-net' ),
		'SafetyNet\Admin\render_field',
		SETTINGS_SLUG,
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
		SETTINGS_SLUG,
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
 * Returns the URL of the Email Log page.
 *
 * @param array $args Query arguments to add, not encoded.
 *
 * @return string
 */
function page_url( array $args = array() ): string {
	return add_query_arg( urlencode_deep( $args ), admin_url( 'admin.php?page=' . PAGE_SLUG ) );
}

/**
 * Returns the outcome filter, search and page number of the list in a query, keeping only valid values.
 *
 * @param array $query The query arguments, unslashed.
 *
 * @return array{outcome?: string, s?: string, paged?: int}
 */
function list_args( array $query ): array {
	$args = array();

	if ( isset( $query['outcome'] ) && in_array( $query['outcome'], OUTCOMES, true ) ) {
		$args['outcome'] = $query['outcome'];
	}

	if ( isset( $query['s'] ) && is_string( $query['s'] ) && '' !== trim( $query['s'] ) ) {
		$args['s'] = trim( $query['s'] );
	}

	if ( isset( $query['paged'] ) && is_scalar( $query['paged'] ) && absint( $query['paged'] ) > 1 ) {
		$args['paged'] = absint( $query['paged'] );
	}

	return $args;
}

/**
 * Returns the outcome filter, search and page number of the list in the current request.
 *
 * @return array{outcome?: string, s?: string, paged?: int}
 */
function current_list_args(): array {
	return list_args( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only whitelisted values, integers and a search used in a prepared LIKE are kept.
}

/**
 * Returns the ID of the entry the current request shows, or 0 for the list.
 *
 * @return int
 */
function current_entry_id(): int {
	return isset( $_GET['entry'] ) && is_scalar( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/**
 * Returns the URL of an entry's page, keeping the list's filter, search and page for its Back link.
 *
 * @param int $id The entry ID.
 *
 * @return string
 */
function entry_url( int $id ): string {
	return page_url( current_list_args() + array( 'entry' => $id ) );
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
 * Returns the label of an entry's outcome: what wp_mail() would do with a blocked email, or did with one let through.
 *
 * @param string $outcome The stored outcome.
 *
 * @return string
 */
function outcome_label( string $outcome ): string {
	return match ( $outcome ) {
		'would_send' => __( 'Would send', 'safety-net' ),
		'would_fail' => __( 'Would fail', 'safety-net' ),
		'sent'       => __( 'Sent', 'safety-net' ),
		'failed'     => __( 'Failed', 'safety-net' ),
		default      => '—',
	};
}

/**
 * Returns the label of an entry's outcome as HTML, which screen readers read as "Unknown" for an outcome without a label.
 *
 * @param string $outcome The stored outcome.
 *
 * @return string
 */
function outcome_html( string $outcome ): string {
	if ( ! in_array( $outcome, OUTCOMES, true ) ) {
		return '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Unknown', 'safety-net' ) . '</span>';
	}

	return esc_html( outcome_label( $outcome ) );
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
 * Encodes text for HTML once more than esc_html() and esc_attr() would, since they keep existing entities, which the browser would then decode.
 *
 * @param string $text The text, shown as it is.
 *
 * @return string
 */
function encode_text( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, get_option( 'blog_charset' ) );
}

/**
 * Renders the Email Log page: the list, or one entry.
 *
 * @return void
 */
function render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$id   = current_entry_id();
	$args = current_list_args();
	?>
	<div class="wrap safety-net-email-log-page">
		<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<?php if ( ! $id && isset( $args['s'] ) ) : ?>
			<span class="subtitle">
				<?php
				/* translators: %s: the search terms */
				printf( esc_html__( 'Search results for: %s', 'safety-net' ), '<strong>' . esc_html( $args['s'] ) . '</strong>' );
				?>
			</span>
		<?php endif; ?>
		<hr class="wp-header-end">
		<?php
		render_result_notice();

		if ( $id ) {
			render_entry_page( $id );
		} else {
			render_list();
		}
		?>
	</div>
	<?php
}

/**
 * Renders the Email Log Settings page: the two switches and what the log covers.
 *
 * @return void
 */
function render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap safety-net-email-log-settings-page">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<?php settings_errors(); ?>
		<form action="options.php" method="post" class="safety-net-email-log-settings">
			<?php
			settings_fields( SETTINGS_GROUP );
			do_settings_sections( SETTINGS_SLUG );
			submit_button();
			?>
		</form>
		<div class="notice notice-info inline safety-net-email-log-notes">
			<p><?php esc_html_e( 'Only emails sent through wp_mail() are logged. Plugins that send email another way are neither logged nor blocked.', 'safety-net' ); ?></p>
			<p><?php esc_html_e( 'The log is emptied whenever Safety Net deletes users and orders.', 'safety-net' ); ?></p>
		</div>
	</div>
	<?php
}

/**
 * Shows the result of the last action on the log, which the action passes in the URL.
 *
 * @return void
 */
function render_result_notice(): void {
	$results = array(
		'sent'           => array( 'success', __( 'A copy was sent to your email address.', 'safety-net' ) ),
		'sent-with-keys' => array( 'success', __( 'A copy was sent to your email address, with its password reset and confirmation keys put back.', 'safety-net' ) ),
		'not-sent'       => array( 'error', __( 'The copy was not sent. wp_mail() failed, or Safety Net blocked it because it would also have gone to someone who is not an administrator.', 'safety-net' ) ),
		'not-admin'      => array( 'error', __( 'The copy was not sent: copies only go to administrators, and your email address is not an administrator\'s.', 'safety-net' ) ),
		'deleted'        => array( 'success', __( 'The log entry was deleted.', 'safety-net' ) ),
		'cleared'        => array( 'success', __( 'The email log was cleared.', 'safety-net' ) ),
		'not-found'      => array( 'error', __( 'That log entry does not exist.', 'safety-net' ) ),
	);

	$result = isset( $_GET['result'] ) ? sanitize_key( $_GET['result'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $results[ $result ] ) ) {
		return;
	}

	printf( '<div class="notice notice-%1$s safety-net-email-log-result"><p>%2$s</p></div>', esc_attr( $results[ $result ][0] ), esc_html( $results[ $result ][1] ) );
}

/**
 * Renders the filtered and searchable list with the Clear log button, and the dialog entries open in.
 *
 * @return void
 */
function render_list(): void {
	$table = get_list_table();
	$args  = current_list_args();
	?>
	<div class="notice notice-info inline safety-net-email-log-notes"><p><?php esc_html_e( 'Would send and Would fail: whether wp_mail() would have sent a blocked email, checked the way PHPMailer checks it before sending, without contacting a mail server. Sent and Failed: the real result for emails Safety Net let through.', 'safety-net' ); ?></p></div>
	<?php if ( ! is_logging_enabled() ) : ?>
		<div class="notice notice-warning inline"><p>
			<?php
			printf(
				/* translators: %s: link to the Email Log Settings page */
				esc_html__( 'Logging is switched off, so new emails are not logged. Turn it on in %s.', 'safety-net' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=' . SETTINGS_SLUG ) ) . '">' . esc_html__( 'Settings', 'safety-net' ) . '</a>'
			);
			?>
		</p></div>
	<?php endif; ?>
	<?php $table->views(); ?>
	<form method="get" class="safety-net-email-log-filter">
		<input type="hidden" name="page" value="<?php echo esc_attr( PAGE_SLUG ); ?>" />
		<?php if ( isset( $args['outcome'] ) ) : ?>
			<input type="hidden" name="outcome" value="<?php echo esc_attr( $args['outcome'] ); ?>" />
		<?php endif; ?>
		<?php
		$table->search_box( esc_html__( 'Search emails', 'safety-net' ), 'safety-net-email-log' );
		$table->display();
		?>
	</form>
	<?php if ( count_entries() ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="safety-net-email-log-clear" data-safety-net-confirm="<?php esc_attr_e( 'Delete every entry in the email log? This cannot be undone.', 'safety-net' ); ?>">
			<input type="hidden" name="action" value="safety_net_email_log_clear" />
			<?php wp_nonce_field( 'safety_net_email_log_clear' ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Clear log', 'safety-net' ); ?></button>
		</form>
	<?php endif; ?>
	<dialog id="safety-net-email-log-dialog" class="safety-net-email-log-dialog" aria-labelledby="safety-net-email-log-dialog-title" data-nonce="<?php echo esc_attr( wp_create_nonce( VIEW_NONCE ) ); ?>" data-loading="<?php esc_attr_e( 'Loading…', 'safety-net' ); ?>" data-loaded="<?php esc_attr_e( 'Log entry loaded.', 'safety-net' ); ?>" data-error="<?php esc_attr_e( 'The log entry could not be loaded. Reload the page and try again.', 'safety-net' ); ?>">
		<div class="safety-net-email-log-dialog-header">
			<h2 id="safety-net-email-log-dialog-title"><?php esc_html_e( 'Email log entry', 'safety-net' ); ?></h2>
			<button type="button" class="button safety-net-email-log-dialog-close"><?php esc_html_e( 'Close', 'safety-net' ); ?></button>
		</div>
		<div class="safety-net-email-log-dialog-body"></div>
	</dialog>
	<?php
}

/**
 * Renders the page of one entry, which the list links to when the dialog cannot open.
 *
 * @param int $id The entry ID.
 *
 * @return void
 */
function render_entry_page( int $id ): void {
	$entry = get_entry( $id );

	if ( ! $entry ) {
		printf(
			'<p>%1$s</p><p><a class="button" href="%2$s">%3$s</a></p>',
			esc_html__( 'That log entry does not exist.', 'safety-net' ),
			esc_url( page_url( current_list_args() ) ),
			esc_html__( 'Back to the log', 'safety-net' )
		);
		return;
	}

	render_entry( $entry, true );
}

/**
 * Renders one entry, for its page or for the dialog: its details, its message as HTML or text, raw and JSON, and its actions.
 *
 * @param object $entry   The entry.
 * @param bool   $on_page Whether it is rendered on its own page rather than in the dialog.
 *
 * @return void
 */
function render_entry( object $entry, bool $on_page ): void {
	$id          = (int) $entry->id;
	$attachments = '' !== $entry->attachments ? explode( "\n", $entry->attachments ) : array();
	$rows        = array(
		array( __( 'Date', 'safety-net' ), esc_html( format_date( $entry->logged_at ) ), '' ),
		array( __( 'Status', 'safety-net' ), esc_html( status_label( $entry->status ) ), '' ),
		array( __( 'Result', 'safety-net' ), outcome_html( $entry->outcome ), 'safety-net-email-log-outcome' ),
		array( __( 'Error', 'safety-net' ), '' !== $entry->error ? esc_html( encode_text( $entry->error ) ) : esc_html__( 'None', 'safety-net' ), 'safety-net-email-log-error' ),
		array( __( 'From', 'safety-net' ), esc_html( encode_text( $entry->from_address ) ), '' ),
		array( __( 'To', 'safety-net' ), esc_html( encode_text( $entry->recipients ) ), '' ),
		array( __( 'Subject', 'safety-net' ), esc_html( encode_text( $entry->subject ) ), '' ),
	);
	?>
	<div class="safety-net-email-log-entry-view">
		<table class="widefat striped safety-net-email-log-entry">
			<tbody>
				<?php foreach ( $rows as list( $label, $html, $class ) ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td<?php echo $class ? ' class="' . esc_attr( $class ) . '"' : ''; ?>><?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $rows. ?></td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Headers', 'safety-net' ); ?></th>
					<td>
						<?php if ( '' !== $entry->headers ) : ?>
							<pre><?php echo esc_html( encode_text( $entry->headers ) ); ?></pre>
						<?php else : ?>
							<?php esc_html_e( 'None', 'safety-net' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Attachments', 'safety-net' ); ?></th>
					<td>
						<?php
						if ( $attachments ) {
							echo esc_html( encode_text( implode( ', ', $attachments ) ) ) . '<br /><span class="description">' . esc_html__( 'Only the file names are logged.', 'safety-net' ) . '</span>';
						} else {
							esc_html_e( 'None', 'safety-net' );
						}
						?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php render_entry_views( $entry ); ?>
		<div class="safety-net-email-log-actions">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="safety_net_email_log_send" />
				<input type="hidden" name="entry" value="<?php echo esc_attr( (string) $id ); ?>" />
				<?php wp_nonce_field( 'safety_net_email_log_send_' . $id, '_wpnonce', $on_page ); ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Send to me', 'safety-net' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-safety-net-confirm="<?php esc_attr_e( 'Delete this log entry? This cannot be undone.', 'safety-net' ); ?>">
				<input type="hidden" name="action" value="safety_net_email_log_delete" />
				<input type="hidden" name="entry" value="<?php echo esc_attr( (string) $id ); ?>" />
				<?php wp_nonce_field( 'safety_net_email_log_delete_' . $id, '_wpnonce', $on_page ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Delete', 'safety-net' ); ?></button>
			</form>
			<?php if ( $on_page ) : ?>
				<a class="button" href="<?php echo esc_url( page_url( current_list_args() ) ); ?>"><?php esc_html_e( 'Back to the log', 'safety-net' ); ?></a>
			<?php endif; ?>
		</div>
		<p class="description safety-net-email-log-copy-note"><?php echo esc_html( copy_note( $entry ) ); ?></p>
	</div>
	<?php
}

/**
 * Returns what Send to me sends for an entry, and whether the links with keys in the copy work for the current user.
 *
 * @param object $entry The entry.
 *
 * @return string
 */
function copy_note( object $entry ): string {
	$note = sprintf(
		/* translators: %s: the current user's email address */
		__( 'Send to me sends a copy to %s only, with the logged sender, content type and message. Attachments are not included, and the original Cc, Bcc and Reply-To headers are left out.', 'safety-net' ),
		wp_get_current_user()->user_email
	);

	if ( ! has_redacted_keys( $entry->message ) ) {
		return $note;
	}

	$restored = restore_keys( $entry );
	if ( null === $restored && is_entry_to_staff( $entry ) ) {
		return $note . ' ' . __( 'Its password reset and confirmation keys stay redacted, so those links will not work: keys are not stored for emails to administrators or other staff accounts, who got the email itself.', 'safety-net' );
	}

	if ( ! can_restore_keys() ) {
		return $note . ' ' . ( is_multisite()
			? __( 'Its password reset and confirmation keys stay redacted, so those links will not work: only super admins get the keys back.', 'safety-net' )
			: __( 'Its password reset and confirmation keys stay redacted, so those links will not work: only users who can edit users get the keys back.', 'safety-net' ) );
	}

	if ( null === $restored ) {
		return $note . ' ' . __( 'Its password reset and confirmation keys stay redacted, so those links will not work: the keys were not stored with this entry.', 'safety-net' );
	}

	return $note . ' ' . __( 'Its password reset and confirmation keys are put back, so those links work until WordPress expires the keys, after 24 hours by default, or a newer request replaces them.', 'safety-net' );
}

/**
 * Renders the views of an entry's message as tabs: HTML (or Text), Raw and JSON. Every panel is shown until the script hides the inactive ones.
 *
 * @param object $entry The entry.
 *
 * @return void
 */
function render_entry_views( object $entry ): void {
	$id      = (int) $entry->id;
	$is_html = null !== get_html_charset( $entry->content_type );
	$views   = array(
		'message' => $is_html ? __( 'HTML', 'safety-net' ) : __( 'Text', 'safety-net' ),
		'raw'     => __( 'Raw', 'safety-net' ),
		'json'    => __( 'JSON', 'safety-net' ),
	);
	?>
	<div class="safety-net-email-log-views">
		<div role="tablist" class="safety-net-email-log-tabs hide-if-no-js" aria-label="<?php esc_attr_e( 'Message views', 'safety-net' ); ?>">
			<?php foreach ( array_keys( $views ) as $index => $view ) : ?>
				<button type="button" role="tab" class="safety-net-email-log-tab" id="<?php echo esc_attr( "safety-net-email-log-tab-{$view}-{$id}" ); ?>" aria-controls="<?php echo esc_attr( "safety-net-email-log-panel-{$view}-{$id}" ); ?>" aria-selected="<?php echo 0 === $index ? 'true' : 'false'; ?>" tabindex="<?php echo 0 === $index ? '0' : '-1'; ?>"><?php echo esc_html( $views[ $view ] ); ?></button>
			<?php endforeach; ?>
		</div>
		<?php foreach ( $views as $view => $label ) : ?>
			<div role="tabpanel" class="<?php echo esc_attr( "safety-net-email-log-panel safety-net-email-log-panel-{$view}" ); ?>" id="<?php echo esc_attr( "safety-net-email-log-panel-{$view}-{$id}" ); ?>" aria-labelledby="<?php echo esc_attr( "safety-net-email-log-tab-{$view}-{$id}" ); ?>" tabindex="0">
				<h4 class="hide-if-js"><?php echo esc_html( $label ); ?></h4>
				<?php
				if ( 'message' === $view ) {
					render_message( $entry, $is_html );
				} elseif ( 'raw' === $view ) {
					render_raw( $entry );
				} else {
					render_json( $entry );
				}
				?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Renders an entry's message: HTML in a sandboxed frame that runs no scripts and loads nothing remote, anything else as text.
 *
 * @param object $entry   The entry.
 * @param bool   $is_html Whether wp_mail() sends the message as HTML.
 *
 * @return void
 */
function render_message( object $entry, bool $is_html ): void {
	$message = ( $is_html ? '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:"><meta name="referrer" content="no-referrer">' : '' ) . $entry->message;

	if ( $is_html ) {
		printf( '<iframe class="safety-net-email-log-message" sandbox="" srcdoc="%s" title="%s"></iframe>', esc_attr( encode_text( $message ) ), esc_attr__( 'Email message', 'safety-net' ) );
	} else {
		echo '<pre class="safety-net-email-log-message">' . esc_html( encode_text( $message ) ) . '</pre>';
	}
}

/**
 * Renders the MIME source of an entry as wp_mail() would build it, or why PHPMailer refuses it.
 *
 * @param object $entry The entry.
 *
 * @return void
 */
function render_raw( object $entry ): void {
	$raw = get_raw_message( $entry );

	if ( is_wp_error( $raw ) ) {
		printf(
			'<div class="notice notice-error inline safety-net-email-log-raw-error"><p>%s</p></div>',
			/* translators: %s: PHPMailer's error message */
			esc_html( sprintf( __( 'The raw message could not be rebuilt: %s', 'safety-net' ), $raw->get_error_message() ) )
		);
		return;
	}

	$note = __( 'Rebuilt from this entry the way wp_mail() builds an email, with the logged date and the redacted message.', 'safety-net' );
	if ( '' !== $entry->attachments ) {
		$note .= ' ' . __( 'Attachments are not included.', 'safety-net' );
	}

	printf( '<p class="description">%1$s</p><pre class="safety-net-email-log-raw">%2$s</pre>', esc_html( $note ), esc_html( encode_text( $raw ) ) );
}

/**
 * Renders an entry as JSON.
 *
 * @param object $entry The entry.
 *
 * @return void
 */
function render_json( object $entry ): void {
	echo '<pre class="safety-net-email-log-json">' . esc_html( encode_text( get_entry_json( $entry ) ) ) . '</pre>';
}

/**
 * Returns an entry as pretty-printed JSON.
 *
 * @param object $entry The entry.
 *
 * @return string
 */
function get_entry_json( object $entry ): string {
	$timestamp = strtotime( $entry->logged_at . ' UTC' );

	return (string) wp_json_encode(
		array(
			'id'           => (int) $entry->id,
			'logged_at'    => false === $timestamp ? $entry->logged_at : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ),
			'status'       => $entry->status,
			'outcome'      => $entry->outcome,
			'error'        => $entry->error,
			'from'         => $entry->from_address,
			'to'           => to_lines( $entry->recipients, ',' ),
			'subject'      => $entry->subject,
			'headers'      => to_lines( $entry->headers, "\n" ),
			'content_type' => $entry->content_type,
			'attachments'  => to_lines( $entry->attachments, "\n" ),
			'message'      => $entry->message,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	);
}

/**
 * Returns one entry's markup for the dialog, as JSON, rendered by the same code as its page.
 *
 * @return void
 */
function handle_ajax_entry(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'safety-net' ) ), 403 );
	}

	if ( false === check_ajax_referer( VIEW_NONCE, '_wpnonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the page and try again.', 'safety-net' ) ), 403 );
	}

	$entry = get_entry( current_entry_id() );
	if ( ! $entry ) {
		wp_send_json_error( array( 'message' => __( 'That log entry does not exist.', 'safety-net' ) ), 404 );
	}

	ob_start();
	render_entry( $entry, false );

	wp_send_json_success( array( 'html' => ob_get_clean() ) );
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
 * Returns the list's filter, search and page, and the entry, of the Email Log page the request came from, or nothing when it came from elsewhere.
 *
 * @param bool $keep_entry Whether to return to the entry the request came from.
 *
 * @return array
 */
function referer_args( bool $keep_entry ): array {
	$referer = wp_get_referer();
	if ( ! $referer ) {
		return array();
	}

	$parts = wp_parse_url( $referer );
	if ( ! is_array( $parts ) || ( $parts['path'] ?? '' ) !== wp_parse_url( admin_url( 'admin.php' ), PHP_URL_PATH ) ) {
		return array();
	}

	parse_str( $parts['query'] ?? '', $query );
	if ( PAGE_SLUG !== ( $query['page'] ?? '' ) ) {
		return array();
	}

	$args = list_args( $query );
	if ( $keep_entry && isset( $query['entry'] ) && is_scalar( $query['entry'] ) && absint( $query['entry'] ) ) {
		$args['entry'] = absint( $query['entry'] );
	}

	return $args;
}

/**
 * Redirects back to the Email Log page the request came from with the result of an action, and stops.
 *
 * @param string $result     The result code render_result_notice() shows.
 * @param bool   $keep_entry Whether to return to the entry the request came from, rather than to the list.
 *
 * @return never
 */
function redirect( string $result, bool $keep_entry = false ): never {
	wp_safe_redirect( page_url( referer_args( $keep_entry ) + array( 'result' => $result ) ) );
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
		redirect( 'not-admin', true );
	}

	$message = can_restore_keys() ? restore_keys( $entry ) : null;
	if ( ! send_copy( $entry, $email, $message ) ) {
		redirect( 'not-sent', true );
	}

	redirect( null === $message ? 'sent' : 'sent-with-keys', true );
}

/**
 * Sends a copy of one entry to an administrator's address, past stop_emails(), with the logged sender and content type.
 *
 * @param object      $entry   The entry.
 * @param string      $email   The administrator's address.
 * @param string|null $message The message to send instead of the logged one, such as one with its keys put back.
 *
 * @return bool Whether wp_mail() sent it.
 */
function send_copy( object $entry, string $email, ?string $message = null ): bool {
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
		return wp_mail( $email, $subject, $message ?? $entry->message, $headers );
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
