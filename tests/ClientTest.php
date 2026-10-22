<?php

namespace AdaRemediationClient\Tests;

use AdaRemediationClient\Client;
use AdaRemediationClient\Submit_Result;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class ClientTest extends TestCase
{
    /** @var string|false */
    private $previous_error_log;

    public function setUp(): void
    {
        parent::setUp();
        // Refused submits log a line; keep it out of the test output.
        $this->previous_error_log = ini_set('error_log', '/dev/null');
    }

    public function tearDown(): void
    {
        ini_set('error_log', (string) $this->previous_error_log);
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    private const SUBMIT_RESPONSE_BODY = '{"id":"remediation-abc-123","document_id":"deadbeef","pipeline_version":"1.2.3","status":"queued"}';

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_submit_attachment_reads_local_file_when_available(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();

        WP_Mock::userFunction('get_attached_file', [
            'args' => [42],
            'return' => '/uploads/2026/09/report.pdf',
            'times' => '1+',
        ]);
        WP_Mock::userFunction('AdaRemediationClient\\file_exists', [
            'args' => ['/uploads/2026/09/report.pdf'],
            'return' => true,
        ]);
        WP_Mock::userFunction('AdaRemediationClient\\file_get_contents', [
            'args' => ['/uploads/2026/09/report.pdf'],
            'return' => '%PDF-1.4 local bytes',
        ]);
        // The fallback path must never be touched when the local file is available.
        WP_Mock::userFunction('wp_remote_get', ['times' => 0]);
        WP_Mock::userFunction('rest_url', [
            'args' => ['ada-remediation/v1/callback'],
            'return' => 'https://example.org/wp-json/ada-remediation/v1/callback',
        ]);

        WP_Mock::userFunction('wp_remote_post', [
            'times' => 1,
            'return' => ['response' => ['code' => 201], 'body' => self::SUBMIT_RESPONSE_BODY],
        ]);
        WP_Mock::userFunction('update_post_meta', ['times' => '1+']);

        $this->assertTrue(Client::submit_attachment(42));
        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_submit_attachment_falls_back_to_attachment_url_when_local_file_is_missing(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();

        WP_Mock::userFunction('get_attached_file', [
            'args' => [42],
            'return' => '/uploads/2026/09/report.pdf',
            'times' => '1+',
        ]);
        WP_Mock::userFunction('AdaRemediationClient\\file_exists', [
            'args' => ['/uploads/2026/09/report.pdf'],
            'return' => false,
        ]);
        // Local read must never be attempted once file_exists() says no.
        WP_Mock::userFunction('AdaRemediationClient\\file_get_contents', ['times' => 0]);

        WP_Mock::userFunction('wp_get_attachment_url', [
            'args' => [42],
            'return' => 'https://example.org/wp-content/uploads/2026/09/report.pdf',
        ]);
        WP_Mock::userFunction('wp_remote_get', [
            'args' => ['https://example.org/wp-content/uploads/2026/09/report.pdf'],
            'times' => 1,
            'return' => ['response' => ['code' => 200], 'body' => '%PDF-1.4 remote bytes'],
        ]);
        WP_Mock::userFunction('rest_url', [
            'return' => 'https://example.org/wp-json/ada-remediation/v1/callback',
        ]);

        WP_Mock::userFunction('wp_remote_post', [
            'times' => 1,
            'return' => ['response' => ['code' => 201], 'body' => self::SUBMIT_RESPONSE_BODY],
        ]);
        WP_Mock::userFunction('update_post_meta', ['times' => '1+']);

        $this->assertTrue(Client::submit_attachment(42));
        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_submit_attachment_sends_expected_auth_header_and_multipart_payload(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();

        WP_Mock::userFunction('get_attached_file', [
            'return' => '/uploads/2026/09/report.pdf',
        ]);
        WP_Mock::userFunction('AdaRemediationClient\\file_exists', ['return' => true]);
        WP_Mock::userFunction('AdaRemediationClient\\file_get_contents', ['return' => '%PDF-1.4 local bytes']);
        WP_Mock::userFunction('rest_url', [
            'return' => 'https://example.org/wp-json/ada-remediation/v1/callback',
        ]);
        WP_Mock::userFunction('update_post_meta', ['times' => '1+']);

        WP_Mock::userFunction('wp_remote_post', [
            'times' => 1,
            'return' => ['response' => ['code' => 201], 'body' => self::SUBMIT_RESPONSE_BODY],
        ])->with(
            'https://pipeline.example.org/api/submit-document/',
            Mockery::on(function (array $request): bool {
                if ($request['headers']['Authorization'] !== 'Token test-token') {
                    return false;
                }

                if (strpos($request['headers']['Content-Type'], 'multipart/form-data; boundary=') !== 0) {
                    return false;
                }

                $body = $request['body'];

                return strpos($body, 'name="file"; filename="report.pdf"') !== false
                    && strpos($body, '%PDF-1.4 local bytes') !== false
                    && strpos($body, 'name="callback_url"') !== false
                    && strpos($body, 'https://example.org/wp-json/ada-remediation/v1/callback') !== false;
            })
        );

        $this->assertTrue(Client::submit_attachment(42));
        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_submit_attachment_stores_the_remediation_id_and_content_hash_from_the_response(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();

        WP_Mock::userFunction('get_attached_file', ['return' => '/uploads/2026/09/report.pdf']);
        WP_Mock::userFunction('AdaRemediationClient\\file_exists', ['return' => true]);
        WP_Mock::userFunction('AdaRemediationClient\\file_get_contents', ['return' => '%PDF-1.4 local bytes']);
        WP_Mock::userFunction('rest_url', [
            'return' => 'https://example.org/wp-json/ada-remediation/v1/callback',
        ]);
        WP_Mock::userFunction('wp_remote_post', [
            'return' => ['response' => ['code' => 201], 'body' => self::SUBMIT_RESPONSE_BODY],
        ]);

        WP_Mock::userFunction('update_post_meta')
            ->with(42, '_ada_remediation_id', 'remediation-abc-123')
            ->once();
        WP_Mock::userFunction('update_post_meta')
            ->with(42, '_ada_remediation_content_hash', 'deadbeef')
            ->once();
        WP_Mock::userFunction('update_post_meta')
            ->with(42, '_ada_remediation_pipeline_version', '1.2.3')
            ->once();
        WP_Mock::userFunction('update_post_meta')
            ->with(42, '_ada_remediation_badge', 'pending')
            ->once();

        $this->assertTrue(Client::submit_attachment(42));
        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_submit_attachment_returns_false_when_no_file_could_be_read(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();

        WP_Mock::userFunction('get_attached_file', ['return' => false]);
        WP_Mock::userFunction('wp_get_attachment_url', ['return' => 'https://example.org/missing.pdf']);
        WP_Mock::userFunction('wp_remote_get', [
            'return' => ['errors' => ['http_request_failed' => ['Could not resolve host']]],
        ]);
        WP_Mock::userFunction('wp_remote_post', ['times' => 0]);
        $this->expect_failure_badge_write(42);

        $this->assertFalse(Client::submit_attachment(42));
        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_submit_attachment_returns_false_when_pipeline_responds_with_non_2xx_status(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();

        WP_Mock::userFunction('get_attached_file', ['return' => '/uploads/2026/09/report.pdf']);
        WP_Mock::userFunction('AdaRemediationClient\\file_exists', ['return' => true]);
        WP_Mock::userFunction('AdaRemediationClient\\file_get_contents', ['return' => '%PDF-1.4 local bytes']);
        WP_Mock::userFunction('rest_url', [
            'return' => 'https://example.org/wp-json/ada-remediation/v1/callback',
        ]);

        // Transport succeeded (not a WP_Error) but the pipeline rejected the request —
        // e.g. a revoked ADA_REMEDIATION_API_TOKEN.
        WP_Mock::userFunction('wp_remote_post', [
            'times' => 1,
            'return' => ['response' => ['code' => 401], 'body' => '{"detail":"Invalid token."}'],
        ]);
        // A rejected submission has no remediation_id/content_hash/pipeline_version to store —
        // only the error badge and a log row (DIC-2004).
        $this->expect_failure_badge_write(42);

        $this->assertFalse(Client::submit_attachment(42));
        $this->assertConditionsMet();
    }

    /**
     * A 2xx with no remediation id could never be matched by a webhook, and a pending badge
     * would then block every retry. It must fail the submission, like a rejected request.
     *
     * @dataProvider bodies_without_a_remediation_id
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_submit_attachment_fails_when_the_response_carries_no_remediation_id(string $body): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();

        WP_Mock::userFunction('get_attached_file', ['return' => '/uploads/2026/09/report.pdf']);
        WP_Mock::userFunction('AdaRemediationClient\\file_exists', ['return' => true]);
        WP_Mock::userFunction('AdaRemediationClient\\file_get_contents', ['return' => '%PDF-1.4 local bytes']);
        WP_Mock::userFunction('rest_url', [
            'return' => 'https://example.org/wp-json/ada-remediation/v1/callback',
        ]);
        WP_Mock::userFunction('wp_remote_post', [
            'times' => 1,
            'return' => ['response' => ['code' => 200], 'body' => $body],
        ]);
        // Only the error badge and a log row; no pending badge, no stored id.
        $this->expect_failure_badge_write(42);

        $this->assertFalse(Client::submit_attachment(42));
        $this->assertConditionsMet();
    }

    public function bodies_without_a_remediation_id(): array
    {
        return [
            'JSON without id' => ['{"document_id":"deadbeef","status":"queued"}'],
            'not JSON' => ['<html>gateway error</html>'],
            'empty' => [''],
        ];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_submit_attachment_strips_quotes_and_line_breaks_from_the_filename_header(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();

        WP_Mock::userFunction('get_attached_file', ['return' => "/uploads/a\"b\r\nX-Evil: 1.pdf"]);
        WP_Mock::userFunction('AdaRemediationClient\\file_exists', ['return' => true]);
        WP_Mock::userFunction('AdaRemediationClient\\file_get_contents', ['return' => '%PDF-1.4 local bytes']);
        WP_Mock::userFunction('rest_url', [
            'return' => 'https://example.org/wp-json/ada-remediation/v1/callback',
        ]);
        $sent = null;
        WP_Mock::userFunction('wp_remote_post', [
            'times' => 1,
            'return' => static function ($url, $args) use (&$sent) {
                $sent = $args['body'];

                return ['response' => ['code' => 200], 'body' => self::SUBMIT_RESPONSE_BODY];
            },
        ]);
        WP_Mock::userFunction('update_post_meta');

        Client::submit_attachment(42);

        $this->assertStringContainsString('filename="abX-Evil: 1.pdf"' . "\r\n", (string) $sent);
        $this->assertStringNotContainsString("\r\nX-Evil", (string) $sent);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_submit_attachment_returns_false_when_fallback_fetch_responds_with_non_2xx_status(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();

        WP_Mock::userFunction('get_attached_file', ['return' => false]);
        WP_Mock::userFunction('wp_get_attachment_url', ['return' => 'https://example.org/missing.pdf']);

        // Transport succeeded (not a WP_Error) but the URL 404s — the body would be an
        // HTML error page, not PDF bytes, so it must not be shipped to the pipeline.
        WP_Mock::userFunction('wp_remote_get', [
            'return' => ['response' => ['code' => 404], 'body' => '<html>Not Found</html>'],
        ]);
        WP_Mock::userFunction('wp_remote_post', ['times' => 0]);
        $this->expect_failure_badge_write(42);

        $this->assertFalse(Client::submit_attachment(42));
        $this->assertConditionsMet();
    }

    /**
     * A failed submission (unreadable file or a rejected pipeline response) has no
     * remediation_id/content_hash/pipeline_version to store — only the error badge
     * and a log row with those fields left empty/null (DIC-2004).
     */
    private function expect_failure_badge_write(int $attachment_id): void
    {
        WP_Mock::userFunction('update_post_meta')
            ->with($attachment_id, '_ada_remediation_badge', 'error')
            ->once();
        WP_Mock::userFunction('current_time', ['return' => '2026-09-28 12:00:00']);

        // Shares the $wpdb mock_free_pdf() installed, which also serves the claim queries.
        $wpdb = $GLOBALS['wpdb'] ?? Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('insert')->once()->with(
            'wp_ada_remediation_log',
            Mockery::on(function (array $row) use ($attachment_id): bool {
                return $row['attachment_id'] === $attachment_id
                    && $row['remediation_id'] === ''
                    && $row['content_hash'] === ''
                    && $row['pipeline_version'] === ''
                    && $row['badge'] === 'error'
                    && $row['precheck_json'] === null
                    && $row['postcheck_json'] === null;
            }),
            Mockery::type('array')
        );
        $GLOBALS['wpdb'] = $wpdb;
    }

    /**
     * DIC-2040: a fixed callback_url means the pipeline's own (remediation, callback_url)
     * idempotency (ADR 0015) silently drops the webhook for a second attachment that happens
     * to dedupe to the same remediation (identical file content) — its badge gets stuck on
     * "pending" forever, since no notification ever fires for it. Embedding the attachment_id
     * gives every submission its own callback_url, regardless of content-hash dedup.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_submit_attachment_includes_the_attachment_id_in_the_callback_url(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();

        WP_Mock::userFunction('get_attached_file', ['return' => '/uploads/2026/09/report.pdf']);
        WP_Mock::userFunction('AdaRemediationClient\\file_exists', ['return' => true]);
        WP_Mock::userFunction('AdaRemediationClient\\file_get_contents', ['return' => '%PDF-1.4 local bytes']);
        WP_Mock::userFunction('rest_url', [
            'return' => 'https://example.org/wp-json/ada-remediation/v1/callback',
        ]);
        WP_Mock::userFunction('update_post_meta', ['times' => '1+']);

        WP_Mock::userFunction('wp_remote_post', [
            'times' => 1,
            'return' => ['response' => ['code' => 201], 'body' => self::SUBMIT_RESPONSE_BODY],
        ])->with(
            Mockery::any(),
            Mockery::on(function (array $request): bool {
                return strpos(
                    $request['body'],
                    'https://example.org/wp-json/ada-remediation/v1/callback?attachment_id=42'
                ) !== false;
            })
        );

        $this->assertTrue(Client::submit_attachment(42));
        $this->assertConditionsMet();
    }

    public function test_submit_attachment_refuses_a_non_pdf_without_touching_anything(): void
    {
        WP_Mock::userFunction('get_post_mime_type', ['return' => 'image/jpeg']);
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);
        WP_Mock::userFunction('wp_remote_post', ['times' => 0]);

        $this->assertFalse(Client::submit_attachment(42));
        $this->assertConditionsMet();
    }

    /**
     * @dataProvider force_contexts
     */
    public function test_submit_attachment_refuses_while_a_claim_is_held_even_when_forced(array $context): void
    {
        WP_Mock::userFunction('get_post_mime_type', ['return' => 'application/pdf']);
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        // Not ours, so it must not be released, stamped or submitted.
        $wpdb = $this->mock_wpdb(0, (time() - 5) . '|other', false);
        $wpdb->shouldReceive('delete')->never();
        $wpdb->shouldReceive('update')->never();
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);
        WP_Mock::userFunction('wp_remote_post', ['times' => 0]);

        $this->assertFalse(Client::submit_attachment(42, $context));
        $this->assertConditionsMet();
    }

    public function test_submit_attachment_refuses_while_the_pipeline_has_a_pending_job_and_releases_its_claim(): void
    {
        WP_Mock::userFunction('get_post_mime_type', ['return' => 'application/pdf']);
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        $recent = time() - 5;
        WP_Mock::userFunction('get_post_meta', [
            'return' => static function ($id, $key) use ($recent) {
                return [
                    '_ada_remediation_badge' => 'pending',
                    '_ada_remediation_submitted_at' => $recent,
                ][$key] ?? '';
            },
        ]);
        $wpdb = $this->mock_wpdb(1, '', false);
        $wpdb->shouldReceive('delete')
            ->once()
            ->with(
                'wp_options',
                Mockery::on(static function ($where): bool {
                    return is_array($where)
                        && 'ada_remediation_submit_claim_42' === ($where['option_name'] ?? null)
                        && 1 === preg_match('/^\d+\|uuid$/', (string) ($where['option_value'] ?? ''));
                })
            );
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);
        WP_Mock::userFunction('wp_remote_post', ['times' => 0]);

        $this->assertFalse(Client::submit_attachment(42, ['force' => true]));
        $this->assertConditionsMet();
    }

    /**
     * @dataProvider force_contexts
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_submit_attachment_sends_force_from_the_context(array $context, string $expected): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();

        WP_Mock::userFunction('get_attached_file', ['return' => '/uploads/2026/09/report.pdf']);
        WP_Mock::userFunction('AdaRemediationClient\\file_exists', ['return' => true]);
        WP_Mock::userFunction('AdaRemediationClient\\file_get_contents', ['return' => '%PDF-1.4 local bytes']);
        WP_Mock::userFunction('rest_url', ['return' => 'https://example.org/wp-json/ada-remediation/v1/callback']);
        WP_Mock::userFunction('update_post_meta', ['times' => '1+']);

        WP_Mock::userFunction('wp_remote_post', [
            'times' => 1,
            'return' => ['response' => ['code' => 201], 'body' => self::SUBMIT_RESPONSE_BODY],
        ])->with(
            Mockery::any(),
            Mockery::on(function (array $request) use ($expected): bool {
                return strpos($request['body'], "name=\"force\"\r\n\r\n{$expected}\r\n") !== false;
            })
        );

        $this->assertTrue(Client::submit_attachment(42, $context));
        $this->assertConditionsMet();
    }

    public function force_contexts(): array
    {
        return [
            'no context'   => [[], 'false'],
            'force true'   => [['force' => true], 'true'],
            'force false'  => [['force' => false], 'false'],
        ];
    }

    /**
     * @dataProvider pipeline_urls
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_authenticated_get_sends_the_token_to_the_pipelines_own_origin(string $url): void
    {
        $this->define_config_constants();
        WP_Mock::userFunction('wp_parse_url', [
            'return' => static function (string $url) {
                return parse_url($url);
            },
        ]);

        WP_Mock::userFunction('wp_remote_get', ['times' => 1, 'return' => ['response' => ['code' => 200]]])
            ->with(
                $url,
                Mockery::on(static function (array $args): bool {
                    return ($args['headers']['Authorization'] ?? null) === 'Token test-token';
                })
            );

        Client::authenticated_get($url);
        $this->assertConditionsMet();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pipeline_urls(): array
    {
        return [
            'same origin' => ['https://pipeline.example.org/api/document-download/abc/'],
            'same origin, explicit default port' => ['https://pipeline.example.org:443/x'],
        ];
    }

    /**
     * A redirect could lead off the pipeline's origin, and WordPress would send the token along.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_authenticated_get_never_follows_redirects_even_if_the_caller_asks_to(): void
    {
        $this->define_config_constants();
        WP_Mock::userFunction('wp_parse_url', [
            'return' => static function (string $url) {
                return parse_url($url);
            },
        ]);

        WP_Mock::userFunction('wp_remote_get', ['times' => 1, 'return' => ['response' => ['code' => 200]]])
            ->with(
                'https://pipeline.example.org/x',
                Mockery::on(static function (array $args): bool {
                    return $args['redirection'] === 0;
                })
            );

        Client::authenticated_get('https://pipeline.example.org/x', ['redirection' => 5]);
        $this->assertConditionsMet();
    }

    /**
     * The URL comes from a webhook payload: a forged or compromised one must not be able to make
     * this site request an internal address (SSRF), or send the token to another host.
     *
     * @dataProvider foreign_urls
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_authenticated_get_refuses_any_url_outside_the_pipelines_origin_without_making_a_request(string $url): void
    {
        $this->define_config_constants();
        WP_Mock::userFunction('wp_parse_url', [
            'return' => static function (string $url) {
                return parse_url($url);
            },
        ]);
        WP_Mock::userFunction('wp_remote_get', ['times' => 0]);

        $result = Client::authenticated_get($url);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertConditionsMet();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreign_urls(): array
    {
        return [
            'different host' => ['https://evil.example.com/api/document-download/abc/'],
            'different scheme' => ['http://pipeline.example.org/x'],
            'different port' => ['https://pipeline.example.org:8443/x'],
            'cloud metadata service' => ['http://169.254.169.254/latest/meta-data/'],
            'loopback' => ['http://127.0.0.1:8080/admin'],
            'no scheme or host' => ['/etc/passwd'],
            'userinfo trick' => ['https://pipeline.example.org@evil.example.com/x'],
        ];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_download_remediated_file_refuses_a_foreign_url_before_creating_a_temp_file(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        WP_Mock::userFunction('wp_parse_url', [
            'return' => static function (string $url) {
                return parse_url($url);
            },
        ]);
        WP_Mock::userFunction('wp_remote_get', ['times' => 0]);
        WP_Mock::userFunction('wp_tempnam', ['times' => 0]);

        $result = Client::download_remediated_file('http://169.254.169.254/latest/meta-data/');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_download_remediated_file_returns_the_streamed_temp_file(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        WP_Mock::userFunction('wp_parse_url', ['return' => static function (string $url) {
            return parse_url($url);
        }]);
        WP_Mock::userFunction('wp_tempnam', ['return' => '/tmp/ada-abc.tmp']);

        WP_Mock::userFunction('wp_remote_get', ['times' => 1, 'return' => ['response' => ['code' => 200]]])
            ->with(
                'https://pipeline.example.org/api/document-download/abc/',
                Mockery::on(static function (array $args): bool {
                    return $args['stream'] === true
                        && $args['filename'] === '/tmp/ada-abc.tmp'
                        && $args['headers']['Authorization'] === 'Token test-token';
                })
            );
        WP_Mock::userFunction('AdaRemediationClient\\unlink', ['times' => 0]);

        $this->assertSame(
            '/tmp/ada-abc.tmp',
            Client::download_remediated_file('https://pipeline.example.org/api/document-download/abc/')
        );
        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_download_remediated_file_removes_the_temp_file_and_errors_on_a_non_2xx_response(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        WP_Mock::userFunction('wp_parse_url', ['return' => static function (string $url) {
            return parse_url($url);
        }]);
        WP_Mock::userFunction('wp_tempnam', ['return' => '/tmp/ada-abc.tmp']);
        WP_Mock::userFunction('wp_remote_get', ['return' => ['response' => ['code' => 401]]]);
        WP_Mock::userFunction('AdaRemediationClient\\unlink', ['times' => 1])->with('/tmp/ada-abc.tmp');

        $result = Client::download_remediated_file('https://pipeline.example.org/api/document-download/abc/');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertConditionsMet();
    }

    public function test_submit_with_result_says_refused_for_a_non_pdf(): void
    {
        WP_Mock::userFunction('get_post_mime_type', ['return' => 'image/jpeg']);
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);
        WP_Mock::userFunction('wp_remote_post', ['times' => 0]);

        $this->assertSame(Submit_Result::REFUSED, Client::submit_with_result(42)->outcome());
        $this->assertConditionsMet();
    }

    public function test_submit_with_result_says_refused_while_a_claim_is_held(): void
    {
        WP_Mock::userFunction('get_post_mime_type', ['return' => 'application/pdf']);
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        $this->mock_wpdb(0, (time() - 5) . '|other', false);
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);
        WP_Mock::userFunction('wp_remote_post', ['times' => 0]);

        $this->assertSame(Submit_Result::REFUSED, Client::submit_with_result(42)->outcome());
        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_submit_with_result_says_failed_when_no_file_could_be_read(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();

        WP_Mock::userFunction('get_attached_file', ['return' => false]);
        WP_Mock::userFunction('wp_get_attachment_url', ['return' => 'https://example.org/missing.pdf']);
        WP_Mock::userFunction('wp_remote_get', [
            'return' => ['errors' => ['http_request_failed' => ['Could not resolve host']]],
        ]);
        WP_Mock::userFunction('wp_remote_post', ['times' => 0]);
        $this->expect_failure_badge_write(42);

        $this->assertSame(Submit_Result::FAILED, Client::submit_with_result(42)->outcome());
        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_submit_with_result_says_submitted_when_the_pipeline_accepts_the_file(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();

        WP_Mock::userFunction('get_attached_file', ['return' => '/uploads/report.pdf']);
        WP_Mock::userFunction('AdaRemediationClient\\file_exists', ['return' => true]);
        WP_Mock::userFunction('AdaRemediationClient\\file_get_contents', ['return' => '%PDF-1.4 local bytes']);
        WP_Mock::userFunction('rest_url', [
            'return' => 'https://example.org/wp-json/ada-remediation/v1/callback',
        ]);
        WP_Mock::userFunction('update_post_meta', ['times' => '1+']);
        WP_Mock::userFunction('wp_remote_post', [
            'times' => 1,
            'return' => ['response' => ['code' => 201], 'body' => self::SUBMIT_RESPONSE_BODY],
        ]);

        $result = Client::submit_with_result(42);

        $this->assertSame(Submit_Result::SUBMITTED, $result->outcome());
        $this->assertTrue($result->is_submitted());
        $this->assertConditionsMet();
    }

    /**
     * @dataProvider rate_limited_responses
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_429_is_rate_limited_not_failed_and_leaves_the_badge_and_log_alone(
        array $headers,
        string $body,
        ?string $kind,
        ?int $retry_after
    ): void {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();
        $this->mock_local_pdf_and_post(['response' => ['code' => 429], 'headers' => $headers, 'body' => $body]);

        // Nothing may be written: no badge (update_post_meta) and no log row (insert on the shared $wpdb mock).
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);
        $GLOBALS['wpdb']->prefix = 'wp_';
        $GLOBALS['wpdb']->shouldReceive('insert')->never();

        $result = Client::submit_with_result(42);

        $this->assertTrue($result->is_rate_limited());
        $this->assertFalse($result->is_failed());
        $this->assertSame($kind, $result->limit_kind());
        $this->assertSame($retry_after, $result->retry_after());
        $this->assertConditionsMet();
    }

    public function rate_limited_responses(): array
    {
        $rate = '{"limit":"rate_limit"}';

        return [
            'rate limit: the Retry-After header' => [['retry-after' => '120'], $rate, Submit_Result::LIMIT_RATE, 120],
            'rate limit: a bad header falls back to the body' => [
                ['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT'],
                '{"limit":"rate_limit","retry_after":300}',
                Submit_Result::LIMIT_RATE,
                300,
            ],
            'rate limit: no wait given' => [[], $rate, Submit_Result::LIMIT_RATE, null],
            'rate limit: a huge wait is capped' => [['retry-after' => '99999999999'], $rate, Submit_Result::LIMIT_RATE, Submit_Result::MAX_RETRY_AFTER_SECONDS],
            'rate limit: a negative body wait is ignored' => [[], '{"limit":"rate_limit","retry_after":-5}', Submit_Result::LIMIT_RATE, null],
            'rate limit: resets_at that is not ISO 8601 is ignored' => [[], '{"limit":"rate_limit","resets_at":"tomorrow"}', Submit_Result::LIMIT_RATE, null],
            'rate limit: resets_at with a trailing newline is ignored' => [
                [],
                '{"limit":"rate_limit","resets_at":"2026-10-21T17:00:00Z\\n"}',
                Submit_Result::LIMIT_RATE,
                null,
            ],
            'rate limit: resets_at with the right shape but no such date is ignored' => [
                [],
                '{"limit":"rate_limit","resets_at":"2026-13-45T00:00:00Z"}',
                Submit_Result::LIMIT_RATE,
                null,
            ],
            'rate limit: a bare year is ignored' => [[], '{"limit":"rate_limit","resets_at":"2026"}', Submit_Result::LIMIT_RATE, null],
            'rate limit: resets_at with no UTC offset is ignored' => [
                [],
                '{"limit":"rate_limit","resets_at":"2026-10-21T17:00:00"}',
                Submit_Result::LIMIT_RATE,
                null,
            ],
            'rate limit: digits too many for an int are ignored' => [
                ['retry-after' => '99999999999999999999'],
                $rate,
                Submit_Result::LIMIT_RATE,
                null,
            ],
            'max at once: any wait is ignored' => [
                ['retry-after' => '60'],
                '{"limit":"max_at_once","retry_after":60}',
                Submit_Result::LIMIT_MAX_AT_ONCE,
                null,
            ],
            'unknown limit: any wait is ignored' => [['retry-after' => '60'], '{"limit":"other"}', null, null],
            'no body at all (a proxy)' => [['retry-after' => '60'], '', null, null],
            'a body that is not an object' => [[], '"slow down"', null, null],
        ];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_submit_attachment_is_false_for_a_429(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();
        $this->mock_local_pdf_and_post(['response' => ['code' => 429], 'body' => '{"limit":"max_at_once"}']);
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);

        $this->assertFalse(Client::submit_attachment(42));
        $this->assertConditionsMet();
    }

    /**
     * @dataProvider resets_at_offsets
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_429_resets_at_date_becomes_seconds_from_now(int $offset, int $expected): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();
        $resets_at = gmdate('Y-m-d\TH:i:s\Z', time() + $offset);
        $this->mock_local_pdf_and_post([
            'response' => ['code' => 429],
            'body' => '{"limit":"rate_limit","resets_at":"' . $resets_at . '"}',
        ]);
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);

        $result = Client::submit_with_result(42);

        $this->assertEqualsWithDelta($expected, $result->retry_after(), 5);
    }

    public function resets_at_offsets(): array
    {
        return [
            'ten minutes from now' => [600, 600],
            'already past: no wait' => [-3600, 0],
        ];
    }

    /**
     * @dataProvider resets_at_shapes
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_429_resets_at_accepts_the_iso_8601_shapes_the_pipeline_can_send(string $format): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();
        $this->mock_local_pdf_and_post([
            'response' => ['code' => 429],
            'body' => '{"limit":"rate_limit","resets_at":"' . gmdate($format, time() + 600) . '"}',
        ]);
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);

        $this->assertEqualsWithDelta(600, Client::submit_with_result(42)->retry_after(), 5);
    }

    public function resets_at_shapes(): array
    {
        return [
            'Z' => ['Y-m-d\TH:i:s\Z'],
            'a +00:00 offset' => ['Y-m-d\TH:i:s+00:00'],
            'microseconds and Z' => ['Y-m-d\TH:i:s.123456\Z'],
            'microseconds and an offset' => ['Y-m-d\TH:i:s.123456+00:00'],
        ];
    }

    /**
     * @dataProvider quota_headers
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_the_ratelimit_headers_are_read_from_a_success_response(array $headers, array $expected): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();
        $this->mock_local_pdf_and_post([
            'response' => ['code' => 201],
            'headers' => $headers,
            'body' => self::SUBMIT_RESPONSE_BODY,
        ]);
        WP_Mock::userFunction('update_post_meta', ['times' => '1+']);

        $result = Client::submit_with_result(42);

        $this->assertTrue($result->is_submitted());
        $this->assertSame($expected, $result->quota());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_the_ratelimit_headers_are_kept_on_a_failed_response_too(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();
        $this->mock_local_pdf_and_post([
            'response' => ['code' => 500],
            'headers' => ['x-ratelimit-remaining' => '3'],
            'body' => 'oops',
        ]);
        $this->expect_failure_badge_write(42);

        $result = Client::submit_with_result(42);

        $this->assertTrue($result->is_failed());
        $this->assertSame([Submit_Result::QUOTA_REMAINING => 3], $result->quota());
        $this->assertConditionsMet();
    }

    public function quota_headers(): array
    {
        return [
            'all three' => [
                ['x-ratelimit-limit' => '100', 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '1792569600'],
                [
                    Submit_Result::QUOTA_LIMIT => 100,
                    Submit_Result::QUOTA_REMAINING => 0,
                    Submit_Result::QUOTA_RESET => 1792569600,
                ],
            ],
            'only some' => [['x-ratelimit-remaining' => '7'], [Submit_Result::QUOTA_REMAINING => 7]],
            'not whole numbers are left out' => [
                ['x-ratelimit-limit' => 'many', 'x-ratelimit-remaining' => '-1', 'x-ratelimit-reset' => '1.5'],
                [],
            ],
            'none' => [[], []],
        ];
    }

    /**
     * Gives the PDF a readable local file and has wp_remote_post() answer with $response.
     */
    private function mock_local_pdf_and_post(array $response): void
    {
        WP_Mock::userFunction('get_attached_file', ['return' => '/uploads/report.pdf']);
        WP_Mock::userFunction('AdaRemediationClient\\file_exists', ['return' => true]);
        WP_Mock::userFunction('AdaRemediationClient\\file_get_contents', ['return' => '%PDF-1.4 local bytes']);
        WP_Mock::userFunction('rest_url', [
            'return' => 'https://example.org/wp-json/ada-remediation/v1/callback',
        ]);
        WP_Mock::userFunction('wp_remote_post', ['times' => 1, 'return' => $response]);
    }

    /**
     * @dataProvider at_once_retries
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_scheduled_submit_that_hits_the_maximum_at_once_schedules_the_next_try(int $retry, int $delay): void
    {
        $due = time() - 30;
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_claimable_pdf($retry > 0 ? $due : null);
        $this->mock_local_pdf_and_post(['response' => ['code' => 429], 'body' => '{"limit":"max_at_once"}']);
        $writes = $this->capture_meta_writes();
        // The event carries its retry number and the time it is due, which is also the file's stamp.
        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 1, 'return' => true])->with(
            $this->near(time() + $delay),
            'ada_remediation_submit_attachment',
            Mockery::on(function ($args) use ($retry, $delay): bool {
                return 4 === count($args) && 42 === $args[0] && [] === $args[1] && $retry + 1 === $args[2]
                    && abs($args[3] - (time() + $delay)) <= 5;
            })
        );

        Client::submit_scheduled(42, [], $retry, $retry > 0 ? $due : 0);

        // Stamped with the time of the next try, then badged retrying; no error badge, no log row.
        $this->assertCount(2, $writes);
        $this->assertSame([42, '_ada_remediation_submitted_at'], array_slice($writes[0], 0, 2));
        $this->assertEqualsWithDelta(time() + $delay, $writes[0][2], 5);
        $this->assertSame([42, '_ada_remediation_badge', 'retrying'], $writes[1]);
        $this->assertConditionsMet();
    }

    public function at_once_retries(): array
    {
        return [
            'first try gets the 1st retry' => [0, 1200],
            'the 3rd retry follows the 2nd' => [2, 1200],
            'the 4th retry is the last short one' => [3, 1200],
            'the 5th retry is the long wait' => [4, 7200],
        ];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_scheduled_submit_that_hits_a_time_period_limit_waits_for_the_reset(): void
    {
        $due = time() - 30;
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_claimable_pdf($due);
        $this->mock_local_pdf_and_post([
            'response' => ['code' => 429],
            'headers' => ['retry-after' => '5400'],
            'body' => '{"limit":"rate_limit"}',
        ]);
        $this->capture_meta_writes();
        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 1, 'return' => true])->with(
            $this->near(time() + 5400),
            'ada_remediation_submit_attachment',
            Mockery::on(function ($args): bool {
                return 8 === $args[2] && abs($args[3] - (time() + 5400)) <= 5;
            })
        );

        Client::submit_scheduled(42, [], 7, $due);

        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_retry_keeps_the_context_the_submit_was_scheduled_with(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_claimable_pdf();
        $this->mock_local_pdf_and_post(['response' => ['code' => 429], 'body' => '{"limit":"max_at_once"}']);
        $this->capture_meta_writes();
        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 1, 'return' => true])->with(
            Mockery::type('integer'),
            'ada_remediation_submit_attachment',
            Mockery::on(function ($args): bool {
                return ['force' => true] === $args[1];
            })
        );

        Client::submit_scheduled(42, ['force' => true]);

        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_scheduled_submit_marks_the_file_failed_once_the_retries_are_used_up(): void
    {
        $due = time() - 30;
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_claimable_pdf($due);
        $this->mock_local_pdf_and_post(['response' => ['code' => 429], 'body' => '{"limit":"max_at_once"}']);
        // The 5th retry was the last: this is the 6th try, so there is no 7th.
        $this->expect_failure_badge_write(42);
        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 0]);

        Client::submit_scheduled(42, [], 5, $due);

        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_scheduled_submit_marks_the_file_failed_if_the_retry_cannot_be_scheduled(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_claimable_pdf();
        $this->mock_local_pdf_and_post(['response' => ['code' => 429], 'body' => '{"limit":"max_at_once"}']);
        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 1, 'return' => false]);
        WP_Mock::userFunction('wp_next_scheduled', ['return' => false]);
        // The stamp and the retrying badge are written first, then the failure replaces the badge.
        // The specific expectation comes first: Mockery uses the first one that matches.
        $this->expect_failure_badge_write(42);
        WP_Mock::userFunction('update_post_meta', ['return' => true]);

        Client::submit_scheduled(42);

        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_scheduled_submit_leaves_the_file_alone_if_the_pipeline_took_a_job_for_it_meanwhile(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        WP_Mock::userFunction('get_post_mime_type', ['return' => 'application/pdf']);
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        // No pending job when the submit starts; one by the time the retry would be stamped.
        $badge_reads = 0;
        WP_Mock::userFunction('get_post_meta', [
            'return' => static function ($id, $key) use (&$badge_reads) {
                if ('_ada_remediation_badge' === $key) {
                    // Reads: the claim's pending check, the first-try check, then the retry's own check.
                    return ++$badge_reads > 2 ? 'pending' : '';
                }

                return '_ada_remediation_submitted_at' === $key ? time() - 5 : '';
            },
        ]);
        $this->mock_wpdb(1);
        $this->mock_local_pdf_and_post(['response' => ['code' => 429], 'body' => '{"limit":"max_at_once"}']);
        // Not stamped, not rescheduled, and not marked failed: something else has the file now.
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);
        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 0]);

        Client::submit_scheduled(42);

        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_scheduled_submit_the_pipeline_accepts_schedules_nothing_more(): void
    {
        $due = time() - 30;
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_claimable_pdf($due);
        $this->mock_local_pdf_and_post([
            'response' => ['code' => 201],
            'body' => self::SUBMIT_RESPONSE_BODY,
        ]);
        WP_Mock::userFunction('update_post_meta', ['times' => '1+']);
        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 0]);

        Client::submit_scheduled(42, [], 2, $due);

        $this->assertConditionsMet();
    }

    public function test_the_cron_hook_is_told_to_accept_every_argument_submit_scheduled_takes(): void
    {
        $this->assertSame(
            (new \ReflectionMethod(Client::class, 'submit_scheduled'))->getNumberOfParameters(),
            Client::SCHEDULED_SUBMIT_ARGS
        );
    }

    public function test_a_scheduled_submit_that_is_refused_schedules_nothing(): void
    {
        WP_Mock::userFunction('get_post_mime_type', ['return' => 'image/jpeg']);
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);
        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 0]);

        Client::submit_scheduled(42);

        $this->assertConditionsMet();
    }

    /**
     * A retry event whose file has moved on must do nothing at all: not submit, not write, not
     * schedule. This is what keeps one retry chain per file.
     *
     * @dataProvider files_that_moved_on
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_retry_does_nothing_if_the_file_is_no_longer_waiting_for_it(string $badge, ?int $stamp): void
    {
        $due = 1800000000;
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        WP_Mock::userFunction('get_post_meta', [
            'return' => static function ($id, $key) use ($badge, $stamp) {
                if ('_ada_remediation_badge' === $key) {
                    return $badge;
                }

                return '_ada_remediation_submitted_at' === $key && null !== $stamp ? $stamp : '';
            },
        ]);
        // The check runs once the claim is held, so the claim is taken and released around it.
        WP_Mock::userFunction('get_post_mime_type', ['return' => 'application/pdf']);
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        $wpdb = $this->mock_wpdb(1, '', false);
        $wpdb->shouldReceive('delete')->once();
        WP_Mock::userFunction('wp_remote_post', ['times' => 0]);
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);
        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 0]);

        Client::submit_scheduled(42, ['force' => true], 3, $due);

        $this->assertConditionsMet();
    }

    public function files_that_moved_on(): array
    {
        $due = 1800000000;

        return [
            'submitted another way: pending' => ['pending', $due],
            'finished' => ['dark-green', $due],
            'failed' => ['error', $due],
            'queued again' => ['queued', $due],
            'a newer retry replaced this one' => ['retrying', $due + 1200],
            'retrying but stamped by something older' => ['retrying', $due - 5],
            'no badge' => ['', $due],
            'retrying with no stamp' => ['retrying', null],
        ];
    }

    /**
     * A first-try event does nothing for a file that moved on since it was scheduled.
     *
     * @dataProvider files_that_are_not_awaiting_a_first_try
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_first_try_does_nothing_if_the_file_is_no_longer_waiting_for_it(string $badge): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        WP_Mock::userFunction('get_post_meta', [
            'return' => static function ($id, $key) use ($badge) {
                return '_ada_remediation_badge' === $key ? $badge : '';
            },
        ]);
        WP_Mock::userFunction('get_post_mime_type', ['return' => 'application/pdf']);
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        $wpdb = $this->mock_wpdb(1, '', false);
        $wpdb->shouldReceive('delete')->once();
        WP_Mock::userFunction('wp_remote_post', ['times' => 0]);
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);
        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 0]);

        Client::submit_scheduled(42);

        $this->assertConditionsMet();
    }

    public function files_that_are_not_awaiting_a_first_try(): array
    {
        return [
            'compliant' => ['dark-green'],
            'minor issues' => ['light-green'],
            'major issues' => ['yellow'],
            'critical issues' => ['red'],
            'failed' => ['error'],
            'waiting for a retry of its own' => ['retrying'],
            'an adapter\'s own badge' => ['county-custom'],
        ];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_first_try_runs_for_a_file_that_is_queued_for_it(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        WP_Mock::userFunction('get_post_meta', [
            'return' => static function ($id, $key) {
                if ('_ada_remediation_badge' === $key) {
                    return 'queued';
                }

                return '_ada_remediation_submitted_at' === $key ? time() - 5 : '';
            },
        ]);
        WP_Mock::userFunction('get_post_mime_type', ['return' => 'application/pdf']);
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        $this->mock_wpdb(1);
        $this->mock_local_pdf_and_post(['response' => ['code' => 201], 'body' => self::SUBMIT_RESPONSE_BODY]);
        WP_Mock::userFunction('update_post_meta', ['times' => '1+']);

        Client::submit_scheduled(42);

        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_the_first_try_does_not_wait_for_a_retry_state(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();
        $this->mock_free_pdf();
        $this->mock_local_pdf_and_post(['response' => ['code' => 201], 'body' => self::SUBMIT_RESPONSE_BODY]);
        WP_Mock::userFunction('update_post_meta', ['times' => '1+']);

        // An event scheduled by an upload carries no retry number or due time.
        $this->assertNull(Client::submit_scheduled(42));
        $this->assertConditionsMet();
    }

    /**
     * Records what update_post_meta() is asked to write, as [id, key, value] rows.
     */
    private function capture_meta_writes(): \ArrayObject
    {
        $writes = new \ArrayObject();
        WP_Mock::userFunction('update_post_meta', [
            'return' => static function ($id, $key, $value) use ($writes): bool {
                $writes->append([$id, $key, $value]);

                return true;
            },
        ]);

        return $writes;
    }

    private function near(int $expected): \Mockery\Matcher\Closure
    {
        return Mockery::on(static function ($time) use ($expected): bool {
            return is_int($time) && abs($time - $expected) <= 5;
        });
    }

    private function define_config_constants(): void
    {
        define('ADA_REMEDIATION_API_BASE_URL', 'https://pipeline.example.org');
        define('ADA_REMEDIATION_API_TOKEN', 'test-token');
        define('ADA_REMEDIATION_WEBHOOK_SECRET', 'test-secret');
    }

    /**
     * Registers is_wp_error()/wp_remote_retrieve_response_code()/wp_remote_retrieve_body()
     * as WP core actually implements them — deriving from the response array's own shape —
     * so one mock setup works correctly regardless of how many wp_remote_* calls a test
     * makes (the fallback GET and the submission POST return different bodies/codes).
     */
    private function mock_wp_http_helpers(): void
    {
        WP_Mock::userFunction('is_wp_error', [
            'return' => static function ($thing): bool {
                return is_array($thing) && array_key_exists('errors', $thing);
            },
        ]);
        WP_Mock::userFunction('add_query_arg', [
            'return' => static function (string $key, $value, string $url): string {
                $separator = strpos($url, '?') !== false ? '&' : '?';

                return $url . $separator . $key . '=' . $value;
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
        // Like core: the value as sent, or '' when the header is missing.
        WP_Mock::userFunction('wp_remote_retrieve_header', [
            'return' => static function ($response, string $header) {
                return $response['headers'][$header] ?? '';
            },
        ]);
    }

    /**
     * A PDF with no claim held and no job pending, so submit_attachment() proceeds.
     */
    private function mock_free_pdf(): void
    {
        $this->mock_claimable_pdf();
        WP_Mock::userFunction('update_post_meta')
            ->with(42, '_ada_remediation_submitted_at', Mockery::type('integer'));
    }

    /**
     * The same, but leaves update_post_meta() to the test, to inspect what is written.
     *
     * @param int|null $retry_due_at If given, the file is already retrying, due at this time.
     */
    private function mock_claimable_pdf(?int $retry_due_at = null): void
    {
        WP_Mock::userFunction('get_post_mime_type', ['return' => 'application/pdf']);
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        WP_Mock::userFunction('get_post_meta', [
            'return' => static function ($id, $key) use ($retry_due_at) {
                if (null === $retry_due_at) {
                    return '';
                }

                if ('_ada_remediation_badge' === $key) {
                    return 'retrying';
                }

                return '_ada_remediation_submitted_at' === $key ? $retry_due_at : '';
            },
        ]);
        $this->mock_wpdb(1);
    }

    /**
     * Stands in for $wpdb. $inserted is what the claim's INSERT IGNORE affects: 1 wins the claim, 0 loses it.
     */
    private function mock_wpdb(int $inserted, string $stored_claim = '', bool $allow_release = true): \Mockery\MockInterface
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function (string $query): string {
            return $query;
        });
        $wpdb->shouldReceive('query')->andReturn($inserted);
        $wpdb->shouldReceive('get_var')->andReturn($stored_claim);
        if ($allow_release) {
            $wpdb->shouldReceive('delete')->andReturn(1);
        }
        $GLOBALS['wpdb'] = $wpdb;

        return $wpdb;
    }
}
