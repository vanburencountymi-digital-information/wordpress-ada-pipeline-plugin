<?php

namespace AdaRemediationClient\Tests;

use AdaRemediationClient\Webhook;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class WebhookTest extends TestCase
{
    public function test_register_routes_registers_the_callback_route(): void
    {
        WP_Mock::userFunction('register_rest_route', ['times' => 1])
            ->with(
                'ada-remediation/v1',
                '/callback',
                Mockery::on(function (array $args): bool {
                    return $args['methods'] === 'POST'
                        && $args['callback'] instanceof \Closure
                        && $args['permission_callback'] instanceof \Closure;
                })
            );

        Webhook::register_routes();
        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_verify_signature_accepts_a_valid_signature(): void
    {
        define('ADA_REMEDIATION_WEBHOOK_SECRET', 'test-secret');
        $body = '{"remediation_id":"abc"}';
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'test-secret');

        $this->assertTrue(Webhook::verify_signature($body, $signature));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_verify_signature_rejects_a_tampered_body(): void
    {
        define('ADA_REMEDIATION_WEBHOOK_SECRET', 'test-secret');
        $signature = 'sha256=' . hash_hmac('sha256', '{"remediation_id":"abc"}', 'test-secret');

        // Body differs from what the signature was actually computed over.
        $this->assertFalse(Webhook::verify_signature('{"remediation_id":"tampered"}', $signature));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_verify_signature_rejects_a_tampered_signature(): void
    {
        define('ADA_REMEDIATION_WEBHOOK_SECRET', 'test-secret');
        $body = '{"remediation_id":"abc"}';

        $this->assertFalse(Webhook::verify_signature($body, 'sha256=' . str_repeat('0', 64)));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_verify_signature_rejects_a_missing_header(): void
    {
        define('ADA_REMEDIATION_WEBHOOK_SECRET', 'test-secret');

        $this->assertFalse(Webhook::verify_signature('{"remediation_id":"abc"}', null));
    }

    public function test_handle_rejects_malformed_payload(): void
    {
        WP_Mock::userFunction('get_posts', ['times' => 0]);
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);

        $outcome = Webhook::handle('not json');

        $this->assertFalse($outcome['ok']);
        $this->assertSame(400, $outcome['status']);
        $this->assertConditionsMet();
    }

    public function test_handle_rejects_a_payload_missing_remediation_id(): void
    {
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);

        $outcome = Webhook::handle('{"status":"compliant"}');

        $this->assertFalse($outcome['ok']);
        $this->assertSame(400, $outcome['status']);
    }

    public function test_handle_returns_404_when_no_attachment_matches_the_remediation_id(): void
    {
        WP_Mock::userFunction('get_posts', ['return' => []]);
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);

        $outcome = Webhook::handle('{"remediation_id":"unknown-id","status":"compliant"}');

        $this->assertFalse($outcome['ok']);
        $this->assertSame(404, $outcome['status']);
    }

    /**
     * @dataProvider severityBadgeProvider
     */
    public function test_handle_derives_the_correct_badge_when_status_is_noncompliant(array $failed_rules, string $expected_badge): void
    {
        $payload = json_encode([
            'remediation_id' => 'remediation-abc-123',
            'document_id' => 'deadbeef',
            'status' => 'noncompliant',
            'pipeline_version' => '1.2.3',
            'verification_results' => [
                ['step' => 'precheck', 'is_compliant' => false, 'failed_rules' => [['severity' => 'critical']]],
                ['step' => 'postcheck', 'is_compliant' => false, 'failed_rules' => $failed_rules],
            ],
        ]);

        $this->expect_badge_write(42, $expected_badge, 'remediation-abc-123');
        WP_Mock::userFunction('wp_json_encode', [
            'return' => static function ($data) {
                return json_encode($data);
            },
        ]);
        WP_Mock::userFunction('get_posts', [
            'return' => [42],
        ])->with(Mockery::on(function (array $query): bool {
            return $query['meta_key'] === '_ada_remediation_id' && $query['meta_value'] === 'remediation-abc-123';
        }));

        WP_Mock::userFunction('AdaRemediationClient\\do_action', ['times' => 1])
            ->with('ada_remediation_result', 42, Mockery::on(function (array $result) use ($expected_badge): bool {
                return $result['badge'] === $expected_badge && $result['status'] === 'noncompliant';
            }));

        $outcome = Webhook::handle($payload);

        $this->assertTrue($outcome['ok']);
        $this->assertSame(['status' => 'ok'], $outcome['body']);
        $this->assertConditionsMet();
    }

    public static function severityBadgeProvider(): array
    {
        return [
            'minor -> light-green' => [[['severity' => 'minor']], 'light-green'],
            'major -> yellow' => [[['severity' => 'major']], 'yellow'],
            'critical -> red' => [[['severity' => 'critical']], 'red'],
            'unclassified -> red' => [[['severity' => 'unclassified']], 'red'],
        ];
    }

    /**
     * COMPLIANT covers both postcheck passing and precheck already being compliant
     * (AlreadyCompliant ends the job before postcheck ever runs) — the pipeline unifies
     * both into one status (ADR 0024), so no verification_results shape needs checking.
     */
    public function test_handle_badges_dark_green_when_status_is_compliant(): void
    {
        $payload = json_encode([
            'remediation_id' => 'remediation-abc-123',
            'document_id' => 'deadbeef',
            'status' => 'compliant',
            'pipeline_version' => '1.2.3',
            'verification_results' => [
                ['step' => 'precheck', 'is_compliant' => true, 'failed_rules' => []],
            ],
        ]);

        $this->expect_badge_write(42, 'dark-green', 'remediation-abc-123');
        WP_Mock::userFunction('wp_json_encode', [
            'return' => static function ($data) {
                return json_encode($data);
            },
        ]);
        WP_Mock::userFunction('get_posts', ['return' => [42]]);

        WP_Mock::userFunction('AdaRemediationClient\\do_action', ['times' => 1])
            ->with('ada_remediation_result', 42, Mockery::on(function (array $result): bool {
                return $result['badge'] === 'dark-green' && $result['status'] === 'compliant';
            }));

        $outcome = Webhook::handle($payload);
        $this->assertTrue($outcome['ok']);
        $this->assertConditionsMet();
    }

    /**
     * ERROR covers a genuine crash and postcheck's own adapter failing (PostCheckUnavailable)
     * — either way, no verdict exists to derive a real badge from.
     */
    public function test_handle_badges_error_when_status_is_error(): void
    {
        $payload = json_encode([
            'remediation_id' => 'remediation-abc-123',
            'document_id' => 'deadbeef',
            'status' => 'error',
            'pipeline_version' => '1.2.3',
            'error' => 'pipeline blew up',
        ]);

        $this->expect_badge_write(42, 'error', 'remediation-abc-123');
        WP_Mock::userFunction('get_posts', ['return' => [42]]);

        WP_Mock::userFunction('AdaRemediationClient\\do_action', ['times' => 1])
            ->with('ada_remediation_result', 42, Mockery::on(function (array $result): bool {
                return $result['badge'] === 'error' && $result['status'] === 'error';
            }));

        $outcome = Webhook::handle($payload);
        $this->assertTrue($outcome['ok']);
        $this->assertConditionsMet();
    }

    /**
     * SKIPPED means postcheck was disabled — no verdict either, so it collapses into the
     * same 'error' badge as ERROR (PLANNING's badge scheme has no separate slot for
     * "deliberately not checked").
     */
    public function test_handle_badges_error_when_status_is_skipped(): void
    {
        $payload = json_encode([
            'remediation_id' => 'remediation-abc-123',
            'document_id' => 'deadbeef',
            'status' => 'skipped',
            'pipeline_version' => '1.2.3',
        ]);

        $this->expect_badge_write(42, 'error', 'remediation-abc-123');
        WP_Mock::userFunction('get_posts', ['return' => [42]]);

        WP_Mock::userFunction('AdaRemediationClient\\do_action', ['times' => 1])
            ->with('ada_remediation_result', 42, Mockery::on(function (array $result): bool {
                return $result['badge'] === 'error' && $result['status'] === 'skipped';
            }));

        $outcome = Webhook::handle($payload);
        $this->assertTrue($outcome['ok']);
        $this->assertConditionsMet();
    }

    /**
     * DIC-2040: register_routes() passes the attachment_id embedded in the signed request's
     * callback_url as a hint. When it checks out against the payload's own remediation_id,
     * it's used directly — no need to fall back to searching, which matters once two
     * attachments can share one remediation_id (content-hash dedup).
     */
    public function test_handle_uses_the_attachment_id_hint_when_it_matches_the_payload(): void
    {
        $payload = json_encode([
            'remediation_id' => 'remediation-abc-123',
            'document_id' => 'deadbeef',
            'status' => 'compliant',
            'pipeline_version' => '1.2.3',
        ]);

        WP_Mock::userFunction('get_post_meta')
            ->with(94616, '_ada_remediation_id', true)
            ->andReturn('remediation-abc-123');
        WP_Mock::userFunction('get_posts', ['times' => 0]);

        $this->expect_badge_write(94616, 'dark-green', 'remediation-abc-123');

        WP_Mock::userFunction('AdaRemediationClient\\do_action', ['times' => 1])
            ->with('ada_remediation_result', 94616, Mockery::type('array'));

        $outcome = Webhook::handle($payload, 94616);

        $this->assertTrue($outcome['ok']);
        $this->assertConditionsMet();
    }

    /**
     * A stale/mismatched hint (e.g. an old callback_url, or two attachments that both once
     * pointed at this remediation_id before one got superseded) must not be trusted blindly —
     * falls back to the existing search-by-remediation_id behavior instead.
     */
    public function test_handle_falls_back_to_searching_when_the_hint_attachment_does_not_match(): void
    {
        $payload = json_encode([
            'remediation_id' => 'remediation-abc-123',
            'document_id' => 'deadbeef',
            'status' => 'compliant',
            'pipeline_version' => '1.2.3',
        ]);

        WP_Mock::userFunction('get_post_meta')
            ->with(94616, '_ada_remediation_id', true)
            ->andReturn('some-other-remediation-id');
        WP_Mock::userFunction('get_posts', ['return' => [94608]])
            ->with(Mockery::on(function (array $query): bool {
                return $query['meta_key'] === '_ada_remediation_id' && $query['meta_value'] === 'remediation-abc-123';
            }));

        $this->expect_badge_write(94608, 'dark-green', 'remediation-abc-123');

        WP_Mock::userFunction('AdaRemediationClient\\do_action', ['times' => 1])
            ->with('ada_remediation_result', 94608, Mockery::type('array'));

        $outcome = Webhook::handle($payload, 94616);

        $this->assertTrue($outcome['ok']);
        $this->assertConditionsMet();
    }

    /**
     * A retry or a replayed signed body must not re-run the result hooks: an adapter's file swap
     * would create another attachment each time.
     */
    public function test_handle_ignores_a_repeat_delivery_of_a_result_it_already_applied(): void
    {
        $payload = json_encode([
            'remediation_id' => 'remediation-abc-123',
            'document_id' => 'deadbeef',
            'status' => 'compliant',
            'pipeline_version' => '1.2.3',
        ]);

        WP_Mock::userFunction('get_posts', ['return' => [42]]);
        WP_Mock::userFunction('get_post_meta')
            ->with(42, '_ada_remediation_applied_id', true)
            ->andReturn('remediation-abc-123');
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);
        WP_Mock::userFunction('AdaRemediationClient\\do_action', ['times' => 0]);

        $outcome = Webhook::handle($payload);

        $this->assertTrue($outcome['ok']);
        $this->assertSame(['status' => 'ok', 'duplicate' => true], $outcome['body']);
        $this->assertConditionsMet();
    }

    /**
     * A new job for the same file (a resubmit) has a different remediation_id, so it must still apply.
     */
    public function test_handle_still_applies_a_new_job_for_a_file_that_already_had_a_result(): void
    {
        $payload = json_encode([
            'remediation_id' => 'remediation-NEW',
            'document_id' => 'deadbeef',
            'status' => 'compliant',
            'pipeline_version' => '1.2.3',
        ]);

        WP_Mock::userFunction('get_posts', ['return' => [42]]);
        WP_Mock::userFunction('get_post_meta')
            ->with(42, '_ada_remediation_applied_id', true)
            ->andReturn('remediation-OLD');
        $this->expect_badge_write(42, 'dark-green', 'remediation-NEW');
        WP_Mock::userFunction('AdaRemediationClient\\do_action', ['times' => 1]);

        $outcome = Webhook::handle($payload);

        $this->assertTrue($outcome['ok']);
        $this->assertArrayNotHasKey('duplicate', $outcome['body']);
        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_verify_signature_never_accepts_anything_when_the_secret_is_empty(): void
    {
        define('ADA_REMEDIATION_WEBHOOK_SECRET', '');
        $body = '{"remediation_id":"x"}';
        // What an attacker could compute for themselves against an empty key.
        $forged = 'sha256=' . hash_hmac('sha256', $body, '');

        $this->assertFalse(Webhook::verify_signature($body, $forged));
    }

    private function expect_badge_write(int $attachment_id, string $expected_badge, string $remediation_id): void
    {
        // The result hasn't been applied before, and is marked applied once it has.
        WP_Mock::userFunction('get_post_meta')
            ->with($attachment_id, '_ada_remediation_applied_id', true)
            ->andReturn('');
        WP_Mock::userFunction('update_post_meta')
            ->with($attachment_id, '_ada_remediation_applied_id', $remediation_id)
            ->once();
        WP_Mock::userFunction('update_post_meta')
            ->with($attachment_id, '_ada_remediation_badge', $expected_badge)
            ->once();
        WP_Mock::userFunction('update_post_meta')
            ->with($attachment_id, '_ada_remediation_checked_at', Mockery::type('string'))
            ->once();
        WP_Mock::userFunction('current_time', ['return' => '2026-09-28 12:00:00']);

        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('insert')->once()->with(
            'wp_ada_remediation_log',
            Mockery::on(function (array $row) use ($expected_badge, $remediation_id): bool {
                return $row['badge'] === $expected_badge && $row['remediation_id'] === $remediation_id;
            }),
            Mockery::type('array')
        );
        $GLOBALS['wpdb'] = $wpdb;
    }
}
