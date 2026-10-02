<?php
/**
 * Full-detail log of every remediation attempt.
 *
 * @package AdaRemediationClient
 */

namespace AdaRemediationClient;

/**
 * Full-detail row per remediation attempt.
 * Attachment postmeta (see Client::store_submission_result and Webhook) holds
 * only the current state; this log lets a future "everything currently yellow/red"
 * tool query across attempts without depending on any site-specific content type.
 */
class Remediation_Log {

	public const DB_VERSION = '1.1';

	/**
	 * The log table's fully-prefixed name.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'ada_remediation_log';
	}

	/**
	 * Creates/upgrades the log table if its schema version is out of date.
	 */
	public static function maybe_install(): void {
		if ( get_option( 'ada_remediation_log_db_version', '' ) === self::DB_VERSION ) {
			return;
		}

		self::install();
		update_option( 'ada_remediation_log_db_version', self::DB_VERSION, true );
	}

	/**
	 * Creates/upgrades the log table via dbDelta().
	 */
	public static function install(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            remediation_id varchar(64) NOT NULL DEFAULT '',
            content_hash varchar(64) NOT NULL DEFAULT '',
            pipeline_version varchar(20) NOT NULL DEFAULT '',
            badge varchar(20) NOT NULL DEFAULT '',
            precheck_json longtext NULL,
            postcheck_json longtext NULL,
            warning_message text NULL,
            created_gmt datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY attachment_id (attachment_id),
            KEY remediation_id (remediation_id)
        ) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Logs one full remediation attempt.
	 *
	 * @param int         $attachment_id    The attachment this attempt is for.
	 * @param string      $remediation_id   The pipeline's remediation ID.
	 * @param string      $content_hash     The pipeline's content-hash ID for the file.
	 * @param string      $pipeline_version The pipeline version that produced this result.
	 * @param string      $badge            The derived badge color for this attempt.
	 * @param string|null $precheck_json    The precheck verification step's JSON, if any.
	 * @param string|null $postcheck_json   The postcheck verification step's JSON, if any.
	 */
	public static function record(
		int $attachment_id,
		string $remediation_id,
		string $content_hash,
		string $pipeline_version,
		string $badge,
		?string $precheck_json,
		?string $postcheck_json
	): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a plugin-owned custom table has no WP API equivalent to $wpdb->insert().
		$wpdb->insert(
			self::table_name(),
			array(
				'attachment_id'    => $attachment_id,
				'remediation_id'   => $remediation_id,
				'content_hash'     => $content_hash,
				'pipeline_version' => $pipeline_version,
				'badge'            => $badge,
				'precheck_json'    => $precheck_json,
				'postcheck_json'   => $postcheck_json,
				'created_gmt'      => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Narrow, free-text logging path for non-fatal issues that aren't a real
	 * verification attempt (e.g. File_Replacer's reachability check) — kept separate
	 * from record() so precheck_json/postcheck_json keep meaning "a verification
	 * step's JSON," not overloaded with warning text.
	 *
	 * @param int    $attachment_id    The attachment this warning is about.
	 * @param string $remediation_id   The pipeline's remediation ID, if known.
	 * @param string $pipeline_version The pipeline version, if known.
	 * @param string $message          The free-text warning message.
	 */
	public static function record_warning(
		int $attachment_id,
		string $remediation_id,
		string $pipeline_version,
		string $message
	): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a plugin-owned custom table has no WP API equivalent to $wpdb->insert().
		$wpdb->insert(
			self::table_name(),
			array(
				'attachment_id'    => $attachment_id,
				'remediation_id'   => $remediation_id,
				'pipeline_version' => $pipeline_version,
				'warning_message'  => $message,
				'created_gmt'      => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}
}
