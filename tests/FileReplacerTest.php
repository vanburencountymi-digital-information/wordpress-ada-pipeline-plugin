<?php

namespace AdaRemediationClient\Tests;

use AdaRemediationClient\File_Replacer;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class FileReplacerTest extends TestCase
{
    private const RESULT = [
        'download_url' => 'https://pipeline.example.org/files/report.pdf',
        'pipeline_version' => '1.2.3',
    ];

    /**
     * @dataProvider autoReplaceDisabledProvider
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_maybe_replace_does_nothing_when_auto_replace_is_disabled(bool $should_define, bool $value): void
    {
        if ($should_define) {
            define('ADA_REMEDIATION_AUTO_REPLACE_FILE', $value);
        }

        WP_Mock::userFunction('wp_remote_get', ['times' => 0]);
        WP_Mock::userFunction('AdaRemediationClient\\file_put_contents', ['times' => 0]);
        WP_Mock::userFunction('wp_generate_attachment_metadata', ['times' => 0]);

        File_Replacer::maybe_replace(42, self::RESULT);

        $this->assertConditionsMet();
    }

    public static function autoReplaceDisabledProvider(): array
    {
        return [
            'constant never defined' => [false, false],
            'constant explicitly false' => [true, false],
        ];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_maybe_replace_does_nothing_when_the_result_has_been_suppressed_by_an_adapter(): void
    {
        define('ADA_REMEDIATION_AUTO_REPLACE_FILE', true);

        WP_Mock::onFilter('ada_remediation_suppress_file_replacement')
            ->with(false, 42, self::RESULT)
            ->reply(true);

        WP_Mock::userFunction('wp_remote_get', ['times' => 0]);
        WP_Mock::userFunction('AdaRemediationClient\\file_put_contents', ['times' => 0]);
        WP_Mock::userFunction('wp_generate_attachment_metadata', ['times' => 0]);

        File_Replacer::maybe_replace(42, self::RESULT);

        $this->assertConditionsMet();
    }

    /**
     * Real trigger for this: the pipeline only includes `download_url` in the webhook
     * payload when `remediation.final_output_uri` is set (remediation/tasks.py:78-79) —
     * an "AlreadyCompliant" precheck-only job (compliant before remediation ever runs,
     * no output file produced) never sets it, so Webhook::handle() resolves it to null.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_maybe_replace_does_nothing_when_there_is_no_download_url(): void
    {
        define('ADA_REMEDIATION_AUTO_REPLACE_FILE', true);
        $result = ['download_url' => null, 'pipeline_version' => '1.2.3'];

        WP_Mock::onFilter('ada_remediation_suppress_file_replacement')
            ->with(false, 42, $result)
            ->reply(false);

        WP_Mock::userFunction('wp_remote_get', ['times' => 0]);
        WP_Mock::userFunction('AdaRemediationClient\\file_put_contents', ['times' => 0]);
        WP_Mock::userFunction('wp_generate_attachment_metadata', ['times' => 0]);

        File_Replacer::maybe_replace(42, $result);

        $this->assertConditionsMet();
    }

    /**
     * End-to-end shape check for the AlreadyCompliant path specifically: this is the
     * exact $result array Webhook::handle() builds when the pipeline reports 'compliant'
     * with no `download_url` in its payload (see the previous test's docblock) — a
     * dark-green badge with nothing to swap in, not an error of any kind.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_maybe_replace_does_nothing_for_an_already_compliant_result_with_no_remediated_file(): void
    {
        define('ADA_REMEDIATION_AUTO_REPLACE_FILE', true);
        $result = [
            'status' => 'compliant',
            'badge' => 'dark-green',
            'pipeline_version' => '1.2.3',
            'verification_results' => [
                ['step' => 'precheck', 'is_compliant' => true, 'failed_rules' => []],
            ],
            'download_url' => null,
        ];

        WP_Mock::onFilter('ada_remediation_suppress_file_replacement')
            ->with(false, 42, $result)
            ->reply(false);

        WP_Mock::userFunction('wp_remote_get', ['times' => 0]);
        WP_Mock::userFunction('AdaRemediationClient\\file_put_contents', ['times' => 0]);
        WP_Mock::userFunction('wp_generate_attachment_metadata', ['times' => 0]);

        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('insert')->never();
        $GLOBALS['wpdb'] = $wpdb;

        File_Replacer::maybe_replace(42, $result);

        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_maybe_replace_overwrites_the_local_file_and_regenerates_metadata_when_enabled_and_unclaimed(): void
    {
        define('ADA_REMEDIATION_AUTO_REPLACE_FILE', true);

        WP_Mock::onFilter('ada_remediation_suppress_file_replacement')
            ->with(false, 42, self::RESULT)
            ->reply(false);

        $this->mock_wp_http_helpers();
        WP_Mock::userFunction('wp_remote_get', [
            'args' => [
                'https://pipeline.example.org/files/report.pdf',
                ['headers' => ['Authorization' => 'Token test-token']],
            ],
            'times' => 1,
            'return' => ['response' => ['code' => 200], 'body' => '%PDF-1.4 remediated bytes'],
        ]);

        WP_Mock::userFunction('get_attached_file', [
            'args' => [42],
            'return' => '/uploads/2026/09/report.pdf',
        ]);
        WP_Mock::userFunction('AdaRemediationClient\\file_put_contents', ['times' => 1])
            ->with('/uploads/2026/09/report.pdf', '%PDF-1.4 remediated bytes');

        WP_Mock::userFunction('wp_generate_attachment_metadata', [
            'args' => [42, '/uploads/2026/09/report.pdf'],
            'times' => 1,
            'return' => ['file' => 'report.pdf'],
        ]);
        WP_Mock::userFunction('wp_update_attachment_metadata', [
            'args' => [42, ['file' => 'report.pdf']],
            'times' => 1,
        ]);

        // Reachable on the very first check: no fallback HEAD request, no extra sleeps, no warning.
        WP_Mock::userFunction('AdaRemediationClient\\file_exists', [
            'args' => ['/uploads/2026/09/report.pdf'],
            'return' => true,
        ]);
        WP_Mock::userFunction('AdaRemediationClient\\sleep', ['times' => 1])
            ->with(2);
        WP_Mock::userFunction('wp_remote_head', ['times' => 0]);

        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('insert')->never();
        $GLOBALS['wpdb'] = $wpdb;

        File_Replacer::maybe_replace(42, self::RESULT);

        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_maybe_replace_logs_a_warning_and_skips_the_write_when_the_download_fails(): void
    {
        define('ADA_REMEDIATION_AUTO_REPLACE_FILE', true);

        WP_Mock::onFilter('ada_remediation_suppress_file_replacement')
            ->with(false, 42, self::RESULT)
            ->reply(false);

        $this->mock_wp_http_helpers();
        WP_Mock::userFunction('wp_remote_get', [
            'return' => ['response' => ['code' => 404], 'body' => '<html>Not Found</html>'],
        ]);

        WP_Mock::userFunction('AdaRemediationClient\\file_put_contents', ['times' => 0]);
        WP_Mock::userFunction('wp_generate_attachment_metadata', ['times' => 0]);

        WP_Mock::userFunction('get_post_meta', [
            'args' => [42, '_ada_remediation_id', true],
            'return' => 'remediation-abc-123',
        ]);
        WP_Mock::userFunction('current_time', ['return' => '2026-09-28 12:00:00']);

        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('insert')->once()->with(
            'wp_ada_remediation_log',
            Mockery::on(function (array $row): bool {
                return $row['attachment_id'] === 42
                    && $row['remediation_id'] === 'remediation-abc-123'
                    && $row['pipeline_version'] === '1.2.3'
                    && $row['warning_message'] !== null;
            }),
            Mockery::type('array')
        );
        $GLOBALS['wpdb'] = $wpdb;

        File_Replacer::maybe_replace(42, self::RESULT);

        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_maybe_replace_logs_a_warning_when_the_file_is_unreachable_after_all_backoff_attempts(): void
    {
        define('ADA_REMEDIATION_AUTO_REPLACE_FILE', true);

        WP_Mock::onFilter('ada_remediation_suppress_file_replacement')
            ->with(false, 42, self::RESULT)
            ->reply(false);

        $this->mock_wp_http_helpers();
        WP_Mock::userFunction('wp_remote_get', [
            'args' => [
                'https://pipeline.example.org/files/report.pdf',
                ['headers' => ['Authorization' => 'Token test-token']],
            ],
            'return' => ['response' => ['code' => 200], 'body' => '%PDF-1.4 remediated bytes'],
        ]);

        WP_Mock::userFunction('get_attached_file', [
            'args' => [42],
            'return' => '/uploads/2026/09/report.pdf',
        ]);
        WP_Mock::userFunction('AdaRemediationClient\\file_put_contents', ['times' => 1]);
        WP_Mock::userFunction('wp_generate_attachment_metadata', [
            'return' => ['file' => 'report.pdf'],
        ]);
        WP_Mock::userFunction('wp_update_attachment_metadata', ['times' => 1]);

        WP_Mock::userFunction('AdaRemediationClient\\file_exists', [
            'args' => ['/uploads/2026/09/report.pdf'],
            'return' => false,
        ]);
        WP_Mock::userFunction('wp_get_attachment_url', [
            'args' => [42],
            'return' => 'https://example.org/wp-content/uploads/2026/09/report.pdf',
        ]);
        WP_Mock::userFunction('wp_remote_head', [
            'args' => ['https://example.org/wp-content/uploads/2026/09/report.pdf'],
            'times' => 3,
            'return' => ['response' => ['code' => 404], 'body' => ''],
        ]);

        $sleep_calls = [];
        WP_Mock::userFunction('AdaRemediationClient\\sleep', ['times' => 3])
            ->with(Mockery::on(function (int $seconds) use (&$sleep_calls): bool {
                $sleep_calls[] = $seconds;

                return true;
            }));

        WP_Mock::userFunction('get_post_meta', [
            'args' => [42, '_ada_remediation_id', true],
            'return' => 'remediation-abc-123',
        ]);
        WP_Mock::userFunction('current_time', ['return' => '2026-09-28 12:00:00']);
        // Proves this class never touches badge/version postmeta itself.
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);

        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('insert')->once()->with(
            'wp_ada_remediation_log',
            Mockery::on(function (array $row): bool {
                return $row['warning_message'] !== null;
            }),
            Mockery::type('array')
        );
        $GLOBALS['wpdb'] = $wpdb;

        File_Replacer::maybe_replace(42, self::RESULT);

        $this->assertSame([2, 4, 8], $sleep_calls);
        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_maybe_replace_succeeds_silently_when_reachable_on_a_later_attempt(): void
    {
        define('ADA_REMEDIATION_AUTO_REPLACE_FILE', true);

        WP_Mock::onFilter('ada_remediation_suppress_file_replacement')
            ->with(false, 42, self::RESULT)
            ->reply(false);

        $this->mock_wp_http_helpers();
        WP_Mock::userFunction('wp_remote_get', [
            'return' => ['response' => ['code' => 200], 'body' => '%PDF-1.4 remediated bytes'],
        ]);

        WP_Mock::userFunction('get_attached_file', [
            'args' => [42],
            'return' => '/uploads/2026/09/report.pdf',
        ]);
        WP_Mock::userFunction('AdaRemediationClient\\file_put_contents', ['times' => 1]);
        WP_Mock::userFunction('wp_generate_attachment_metadata', [
            'return' => ['file' => 'report.pdf'],
        ]);
        WP_Mock::userFunction('wp_update_attachment_metadata', ['times' => 1]);

        // Local file missing on the first attempt (fails over to a failing HEAD request too),
        // present by the second attempt — the loop must stop there rather than trying a third time.
        $file_exists_attempt = 0;
        WP_Mock::userFunction('AdaRemediationClient\\file_exists', [
            'args' => ['/uploads/2026/09/report.pdf'],
            'times' => 2,
            'return' => function () use (&$file_exists_attempt): bool {
                $file_exists_attempt++;

                return $file_exists_attempt === 2;
            },
        ]);
        WP_Mock::userFunction('wp_get_attachment_url', [
            'args' => [42],
            'return' => 'https://example.org/wp-content/uploads/2026/09/report.pdf',
        ]);
        WP_Mock::userFunction('wp_remote_head', [
            'args' => ['https://example.org/wp-content/uploads/2026/09/report.pdf'],
            'times' => 1,
            'return' => ['response' => ['code' => 500], 'body' => ''],
        ]);

        $sleep_calls = [];
        WP_Mock::userFunction('AdaRemediationClient\\sleep', ['times' => 2])
            ->with(Mockery::on(function (int $seconds) use (&$sleep_calls): bool {
                $sleep_calls[] = $seconds;

                return true;
            }));

        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('insert')->never();
        $GLOBALS['wpdb'] = $wpdb;

        File_Replacer::maybe_replace(42, self::RESULT);

        $this->assertSame([2, 4], $sleep_calls);
        $this->assertConditionsMet();
    }

    /**
     * Registers is_wp_error()/wp_remote_retrieve_response_code()/wp_remote_retrieve_body()
     * as WP core actually implements them — deriving from the response array's own shape —
     * so one mock setup works correctly regardless of how many wp_remote_* calls a test
     * makes. Mirrors ClientTest's helper of the same name.
     */
    private function mock_wp_http_helpers(): void
    {
        // File_Replacer downloads via Client::authenticated_get(), which needs these.
        if (!defined('ADA_REMEDIATION_API_BASE_URL')) {
            define('ADA_REMEDIATION_API_BASE_URL', 'https://pipeline.example.org');
            define('ADA_REMEDIATION_API_TOKEN', 'test-token');
        }

        WP_Mock::userFunction('wp_parse_url', [
            'return' => static function (string $url) {
                return parse_url($url);
            },
        ]);
        WP_Mock::userFunction('is_wp_error', [
            'return' => static function ($thing): bool {
                return is_array($thing) && array_key_exists('errors', $thing);
            },
        ]);
        WP_Mock::userFunction('wp_remote_retrieve_response_code', [
            'return' => static function ($response) {
                return $response['response']['code'] ?? 0;
            },
        ]);
        WP_Mock::userFunction('wp_remote_retrieve_body', [
            'return' => static function ($response) {
                return $response['body'] ?? '';
            },
        ]);
    }
}
