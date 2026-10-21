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
        $this->expect_warning_row(0, 'unknown-id');

        $outcome = Webhook::handle('{"remediation_id":"unknown-id","status":"compliant"}');

        $this->assertFalse($outcome['ok']);
        $this->assertSame(404, $outcome['status']);
        $this->assertConditionsMet();
    }

    /**
     * DIC-2184: the submit response was lost, so the hinted attachment has the error badge and no
     * stored id. The pipeline's result is still applied, and the ids are stored.
     */
    public function test_handle_salvages_a_result_for_a_hint_whose_submit_response_was_lost(): void
    {
        $payload = json_encode([
            'remediation_id' => 'remediation-abc-123',
            'document_id' => 'deadbeef',
            'status' => 'compliant',
            'pipeline_version' => '1.2.3',
        ]);

        WP_Mock::userFunction('get_post_meta')->with(13364, '_ada_remediation_id', true)->andReturn('');
        WP_Mock::userFunction('get_post_meta')->with(13364, '_ada_remediation_badge', true)->andReturn('error');
        WP_Mock::userFunction('get_post_type')->with(13364)->andReturn('attachment');
        WP_Mock::userFunction('get_posts', ['return' => []]);
        WP_Mock::userFunction('update_post_meta')
            ->with(13364, '_ada_remediation_id', 'remediation-abc-123')->once();
        WP_Mock::userFunction('update_post_meta')
            ->with(13364, '_ada_remediation_content_hash', 'deadbeef')->once();
        $this->expect_badge_write(13364, 'dark-green', 'remediation-abc-123');
        $this->expect_result_queued(13364);

        $outcome = Webhook::handle($payload, 13364);

        $this->assertTrue($outcome['ok']);
        $this->assertConditionsMet();
    }

    /**
     * The hint isn't signed. A replayed body whose job is already on file A, sent with the hint of
     * a failed file B, must land on A (and be skipped as a duplicate), never be salvaged onto B.
     */
    public function test_handle_routes_a_replay_to_the_file_that_already_has_the_job_not_the_hinted_one(): void
    {
        $payload = '{"remediation_id":"remediation-abc-123","status":"compliant"}';

        WP_Mock::userFunction('get_post_meta')->with(13364, '_ada_remediation_id', true)->andReturn('');
        WP_Mock::userFunction('get_post_meta')->with(42, '_ada_remediation_applied_id', true)->andReturn('remediation-abc-123');
        WP_Mock::userFunction('get_posts', ['return' => [42]]);
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);
        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 0]);

        $outcome = Webhook::handle($payload, 13364);

        $this->assertTrue($outcome['ok']);
        $this->assertTrue($outcome['body']['duplicate']);
        $this->assertConditionsMet();
    }

    /**
     * An id-less hint is only salvaged when it is a failed submission: a non-error badge means
     * the file is in some other state and must not be taken over.
     */
    public function test_handle_does_not_salvage_a_hint_without_the_error_badge(): void
    {
        WP_Mock::userFunction('get_post_meta')->with(13364, '_ada_remediation_id', true)->andReturn('');
        WP_Mock::userFunction('get_post_meta')->with(13364, '_ada_remediation_badge', true)->andReturn('queued');
        WP_Mock::userFunction('get_post_type')->with(13364)->andReturn('attachment');
        WP_Mock::userFunction('get_posts', ['return' => []]);
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);
        $this->expect_warning_row(13364, 'remediation-abc-123');

        $outcome = Webhook::handle('{"remediation_id":"remediation-abc-123","status":"compliant"}', 13364);

        $this->assertFalse($outcome['ok']);
        $this->assertSame(404, $outcome['status']);
        $this->assertConditionsMet();
    }

    /**
     * A hint pointing at a different job's id is never salvaged: an older result must not
     * overwrite a newer one.
     */
    public function test_handle_does_not_salvage_a_hint_with_a_different_stored_id(): void
    {
        WP_Mock::userFunction('get_post_meta')->with(13364, '_ada_remediation_id', true)->andReturn('newer-job');
        WP_Mock::userFunction('get_posts', ['return' => []]);
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);
        $this->expect_warning_row(13364, 'older-job');

        $outcome = Webhook::handle('{"remediation_id":"older-job","status":"compliant"}', 13364);

        $this->assertFalse($outcome['ok']);
        $this->assertSame(404, $outcome['status']);
        $this->assertConditionsMet();
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

        $this->expect_result_queued(42, function (array $result) use ($expected_badge): bool {
                return $result['badge'] === $expected_badge && $result['status'] === 'noncompliant';
            });

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

        $this->expect_result_queued(42, function (array $result): bool {
                return $result['badge'] === 'dark-green' && $result['status'] === 'compliant';
            });

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

        $this->expect_result_queued(42, function (array $result): bool {
                return $result['badge'] === 'error' && $result['status'] === 'error';
            });

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

        $this->expect_result_queued(42, function (array $result): bool {
                return $result['badge'] === 'error' && $result['status'] === 'skipped';
            });

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

        $this->expect_result_queued(94616);

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

        $this->expect_result_queued(94608);

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
        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 0]);

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
        $this->expect_result_queued(42);

        $outcome = Webhook::handle($payload);

        $this->assertTrue($outcome['ok']);
        $this->assertArrayNotHasKey('duplicate', $outcome['body']);
        $this->assertConditionsMet();
    }

    /**
     * The adapters' hook is queued for WP-Cron, not fired inside the webhook request.
     *
     * @param callable|null $result_check Extra check on the queued result array.
     */
    private function expect_result_queued(int $attachment_id, ?callable $result_check = null): void
    {
        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 1])
            ->with(
                Mockery::type('int'),
                Webhook::APPLY_RESULT_HOOK,
                Mockery::on(function (array $args) use ($attachment_id, $result_check): bool {
                    return $args[0] === $attachment_id
                        && is_array($args[1])
                        && (null === $result_check || $result_check($args[1]));
                })
            )
            ->andReturn(true);
        WP_Mock::userFunction('spawn_cron', ['times' => 1]);
    }

    /**
     * A file swap can take 30s+ and the pipeline gives up after about 10s, so the result hooks
     * must not run inside the webhook request.
     */
    public function test_handle_queues_the_result_hooks_instead_of_running_them(): void
    {
        WP_Mock::userFunction('get_posts', ['return' => [42]]);
        $this->expect_badge_write(42, 'dark-green', 'remediation-abc-123');
        $this->expect_result_queued(42);
        WP_Mock::userFunction('AdaRemediationClient\\do_action', ['times' => 0]);

        $outcome = Webhook::handle('{"remediation_id":"remediation-abc-123","status":"compliant"}');

        $this->assertTrue($outcome['ok']);
        $this->assertConditionsMet();
    }

    /**
     * Losing the result silently would be worse than answering slowly.
     */
    public function test_handle_runs_the_result_hooks_now_if_they_cannot_be_scheduled(): void
    {
        WP_Mock::userFunction('get_posts', ['return' => [42]]);
        $this->expect_badge_write(42, 'dark-green', 'remediation-abc-123');
        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 1])->andReturn(false);
        WP_Mock::userFunction('wp_next_scheduled', ['times' => 1])->andReturn(false);
        WP_Mock::userFunction('spawn_cron', ['times' => 0]);
        WP_Mock::userFunction('AdaRemediationClient\\do_action', ['times' => 1])
            ->with('ada_remediation_result', 42, Mockery::type('array'));

        $outcome = Webhook::handle('{"remediation_id":"remediation-abc-123","status":"compliant"}');

        $this->assertTrue($outcome['ok']);
        $this->assertConditionsMet();
    }

    /**
     * WP-Cron returns false for an identical event already waiting; that one will run the hooks.
     */
    public function test_handle_does_not_run_the_hooks_twice_when_an_identical_event_is_already_scheduled(): void
    {
        WP_Mock::userFunction('get_posts', ['return' => [42]]);
        $this->expect_badge_write(42, 'dark-green', 'remediation-abc-123');
        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 1])->andReturn(false);
        WP_Mock::userFunction('wp_next_scheduled', ['times' => 1])->andReturn(1792594000);
        WP_Mock::userFunction('spawn_cron', ['times' => 1]);
        WP_Mock::userFunction('AdaRemediationClient\\do_action', ['times' => 0]);

        $outcome = Webhook::handle('{"remediation_id":"remediation-abc-123","status":"compliant"}');

        $this->assertTrue($outcome['ok']);
        $this->assertConditionsMet();
    }

    public function test_run_result_hooks_fires_the_adapters_hook(): void
    {
        $result = ['status' => 'compliant', 'badge' => 'dark-green'];

        WP_Mock::userFunction('AdaRemediationClient\\do_action', ['times' => 1])
            ->with('ada_remediation_result', 42, $result);

        Webhook::run_result_hooks(42, $result);

        $this->assertConditionsMet();
    }

    public function test_run_result_hooks_logs_a_warning_when_a_listener_throws(): void
    {
        WP_Mock::userFunction('AdaRemediationClient\\do_action', ['times' => 1])
            ->andThrow(new \RuntimeException('swap blew up'));
        WP_Mock::userFunction('get_post_meta')->with(42, '_ada_remediation_id', true)->andReturn('remediation-abc-123');
        $this->expect_warning_row(42, 'remediation-abc-123');

        Webhook::run_result_hooks(42, ['pipeline_version' => '1.2.3']);

        $this->assertConditionsMet();
    }

    public function test_authorize_refuses_an_address_over_the_failure_limit_without_checking_the_signature(): void
    {
        // The secret constant is deliberately undefined: reaching verify_signature() would fail the test.
        WP_Mock::userFunction('get_transient', ['times' => 1, 'return' => 20])
            ->with('ada_remediation_webhook_failures_' . md5('203.0.113.9'));
        WP_Mock::userFunction('set_transient', ['times' => 0]);

        $outcome = Webhook::authorize('{}', 'sha256=whatever', '203.0.113.9');

        $this->assertFalse($outcome['ok']);
        $this->assertSame(429, $outcome['status']);
        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_authorize_counts_a_failed_signature_against_the_address(): void
    {
        define('ADA_REMEDIATION_WEBHOOK_SECRET', 'test-secret');
        $key = 'ada_remediation_webhook_failures_' . md5('203.0.113.9');
        WP_Mock::userFunction('get_transient', ['return' => 3])->with($key);
        WP_Mock::userFunction('set_transient', ['times' => 1])->with($key, 4, 600);

        $outcome = Webhook::authorize('{}', 'sha256=wrong', '203.0.113.9');

        $this->assertFalse($outcome['ok']);
        $this->assertSame(401, $outcome['status']);
        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_authorize_does_not_count_a_valid_signature(): void
    {
        define('ADA_REMEDIATION_WEBHOOK_SECRET', 'test-secret');
        $body = '{"remediation_id":"abc"}';
        WP_Mock::userFunction('get_transient', ['return' => 19]);
        WP_Mock::userFunction('set_transient', ['times' => 0]);

        $outcome = Webhook::authorize($body, 'sha256=' . hash_hmac('sha256', $body, 'test-secret'), '203.0.113.9');

        $this->assertTrue($outcome['ok']);
        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_authorize_does_not_throttle_when_the_address_is_unknown(): void
    {
        define('ADA_REMEDIATION_WEBHOOK_SECRET', 'test-secret');
        WP_Mock::userFunction('get_transient', ['times' => 0]);
        WP_Mock::userFunction('set_transient', ['times' => 0]);

        $outcome = Webhook::authorize('{}', 'sha256=wrong', '');

        $this->assertSame(401, $outcome['status']);
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

    private function expect_warning_row(int $attachment_id, string $remediation_id): void
    {
        WP_Mock::userFunction('current_time', ['return' => '2026-09-28 12:00:00']);

        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('insert')->once()->with(
            'wp_ada_remediation_log',
            Mockery::on(function (array $row) use ($attachment_id, $remediation_id): bool {
                return $row['attachment_id'] === $attachment_id
                    && $row['remediation_id'] === $remediation_id
                    && '' !== $row['warning_message'];
            }),
            Mockery::type('array')
        );
        $GLOBALS['wpdb'] = $wpdb;
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
