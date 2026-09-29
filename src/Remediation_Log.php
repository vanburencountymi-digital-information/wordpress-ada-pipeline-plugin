<?php

namespace AdaRemediationClient;

/**
 * Full-detail row per remediation attempt.
 * Attachment postmeta (see Client::store_submission_result and Webhook) holds
 * only the current state; this log lets a future "everything currently yellow/red"
 * tool query across attempts without depending on any site-specific content type.
 */
class Remediation_Log
{
    public const DB_VERSION = '1.0';

    public static function table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'ada_remediation_log';
    }

    public static function maybe_install(): void
    {
        if (get_option('ada_remediation_log_db_version', '') === self::DB_VERSION) {
            return;
        }

        self::install();
        update_option('ada_remediation_log_db_version', self::DB_VERSION, true);
    }

    public static function install(): void
    {
        global $wpdb;

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $table = self::table_name();
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
            created_gmt datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY attachment_id (attachment_id),
            KEY remediation_id (remediation_id)
        ) {$charset_collate};";

        dbDelta($sql);
    }

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

        $wpdb->insert(
            self::table_name(),
            [
                'attachment_id' => $attachment_id,
                'remediation_id' => $remediation_id,
                'content_hash' => $content_hash,
                'pipeline_version' => $pipeline_version,
                'badge' => $badge,
                'precheck_json' => $precheck_json,
                'postcheck_json' => $postcheck_json,
                'created_gmt' => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }
}
