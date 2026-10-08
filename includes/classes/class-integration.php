<?php
/**
 * Integration declaration
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations;

/**
 * Everything Safety Net does for one third-party plugin, as data the pipeline steps read.
 */
final class Integration {

	const LIST_FIELDS = array( 'plugins', 'options', 'delete_options', 'tables', 'network_tables', 'post_types', 'comment_types', 'usermeta', 'action_scheduler_hooks', 'cancel_action_scheduler_hooks', 'upload_globs', 'offline_gateways' );

	const PHASES = array( 'scrub', 'delete', 'hooks', 'late' );

	/**
	 * Declares an integration.
	 *
	 * @param string        $slug                          Unique name made of a-z, 0-9 and hyphens; also the integration's file name.
	 * @param string        $label                         Human-readable name.
	 * @param string[]      $plugins                       Case-insensitive substrings of plugin basenames to deactivate, as in plugin_denylist.txt.
	 * @param string[]      $options                       Options blanked after a copy to {name}_sn_backup.
	 * @param array         $partial_options               Option name => keys inside its array value (listed keys are blanked, string keys set to their value), or a closure that returns the new array; a value that is not an array is blanked whole; backup kept.
	 * @param array         $option_values                 Option name => value to set; backup kept.
	 * @param string[]      $delete_options                Options deleted without a backup, together with any {name}_sn_backup.
	 * @param array         $delete_partial_options        Option name => keys blanked at any depth inside its array value, without a backup; a value that is not an array is kept, and any {name}_sn_backup is deleted.
	 * @param array         $delete_option_prefixes        Option name prefixes deleted like delete_options; a prefix => suffixes entry only matches names ending in one of them.
	 * @param string[]      $tables                        Tables after $wpdb->prefix that are emptied.
	 * @param string[]      $network_tables                Tables after $wpdb->base_prefix that are emptied.
	 * @param string[]      $post_types                    Post types whose posts and post meta are deleted.
	 * @param string[]      $comment_types                 Comment types whose comments are deleted.
	 * @param string[]      $usermeta                      User meta key LIKE patterns deleted for every user.
	 * @param string[]      $action_scheduler_hooks        Hooks whose scheduled actions and their logs are deleted.
	 * @param string[]      $cancel_action_scheduler_hooks Hook LIKE patterns whose pending actions the scrub cancels.
	 * @param string[]      $upload_globs                  File globs relative to the uploads directory that are deleted.
	 * @param string[]      $offline_gateways              Gateway classes, and their subclasses, that the gateway pass leaves active.
	 * @param \Closure|null $scrub                         Runs in the scrub step after the declared options; may run before other plugins load.
	 * @param \Closure|null $delete                        Runs in the delete step after the declared deletes; may run before other plugins load.
	 * @param \Closure|null $hooks                         Runs while Safety Net loads on every non-production request.
	 * @param \Closure|null $late                          Runs on wp_loaded of every non-production request, when other plugins' classes are available.
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $label,
		public readonly array $plugins = array(),
		public readonly array $options = array(),
		public readonly array $partial_options = array(),
		public readonly array $option_values = array(),
		public readonly array $delete_options = array(),
		public readonly array $delete_partial_options = array(),
		public readonly array $delete_option_prefixes = array(),
		public readonly array $tables = array(),
		public readonly array $network_tables = array(),
		public readonly array $post_types = array(),
		public readonly array $comment_types = array(),
		public readonly array $usermeta = array(),
		public readonly array $action_scheduler_hooks = array(),
		public readonly array $cancel_action_scheduler_hooks = array(),
		public readonly array $upload_globs = array(),
		public readonly array $offline_gateways = array(),
		public readonly ?\Closure $scrub = null,
		public readonly ?\Closure $delete = null,
		public readonly ?\Closure $hooks = null,
		public readonly ?\Closure $late = null,
	) {}

	/**
	 * Returns the declaration as plain data, with each phase as whether it has a closure and each closure in partial_options as true.
	 *
	 * @return array
	 */
	public function to_array(): array {
		$data = get_object_vars( $this );
		foreach ( self::PHASES as $phase ) {
			$data[ $phase ] = null !== $this->$phase;
		}

		foreach ( $this->partial_options as $option => $keys ) {
			if ( $keys instanceof \Closure ) {
				$data['partial_options'][ $option ] = true;
			}
		}

		return $data;
	}
}
