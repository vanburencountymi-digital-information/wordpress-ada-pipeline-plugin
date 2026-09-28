<?php

namespace AdaRemediationClient\Tests;

use AdaRemediationClient\Client;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class ClientTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_submit_attachment_reads_local_file_when_available(): void
    {
        $this->define_config_constants();

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
            'return' => ['response' => ['code' => 201]],
        ]);
        WP_Mock::userFunction('is_wp_error', ['return' => false]);
        WP_Mock::userFunction('wp_remote_retrieve_response_code', ['return' => 201]);

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
            'return' => ['body' => '%PDF-1.4 remote bytes'],
        ]);
        WP_Mock::userFunction('wp_remote_retrieve_body', [
            'return' => '%PDF-1.4 remote bytes',
        ]);
        WP_Mock::userFunction('rest_url', [
            'return' => 'https://example.org/wp-json/ada-remediation/v1/callback',
        ]);

        WP_Mock::userFunction('wp_remote_post', [
            'times' => 1,
            'return' => ['response' => ['code' => 201]],
        ]);
        WP_Mock::userFunction('is_wp_error', ['return' => false]);
        WP_Mock::userFunction('wp_remote_retrieve_response_code', ['return' => 200]);

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

        WP_Mock::userFunction('get_attached_file', [
            'return' => '/uploads/2026/09/report.pdf',
        ]);
        WP_Mock::userFunction('AdaRemediationClient\\file_exists', ['return' => true]);
        WP_Mock::userFunction('AdaRemediationClient\\file_get_contents', ['return' => '%PDF-1.4 local bytes']);
        WP_Mock::userFunction('rest_url', [
            'return' => 'https://example.org/wp-json/ada-remediation/v1/callback',
        ]);
        WP_Mock::userFunction('is_wp_error', ['return' => false]);
        WP_Mock::userFunction('wp_remote_retrieve_response_code', ['return' => 201]);

        WP_Mock::userFunction('wp_remote_post', [
            'times' => 1,
            'return' => ['response' => ['code' => 201]],
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
    public function test_submit_attachment_returns_false_when_no_file_could_be_read(): void
    {
        $this->define_config_constants();

        WP_Mock::userFunction('get_attached_file', ['return' => false]);
        WP_Mock::userFunction('wp_get_attachment_url', ['return' => 'https://example.org/missing.pdf']);
        WP_Mock::userFunction('wp_remote_get', [
            'return' => ['errors' => ['http_request_failed' => ['Could not resolve host']]],
        ]);
        WP_Mock::userFunction('is_wp_error', ['return' => true]);
        WP_Mock::userFunction('wp_remote_post', ['times' => 0]);

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
            'return' => ['response' => ['code' => 401]],
        ]);
        WP_Mock::userFunction('is_wp_error', ['return' => false]);
        WP_Mock::userFunction('wp_remote_retrieve_response_code', ['return' => 401]);

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

        WP_Mock::userFunction('get_attached_file', ['return' => false]);
        WP_Mock::userFunction('wp_get_attachment_url', ['return' => 'https://example.org/missing.pdf']);

        // Transport succeeded (not a WP_Error) but the URL 404s — the body would be an
        // HTML error page, not PDF bytes, so it must not be shipped to the pipeline.
        WP_Mock::userFunction('wp_remote_get', [
            'return' => ['response' => ['code' => 404], 'body' => '<html>Not Found</html>'],
        ]);
        WP_Mock::userFunction('is_wp_error', ['return' => false]);
        WP_Mock::userFunction('wp_remote_retrieve_response_code', ['return' => 404]);
        WP_Mock::userFunction('wp_remote_post', ['times' => 0]);

        $this->assertFalse(Client::submit_attachment(42));
        $this->assertConditionsMet();
    }

    private function define_config_constants(): void
    {
        define('ADA_REMEDIATION_API_BASE_URL', 'https://pipeline.example.org');
        define('ADA_REMEDIATION_API_TOKEN', 'test-token');
        define('ADA_REMEDIATION_WEBHOOK_SECRET', 'test-secret');
    }
}
