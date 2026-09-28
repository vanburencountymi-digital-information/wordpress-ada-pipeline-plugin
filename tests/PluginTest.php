<?php

namespace AdaRemediationClient\Tests;

use AdaRemediationClient\Plugin;
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
}
