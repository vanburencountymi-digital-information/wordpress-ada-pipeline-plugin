<?php

namespace AdaRemediationClient\Tests;

use AdaRemediationClient\Client;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class ClientTest extends TestCase
{
    private const SUBMIT_RESPONSE_BODY = '{"id":"remediation-abc-123","document_id":"deadbeef","pipeline_version":"1.2.3","status":"queued"}';

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_submit_attachment_reads_local_file_when_available(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();

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
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_submit_attachment_returns_false_when_fallback_fetch_responds_with_non_2xx_status(): void
    {
        $this->define_config_constants();
        $this->mock_wp_http_helpers();

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

        $wpdb = Mockery::mock('wpdb');
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
    }
}
