<?php

namespace AdaRemediationClient\Tests;

use AdaRemediationClient\Client;
use AdaRemediationClient\File_Replacer;
use AdaRemediationClient\Plugin;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class PluginTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_is_configured_returns_false_when_no_constants_are_defined(): void
    {
        $this->assertFalse(Plugin::is_configured());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     * @dataProvider missingConstantProvider
     */
    public function test_is_configured_returns_false_when_one_constant_is_missing(string $missing): void
    {
        foreach (Plugin::REQUIRED_CONSTANTS as $constant) {
            if ($constant !== $missing) {
                define($constant, 'value');
            }
        }

        $this->assertFalse(Plugin::is_configured());
    }

    public static function missingConstantProvider(): array
    {
        return [
            'missing base URL' => ['ADA_REMEDIATION_API_BASE_URL'],
            'missing API token' => ['ADA_REMEDIATION_API_TOKEN'],
            'missing webhook secret' => ['ADA_REMEDIATION_WEBHOOK_SECRET'],
        ];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_is_configured_returns_true_when_all_constants_are_defined(): void
    {
        foreach (Plugin::REQUIRED_CONSTANTS as $constant) {
            define($constant, 'value');
        }

        $this->assertTrue(Plugin::is_configured());
    }

    /**
     * No WP_Mock expectations are set up here — if boot() called any WordPress
     * function while unconfigured, WP_Mock would fail this test on that call.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_boot_is_inert_and_touches_no_wordpress_functions_when_unconfigured(): void
    {
        Plugin::boot();

        $this->assertFalse(Plugin::is_configured());
    }

    public function test_client_namespace_is_loadable_for_adapters(): void
    {
        $this->assertTrue(class_exists('AdaRemediationClient\\Client'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_boot_registers_add_attachment_trigger_when_configured(): void
    {
        foreach (Plugin::REQUIRED_CONSTANTS as $constant) {
            define($constant, 'value');
        }

        // Short-circuits Remediation_Log::maybe_install() before its install() branch,
        // which needs a real ABSPATH/wp-admin/includes/upgrade.php — see RemediationLogTest
        // for that method's own coverage.
        WP_Mock::userFunction('get_option', [
            'args' => ['ada_remediation_log_db_version', ''],
            'return' => \AdaRemediationClient\Remediation_Log::DB_VERSION,
        ]);

        WP_Mock::expectActionAdded('add_attachment', [Plugin::class, 'maybe_submit_on_upload']);
        WP_Mock::expectActionAdded('ada_remediation_submit_attachment', [Client::class, 'submit_attachment']);
        WP_Mock::expectActionAdded('rest_api_init', [\AdaRemediationClient\Webhook::class, 'register_routes']);
        WP_Mock::expectFilterAdded('manage_media_columns', [\AdaRemediationClient\Media_Library_Badge_Column::class, 'register_column']);
        WP_Mock::expectActionAdded('manage_media_custom_column', [\AdaRemediationClient\Media_Library_Badge_Column::class, 'render_column'], 10, 2);
        WP_Mock::expectActionAdded('ada_remediation_result', [File_Replacer::class, 'maybe_replace'], File_Replacer::HOOK_PRIORITY, 2);

        Plugin::boot();

        $this->assertHooksAdded();
    }

    public function test_should_auto_submit_is_true_for_a_pdf_when_filter_allows_it(): void
    {
        WP_Mock::userFunction('get_post_mime_type', [
            'args' => [42],
            'return' => 'application/pdf',
        ]);
        WP_Mock::onFilter('ada_remediation_auto_trigger_on_upload')
            ->with(true, 42)
            ->reply(true);

        $this->assertTrue(Plugin::should_auto_submit(42));
    }

    public function test_should_auto_submit_is_false_when_filter_suppresses_it(): void
    {
        WP_Mock::userFunction('get_post_mime_type', [
            'args' => [42],
            'return' => 'application/pdf',
        ]);
        WP_Mock::onFilter('ada_remediation_auto_trigger_on_upload')
            ->with(true, 42)
            ->reply(false);

        $this->assertFalse(Plugin::should_auto_submit(42));
    }

    public function test_should_auto_submit_is_false_for_non_pdf_attachments_without_consulting_the_filter(): void
    {
        WP_Mock::userFunction('get_post_mime_type', [
            'args' => [42],
            'return' => 'image/jpeg',
        ]);
        // No onFilter() expectation set up: if should_auto_submit() called
        // apply_filters() anyway for a non-PDF attachment, WP_Mock would fail here.

        $this->assertFalse(Plugin::should_auto_submit(42));
    }

    /**
     * Scheduling (rather than calling Client::submit_attachment() directly) keeps the
     * pipeline's HTTP round-trip — including a possible API cold start — off of the
     * upload request itself.
     */
    public function test_maybe_submit_on_upload_schedules_submission_for_a_pdf_when_filter_allows_it(): void
    {
        WP_Mock::userFunction('get_post_mime_type', [
            'args' => [42],
            'return' => 'application/pdf',
        ]);
        WP_Mock::onFilter('ada_remediation_auto_trigger_on_upload')
            ->with(true, 42)
            ->reply(true);

        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 1])
            ->with(Mockery::type('integer'), 'ada_remediation_submit_attachment', [42]);
        WP_Mock::userFunction('update_post_meta', ['times' => 1])
            ->with(42, '_ada_remediation_badge', 'queued');

        Plugin::maybe_submit_on_upload(42);
        $this->assertConditionsMet();
    }

    public function test_maybe_submit_on_upload_does_not_schedule_when_filter_suppresses_it(): void
    {
        WP_Mock::userFunction('get_post_mime_type', [
            'args' => [42],
            'return' => 'application/pdf',
        ]);
        WP_Mock::onFilter('ada_remediation_auto_trigger_on_upload')
            ->with(true, 42)
            ->reply(false);

        WP_Mock::userFunction('wp_schedule_single_event', ['times' => 0]);
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);

        Plugin::maybe_submit_on_upload(42);
        $this->assertConditionsMet();
    }
}
