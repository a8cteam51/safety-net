<?php
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Load PHPMailer class, so we can subclass it.
require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
class Stop_Emails_PHPMailer extends PHPMailer\PHPMailer\PHPMailer {}

/**
 * Subclass of PHPMailer to prevent Sending.
 *
 * This subclass of PHPMailer replaces the send() method
 * with a method that does not send.
 * This subclass is based on the WP Core MockPHPMailer
 * found in phpunit/includes/mock-mailer.php
 *
 */
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
class Stop_Emails_Fake_PHPMailer extends Stop_Emails_PHPMailer {
	/**
	 * Mock sent email.
	 */
	public array $mock_sent = array();

	/**
	 * Replacement send() method that does not send.
	 *
	 * Unlike the PHPMailer send method,
	 * this method never calls the method postSend(),
	 * which is where the email is actually sent
	 *
	 */
	public function send() {
		try {
			if ( ! $this->preSend() ) {
				return false;
			}

			$mock_email = array(
				'to'     => $this->to,
				'cc'     => $this->cc,
				'bcc'    => $this->bcc,
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				'header' => $this->MIMEHeader,
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				'body'   => $this->MIMEBody,
			);

			$this->mock_sent[] = $mock_email;

			return true;
		} catch ( phpmailerException $e ) {
			return false;
		}
	}
}

/**
 * Stop Emails Plugin Class.
 *
 * Prevents emails from being sent and provides basic logging.
 * Replaces PHPMailer global instance $phpmailer with an instance
 * of the subclass Stop_Emails_Fake_PHPMailer
 *
 */
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
class Stop_Emails {
	/**
	 * Constuctor to setup plugin.
	 *
	 */
	public function __construct() {
		$this->add_hooks();
	}

	/**
	 * Add hooks.
	 *
	 */
	public function add_hooks() {
		add_action( 'plugins_loaded', array( $this, 'replace_phpmailer' ) );
		add_action( 'admin_notices', array( $this, 'show_warning' ) );

		/**
		 * Force BuddyPress to use wp_mail() rather than its own BP_PHPMailer class
		 */
		add_filter( 'bp_email_use_wp_mail', '__return_true' );
	}

	/**
	 * Replace the global $phpmailer with fake phpmailer.
	 *
	 */
	public function replace_phpmailer() {
		global $phpmailer;
		return $this->replace_w_fake_phpmailer( $phpmailer );
	}

	/**
	 * Replace the parameter object with an instance of
	 * Stop_Emails_Fake_PHPMailer.
	 */
	public function replace_w_fake_phpmailer( &$obj = null ) {
		$obj = new Stop_Emails_Fake_PHPMailer();

		return $obj;
	}

	/**
	 * Display Warning that emails are being stopped.
	 *
	 */
	public function show_warning() {
		echo "\n<div class='error'><p>";
		echo '<strong>';
			esc_html_e( 'Emails Disabled', 'safety-net' );
		echo ': ';
		echo '</strong>';

		esc_html_e( 'The Safety Net plugin is currently active, which will prevent any emails from being sent.  ', 'safety-net' );
		esc_html_e( 'To send emails, disable the plugin.', 'safety-net' );
		echo '</p></div>';
	}

}

new Stop_Emails();
