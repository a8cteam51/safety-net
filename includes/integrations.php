<?php
/**
 * Integrations: what Safety Net does for each third-party plugin, declared in includes/integrations/<slug>.php
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations;

use function SafetyNet\Utilities\get_denylist_array;

use const SafetyNet\Utilities\OFFLINE_GATEWAY_CLASSES;

require_once __DIR__ . '/classes/class-integration.php';

// Safety Net's own declarations come first, so a site's declaration that overlaps one of them is the one skipped.
const BUILT_IN_PRIORITY = PHP_INT_MIN;

/**
 * Loads the integration files, which add their declarations to the safety_net/integrations filter.
 *
 * @return void
 */
function load_integrations(): void {
	$directory = __DIR__ . '/integrations';
	// glob() would read brackets or asterisks in the install path as a pattern and find nothing.
	$names = is_dir( $directory ) ? scandir( $directory, SCANDIR_SORT_NONE ) : false;
	if ( ! is_array( $names ) ) {
		return;
	}

	// Byte order keeps integrations in the same order on every system, whatever its collation.
	sort( $names, SORT_STRING );
	foreach ( $names as $name ) {
		if ( str_ends_with( $name, '.php' ) && ! in_array( $name[0], array( '_', '.' ), true ) ) {
			require_once $directory . '/' . $name;
		}
	}
}

/**
 * Returns the valid integrations, keyed by slug, in the order they were declared.
 *
 * @return Integration[]
 */
function get_integrations(): array {
	return integrations_cache( false );
}

/**
 * Makes the next get_integrations() call collect the declarations again; for tests.
 *
 * @return void
 */
function reset_integrations_cache(): void {
	integrations_cache( true );
}

/**
 * Keeps the collected integrations for the rest of the request.
 *
 * @param bool $reset Whether to forget them.
 * @return Integration[]
 */
function integrations_cache( bool $reset ): array {
	static $integrations = null;

	if ( $reset ) {
		$integrations = null;
		return array();
	}

	if ( null === $integrations ) {
		$integrations = collect_integrations();
	}

	return $integrations;
}

/**
 * Collects the declarations on the safety_net/integrations filter, logging and skipping invalid ones so the rest still run.
 *
 * @return Integration[]
 */
function collect_integrations(): array {
	$declared = apply_filters( 'safety_net/integrations', array() );
	if ( ! is_array( $declared ) ) {
		error_log( sprintf( 'Safety Net: ignoring integrations: the safety_net/integrations filter returned %s, not an array.', get_debug_type( $declared ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Logging is okay here.
		return array();
	}

	$integrations = array();
	foreach ( $declared as $index => $integration ) {
		$problem = find_problem( $integration, $integrations );
		if ( null === $problem ) {
			$integrations[ $integration->slug ] = $integration;
			continue;
		}

		$name = $integration instanceof Integration ? '"' . $integration->slug . '"' : '#' . $index;
		error_log( sprintf( 'Safety Net: ignoring integration %s: %s.', $name, $problem ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Logging is okay here.
	}

	return $integrations;
}

/**
 * Finds what makes a declaration invalid.
 *
 * @param mixed         $integration The declaration.
 * @param Integration[] $accepted    The integrations accepted so far.
 * @return string|null The problem, or null when the declaration is valid.
 */
function find_problem( $integration, array $accepted ): ?string {
	if ( ! $integration instanceof Integration ) {
		return sprintf( 'expected a %s, got %s', Integration::class, get_debug_type( $integration ) );
	}

	if ( ! preg_match( '/^[a-z0-9-]+$/', $integration->slug ) ) {
		return 'its slug may only contain a-z, 0-9 and hyphens';
	}

	if ( isset( $accepted[ $integration->slug ] ) ) {
		return 'another integration already uses its slug';
	}

	foreach ( Integration::LIST_FIELDS as $field ) {
		if ( ! is_name_list( $integration->$field ) ) {
			return "$field may only hold non-empty strings";
		}
	}

	foreach ( array( 'partial_options', 'option_values', 'delete_partial_options' ) as $field ) {
		if ( ! is_name_list( array_keys( $integration->$field ) ) ) {
			return "$field must be keyed by option name";
		}
	}

	foreach ( $integration->partial_options as $keys ) {
		if ( ! $keys instanceof \Closure && ( ! is_array( $keys ) || ! $keys || ! is_name_list( partial_option_keys( $keys ) ) ) ) {
			return 'partial_options must map each option to the keys to change inside it, or to a closure';
		}
	}

	foreach ( $integration->delete_partial_options as $keys ) {
		if ( ! is_array( $keys ) || ! $keys || ! array_is_list( $keys ) || ! is_name_list( $keys ) ) {
			return 'delete_partial_options must map each option to the keys to blank inside it';
		}
	}

	foreach ( $integration->delete_option_prefixes as $prefix => $suffixes ) {
		$valid = is_int( $prefix ) ? is_name_list( array( $suffixes ) ) : ( '' !== $prefix && is_array( $suffixes ) && $suffixes && is_name_list( $suffixes ) );
		if ( ! $valid ) {
			return 'delete_option_prefixes must list prefixes, or map each prefix to the suffixes it covers';
		}
	}

	$options = declared_options( $integration );
	if ( count( $options ) !== count( array_unique( $options ) ) ) {
		return 'it declares an option twice';
	}

	$plugins = array_map( 'strtolower', $integration->plugins );
	if ( count( $plugins ) !== count( array_unique( $plugins ) ) ) {
		return 'it declares a plugin pattern twice';
	}

	foreach ( $accepted as $other ) {
		$shared = array_intersect( $options, declared_options( $other ) );
		if ( $shared ) {
			return sprintf( 'option %s is already declared by integration %s', reset( $shared ), $other->slug );
		}

		$shared = array_intersect( $plugins, array_map( 'strtolower', $other->plugins ) );
		if ( $shared ) {
			return sprintf( 'plugin pattern %s is already declared by integration %s', reset( $shared ), $other->slug );
		}

		foreach ( $options as $option ) {
			if ( matches_delete_prefix( $other, $option ) ) {
				return sprintf( 'option %s falls under the delete_option_prefixes of integration %s', $option, $other->slug );
			}
		}

		foreach ( declared_options( $other ) as $option ) {
			if ( matches_delete_prefix( $integration, $option ) ) {
				return sprintf( 'its delete_option_prefixes cover option %s, which integration %s declares', $option, $other->slug );
			}
		}
	}

	return null;
}

/**
 * Whether every value is a non-empty string.
 *
 * @param array $values The values.
 * @return bool
 */
function is_name_list( array $values ): bool {
	foreach ( $values as $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
	}

	return true;
}

/**
 * Returns the keys a partial_options entry changes: listed keys and the string keys it sets.
 *
 * @param array $keys The entry.
 * @return array
 */
function partial_option_keys( array $keys ): array {
	$names = array();
	foreach ( $keys as $key => $value ) {
		$names[] = is_int( $key ) ? $value : $key;
	}

	return $names;
}

/**
 * Returns the option names an integration declares outright.
 *
 * @param Integration $integration The integration.
 * @return string[]
 */
function declared_options( Integration $integration ): array {
	return array_merge( $integration->options, array_keys( $integration->partial_options ), array_keys( $integration->option_values ), $integration->delete_options, array_keys( $integration->delete_partial_options ) );
}

/**
 * Whether an option falls under one of an integration's delete_option_prefixes.
 *
 * @param Integration $integration The integration.
 * @param string      $option      Option name.
 * @return bool
 */
function matches_delete_prefix( Integration $integration, string $option ): bool {
	foreach ( $integration->delete_option_prefixes as $prefix => $suffixes ) {
		if ( is_int( $prefix ) ) {
			$prefix   = $suffixes;
			$suffixes = array();
		}

		if ( ! str_starts_with( $option, $prefix ) ) {
			continue;
		}

		if ( ! $suffixes ) {
			return true;
		}

		foreach ( $suffixes as $suffix ) {
			if ( str_ends_with( $option, $suffix ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Returns the stored options under an integration's delete_option_prefixes, including those only a backup is left of.
 *
 * @param Integration $integration The integration.
 * @return string[]
 */
function stored_prefix_options( Integration $integration ): array {
	global $wpdb;

	$backup_suffix = '_sn_backup';
	$options       = array();
	foreach ( $integration->delete_option_prefixes as $prefix => $suffixes ) {
		$stored = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scrubbing requires the stored option names, not their values.
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( is_int( $prefix ) ? $suffixes : $prefix ) . '%' )
		);

		foreach ( $stored as $option ) {
			if ( str_ends_with( $option, $backup_suffix ) ) {
				$option = substr( $option, 0, -strlen( $backup_suffix ) );
			}

			if ( matches_delete_prefix( $integration, $option ) && ! in_array( $option, $options, true ) ) {
				$options[] = $option;
			}
		}
	}

	return $options;
}

/**
 * Returns the options the scrub step goes through: option_scrublist.txt and every integration's options.
 *
 * @return array
 */
function options_to_clear(): array {
	$options = array_values( get_denylist_array( 'options' ) );

	foreach ( get_integrations() as $integration ) {
		foreach ( array_merge( declared_options( $integration ), stored_prefix_options( $integration ) ) as $option ) {
			if ( ! in_array( $option, $options, true ) ) {
				$options[] = $option;
			}
		}
	}

	return $options;
}

/**
 * Decides how the scrub step treats an option, from the integration that declares it.
 *
 * @param mixed $option Option name.
 * @return array 'mode' is 'blank', 'partial' with the 'keys' to change or a closure, 'value' with the 'value' to set, 'delete', or 'delete_partial' with the 'keys' to blank.
 */
function option_treatment( $option ): array {
	if ( ! is_string( $option ) ) {
		return array( 'mode' => 'blank' );
	}

	$integrations = get_integrations();
	foreach ( $integrations as $integration ) {
		if ( in_array( $option, $integration->delete_options, true ) ) {
			return array( 'mode' => 'delete' );
		}

		if ( array_key_exists( $option, $integration->delete_partial_options ) ) {
			return array(
				'mode' => 'delete_partial',
				'keys' => $integration->delete_partial_options[ $option ],
			);
		}

		if ( array_key_exists( $option, $integration->partial_options ) ) {
			return array(
				'mode' => 'partial',
				'keys' => $integration->partial_options[ $option ],
			);
		}

		if ( array_key_exists( $option, $integration->option_values ) ) {
			return array(
				'mode'  => 'value',
				'value' => $integration->option_values[ $option ],
			);
		}

		if ( in_array( $option, $integration->options, true ) ) {
			return array( 'mode' => 'blank' );
		}
	}

	// An option declared by name keeps that treatment even when its own integration's delete_option_prefixes cover it.
	foreach ( $integrations as $integration ) {
		if ( matches_delete_prefix( $integration, $option ) ) {
			return array( 'mode' => 'delete' );
		}
	}

	return array( 'mode' => 'blank' );
}

/**
 * Returns the plugin patterns the deactivate step matches: plugin_denylist.txt and every integration's plugins.
 *
 * @return array
 */
function plugin_patterns(): array {
	$patterns = get_denylist_array( 'plugins' );

	foreach ( get_integrations() as $integration ) {
		$patterns = array_merge( $patterns, $integration->plugins );
	}

	return $patterns;
}

/**
 * Returns the gateway classes, and their subclasses, that never contact a payment processor.
 *
 * @return string[]
 */
function offline_gateways(): array {
	$classes = OFFLINE_GATEWAY_CLASSES;

	foreach ( get_integrations() as $integration ) {
		$classes = array_merge( $classes, $integration->offline_gateways );
	}

	return $classes;
}

/**
 * Runs one phase's closure of every integration, in order.
 *
 * @param string $phase 'scrub', 'delete', 'hooks' or 'late'.
 * @return void
 */
function run_phase( string $phase ): void {
	if ( ! in_array( $phase, Integration::PHASES, true ) ) {
		return;
	}

	foreach ( get_integrations() as $integration ) {
		if ( null !== $integration->$phase ) {
			( $integration->$phase )();
		}
	}
}
