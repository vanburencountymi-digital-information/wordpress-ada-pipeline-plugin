<?php

namespace AdaRemediationClient;

/**
 * Adapters can extend the plugin instead of using its defaults — for example,
 * calling submit_attachment() directly from their own upload hook, suppressing
 * the default add_attachment trigger, and adding their own logic (like file
 * versioning or a richer badge UI).
 */
class Client
{
    private const SUBMIT_PATH = '/api/submit-document/';

    public static function submit_attachment(int $attachment_id, array $context = []): bool
    {
        $file_contents = self::read_attachment_contents($attachment_id);

        if ($file_contents === false || $file_contents === null) {
            return false;
        }

        $filename = basename((string) get_attached_file($attachment_id)) ?: "attachment-{$attachment_id}.pdf";
        $boundary = uniqid('ada-remediation-', true);

        $fields = [
            'callback_url' => rest_url('ada-remediation/v1/callback'),
            // Unreachable today — no caller passes $context['force'] yet. Reserved for a
            // future feature that needs to force a re-run past the pipeline's
            // content-hash dedupe.
            'force' => !empty($context['force']) ? 'true' : 'false',
        ];

        $response = wp_remote_post(
            rtrim(ADA_REMEDIATION_API_BASE_URL, '/') . self::SUBMIT_PATH,
            [
                'headers' => [
                    'Authorization' => 'Token ' . ADA_REMEDIATION_API_TOKEN,
                    'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                ],
                'body' => self::build_multipart_body($boundary, $fields, $filename, $file_contents),
                'timeout' => 30,
            ]
        );

        if (is_wp_error($response)) {
            return false;
        }

        return self::is_success_status(wp_remote_retrieve_response_code($response));
    }

    /**
     * Local path first, HTTP GET of the attachment URL as fallback (e.g. a site
     * that's offloaded the file to remote storage). Returns false on any failure —
     * including a non-2xx response, so an HTML error page never gets shipped to the
     * pipeline as if it were the PDF.
     *
     * @return string|false
     */
    private static function read_attachment_contents(int $attachment_id)
    {
        $local_path = get_attached_file($attachment_id);

        if ($local_path && file_exists($local_path)) {
            return file_get_contents($local_path);
        }

        $response = wp_remote_get(wp_get_attachment_url($attachment_id));

        if (is_wp_error($response) || !self::is_success_status(wp_remote_retrieve_response_code($response))) {
            return false;
        }

        return wp_remote_retrieve_body($response);
    }

    private static function is_success_status(int $status_code): bool
    {
        return $status_code >= 200 && $status_code < 300;
    }

    private static function build_multipart_body(string $boundary, array $fields, string $filename, string $file_contents): string
    {
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
            $body .= "{$value}\r\n";
        }

        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: application/pdf\r\n\r\n";
        $body .= $file_contents . "\r\n";
        $body .= "--{$boundary}--\r\n";

        return $body;
    }
}
