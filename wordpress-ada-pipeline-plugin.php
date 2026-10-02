<?php
/**
 * Plugin Name: WordPress ADA Pipeline Plugin
 * Description: Submits uploaded PDFs to an ADA remediation pipeline (https://github.com/vanburencountymi-digital-information/dice-document-pipeline-api) and reports back remediation status.
 * Version: 0.1.0
 * Requires at least: 6.8
 * Requires PHP: 7.4
 * Author: Van Buren County Digital Information Department
 * Author URI: https://vanburencountymi.gov/departments/departments-offices/digital-information/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package AdaRemediationClient
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// No runtime Composer dependencies, so no vendor/autoload.php to require here —
// deploy.sh ships the tracked tree as-is with no build step, and vendor/ is
// gitignored dev/test tooling only. This mirrors composer.json's own PSR-4
// mapping (AdaRemediationClient\ -> src/) for the one place that actually matters
// on a live site.
spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'AdaRemediationClient\\';
		if ( strncmp( $prefix, $class_name, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );
		$file           = __DIR__ . '/src/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

\AdaRemediationClient\Plugin::boot();
