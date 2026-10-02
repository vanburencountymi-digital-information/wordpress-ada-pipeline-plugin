<?php

namespace AdaRemediationClient;

/**
 * Receives the pipeline's signed result callback. Registered on rest_api_init by
 * Plugin::boot(). verify_signature()/handle() take plain strings/arrays.
 * The two small closures in register_routes() are the only place a real WP_REST_Request
 * or WP_Error is touched, and are thin enough (pure delegation, no branching) not to
 * need their own tests.
 */
class Webhook
{
    public const ROUTE_NAMESPACE = 'ada-remediation/v1';
    public const ROUTE = '/callback';
    public const SIGNATURE_HEADER = 'X-Webhook-Signature';
    public const SIGNATURE_PREFIX = 'sha256=';

    public static function register_routes(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE, [
            'methods' => 'POST',
            'callback' => static function (\WP_REST_Request $request) {
                $outcome = self::handle((string) $request->get_body(), (int) $request->get_param('attachment_id'));

                if (!$outcome['ok']) {
                    return new \WP_Error($outcome['code'], $outcome['message'], ['status' => $outcome['status']]);
                }

                return $outcome['body'];
            },
            'permission_callback' => static function (\WP_REST_Request $request): bool {
                return self::verify_signature((string) $request->get_body(), $request->get_header(self::SIGNATURE_HEADER));
            },
        ]);
    }

    /**
     * HMAC over the raw body, keyed by ADA_REMEDIATION_WEBHOOK_SECRET — replicates
     * dice-document-pipeline-api's remediation/webhook_client.py signing exactly.
     */
    public static function verify_signature(string $raw_body, ?string $signature_header): bool
    {
        $signature_header = (string) $signature_header;

        if (strncmp($signature_header, self::SIGNATURE_PREFIX, strlen(self::SIGNATURE_PREFIX)) !== 0) {
            return false;
        }

        $expected = hash_hmac('sha256', $raw_body, ADA_REMEDIATION_WEBHOOK_SECRET);
        $provided = substr($signature_header, strlen(self::SIGNATURE_PREFIX));

        return hash_equals($expected, $provided);
    }

    /**
     * @param int $attachment_id_hint The attachment_id Client::submit_attachment() embedded in
     *                                this submission's callback_url (DIC-2040). Trusted only
     *                                after confirming it actually points at this remediation_id
     *                                — the query string isn't covered by the HMAC signature, so
     *                                a stale/mismatched hint falls back to the pre-existing
     *                                search instead of being used blindly.
     * @return array{ok: bool, status?: int, code?: string, message?: string, body?: array}
     */
    public static function handle(string $raw_body, int $attachment_id_hint = 0): array
    {
        $data = json_decode($raw_body, true);

        if (!is_array($data) || empty($data['remediation_id'])) {
            return self::error_outcome(400, 'ada_remediation_invalid_payload', 'Malformed webhook payload.');
        }

        $attachment_id = self::resolve_attachment_id($attachment_id_hint, (string) $data['remediation_id']);

        if (!$attachment_id) {
            return self::error_outcome(404, 'ada_remediation_unknown_remediation', 'No attachment found for this remediation_id.');
        }

        $badge = self::derive_badge($data);

        update_post_meta($attachment_id, '_ada_remediation_badge', $badge);
        update_post_meta($attachment_id, '_ada_remediation_checked_at', current_time('mysql', true));

        Remediation_Log::record(
            $attachment_id,
            (string) $data['remediation_id'],
            (string) ($data['document_id'] ?? ''),
            (string) ($data['pipeline_version'] ?? ''),
            $badge,
            self::step_json($data, 'precheck'),
            self::step_json($data, 'postcheck')
        );

        $result = [
            'status' => $data['status'] ?? '',
            'badge' => $badge,
            'pipeline_version' => $data['pipeline_version'] ?? '',
            'verification_results' => $data['verification_results'] ?? [],
            'download_url' => $data['download_url'] ?? null,
        ];

        do_action('ada_remediation_result', $attachment_id, $result);

        return ['ok' => true, 'body' => ['status' => 'ok']];
    }

    private static function error_outcome(int $status, string $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }

    private static function resolve_attachment_id(int $attachment_id_hint, string $remediation_id): int
    {
        if ($attachment_id_hint > 0 && get_post_meta($attachment_id_hint, '_ada_remediation_id', true) === $remediation_id) {
            return $attachment_id_hint;
        }

        return self::find_attachment_id($remediation_id);
    }

    private static function find_attachment_id(string $remediation_id): int
    {
        $posts = get_posts([
            'post_type' => 'attachment',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_ada_remediation_id',
            'meta_value' => $remediation_id,
        ]);

        return isset($posts[0]) ? (int) $posts[0] : 0;
    }

    /**
     * The pipeline's status is unambiguous as of ADR 0024 (dice-document-pipeline-api) —
     * COMPLIANT (postcheck passed, or precheck was already compliant) and NONCOMPLIANT
     * (postcheck ran, real failed_rules exist) are now distinct from ERROR (a crash, or
     * postcheck's own adapter failing via PostCheckUnavailable — no verdict at all) and
     * SKIPPED (postcheck disabled — also no verdict). No need to infer anything from
     * verification_results' shape; branch on status directly. SKIPPED collapses into the
     * same 'error' badge as ERROR for now — PLANNING's badge scheme has no slot for
     * "deliberately not checked," and both mean "nothing to show" from a badge's perspective.
     */
    private static function derive_badge(array $data): string
    {
        $status = $data['status'] ?? '';

        if ($status === 'noncompliant') {
            $postcheck = self::find_step($data, 'postcheck');

            return self::badge_for_severity(self::worst_severity($postcheck['failed_rules'] ?? []));
        }

        if ($status === 'compliant') {
            return 'dark-green';
        }

        return 'error';
    }

    private static function worst_severity(array $failed_rules): ?string
    {
        $present = array_column($failed_rules, 'severity');

        foreach (['critical', 'major', 'minor', 'unclassified'] as $severity) {
            if (in_array($severity, $present, true)) {
                return $severity;
            }
        }

        return null;
    }

    private static function badge_for_severity(?string $severity): string
    {
        switch ($severity) {
            case 'minor':
                return 'light-green';
            case 'major':
                return 'yellow';
            case 'critical':
            case 'unclassified':
                return 'red';
            default:
                return 'dark-green';
        }
    }

    private static function find_step(array $data, string $step): ?array
    {
        foreach ($data['verification_results'] ?? [] as $result) {
            if (($result['step'] ?? '') === $step) {
                return $result;
            }
        }

        return null;
    }

    private static function step_json(array $data, string $step): ?string
    {
        $result = self::find_step($data, $step);

        return $result === null ? null : json_encode($result);
    }
}
