<?php

namespace AdaRemediationClient;

/**
 * Generic default for ADA_REMEDIATION_AUTO_REPLACE_FILE: overwrites the attachment's
 * file in place with the remediated bytes, unless an adapter has suppressed
 * this via the ada_remediation_suppress_file_replacement filter.
 *
 * Registered on the same 'ada_remediation_result' action an adapter would hook, rather
 * than being called directly from Webhook::handle() — see Plugin::boot(). That means
 * the badge/version postmeta Webhook::handle() already wrote is guaranteed to exist
 * before this ever runs, and "never blocks the badge/version update" is a property of
 * hook ordering rather than something this class has to defend.
 */
class File_Replacer
{
    /**
     * Must run after an adapter's own 'ada_remediation_result' listener (registered at
     * the conventional default priority 10) so its suppression filter is wired up
     * before we check it here — Layer 1 and a Layer 2 adapter are separate plugins with
     * no guaranteed load order, so priority is the only ordering guarantee available.
     */
    public const HOOK_PRIORITY = 20;

    private const REACHABILITY_BACKOFF_SECONDS = [2, 4, 8];

    public static function maybe_replace(int $attachment_id, array $result): void
    {
        if (!self::is_enabled()) {
            return;
        }

        if (self::is_suppressed($attachment_id, $result)) {
            return;
        }

        $download_url = $result['download_url'] ?? null;

        if (empty($download_url)) {
            return;
        }

        $new_contents = self::download_remote_file((string) $download_url);

        if ($new_contents === false) {
            self::log_warning($attachment_id, $result, 'Failed to download the remediated file for replacement.');

            return;
        }

        $local_path = (string) get_attached_file($attachment_id);
        file_put_contents($local_path, $new_contents);

        $metadata = wp_generate_attachment_metadata($attachment_id, $local_path);
        wp_update_attachment_metadata($attachment_id, $metadata);

        if (!self::is_reachable_within_backoff($attachment_id, $local_path)) {
            self::log_warning($attachment_id, $result, 'Replaced attachment file was not reachable after remediation.');
        }
    }

    private static function is_enabled(): bool
    {
        return defined('ADA_REMEDIATION_AUTO_REPLACE_FILE') && ADA_REMEDIATION_AUTO_REPLACE_FILE;
    }

    private static function is_suppressed(int $attachment_id, array $result): bool
    {
        return (bool) apply_filters('ada_remediation_suppress_file_replacement', false, $attachment_id, $result);
    }

    /**
     * @return string|false
     */
    private static function download_remote_file(string $url)
    {
        $response = wp_remote_get($url);

        if (is_wp_error($response) || !self::is_success_status(wp_remote_retrieve_response_code($response))) {
            return false;
        }

        return wp_remote_retrieve_body($response);
    }

    private static function is_reachable_within_backoff(int $attachment_id, string $local_path): bool
    {
        foreach (self::REACHABILITY_BACKOFF_SECONDS as $seconds) {
            sleep($seconds);

            if (self::is_reachable($attachment_id, $local_path)) {
                return true;
            }
        }

        return false;
    }

    private static function is_reachable(int $attachment_id, string $local_path): bool
    {
        if (file_exists($local_path)) {
            return true;
        }

        $response = wp_remote_head(wp_get_attachment_url($attachment_id));

        return !is_wp_error($response) && self::is_success_status(wp_remote_retrieve_response_code($response));
    }

    private static function is_success_status(int $status_code): bool
    {
        return $status_code >= 200 && $status_code < 300;
    }

    private static function log_warning(int $attachment_id, array $result, string $message): void
    {
        Remediation_Log::record_warning(
            $attachment_id,
            (string) get_post_meta($attachment_id, '_ada_remediation_id', true),
            (string) ($result['pipeline_version'] ?? ''),
            $message
        );
    }
}
