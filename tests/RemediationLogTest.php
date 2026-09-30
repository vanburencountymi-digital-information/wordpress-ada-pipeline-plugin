<?php

namespace AdaRemediationClient\Tests;

use AdaRemediationClient\Remediation_Log;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class RemediationLogTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_table_name_uses_the_wpdb_prefix(): void
    {
        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_test_'];

        $this->assertSame('wp_test_ada_remediation_log', Remediation_Log::table_name());
    }

    /**
     * dbDelta() is never mocked here: if install() ran anyway, the require_once of a
     * nonexistent wp-admin path would fatal this test — that's the actual assertion.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_maybe_install_skips_install_when_already_at_current_version(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => ['ada_remediation_log_db_version', ''],
            'return' => Remediation_Log::DB_VERSION,
        ]);
        WP_Mock::userFunction('update_option', ['times' => 0]);

        Remediation_Log::maybe_install();
        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_maybe_install_creates_the_table_when_version_is_stale(): void
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_charset_collate')->andReturn('DEFAULT CHARSET=utf8mb4');
        $GLOBALS['wpdb'] = $wpdb;

        WP_Mock::userFunction('get_option', [
            'args' => ['ada_remediation_log_db_version', ''],
            'return' => '0.9',
        ]);
        WP_Mock::userFunction('dbDelta', ['times' => 1])
            ->with(Mockery::on(function (string $sql): bool {
                return strpos($sql, 'wp_ada_remediation_log') !== false
                    && strpos($sql, 'postcheck_json') !== false
                    && strpos($sql, 'warning_message') !== false;
            }));
        WP_Mock::userFunction('update_option', [
            'args' => ['ada_remediation_log_db_version', Remediation_Log::DB_VERSION, true],
            'times' => 1,
        ]);

        Remediation_Log::maybe_install();
        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_record_inserts_a_row_with_the_expected_columns(): void
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $GLOBALS['wpdb'] = $wpdb;

        WP_Mock::userFunction('current_time', [
            'args' => ['mysql', true],
            'return' => '2026-09-28 12:00:00',
        ]);

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ada_remediation_log',
                [
                    'attachment_id' => 42,
                    'remediation_id' => 'remediation-abc-123',
                    'content_hash' => 'deadbeef',
                    'pipeline_version' => '1.2.3',
                    'badge' => 'yellow',
                    'precheck_json' => '{"step":"precheck"}',
                    'postcheck_json' => '{"step":"postcheck"}',
                    'created_gmt' => '2026-09-28 12:00:00',
                ],
                ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );

        Remediation_Log::record(
            42,
            'remediation-abc-123',
            'deadbeef',
            '1.2.3',
            'yellow',
            '{"step":"precheck"}',
            '{"step":"postcheck"}'
        );

        $this->assertConditionsMet();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_record_warning_inserts_a_row_with_the_expected_columns(): void
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $GLOBALS['wpdb'] = $wpdb;

        WP_Mock::userFunction('current_time', [
            'args' => ['mysql', true],
            'return' => '2026-09-28 12:00:00',
        ]);

        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ada_remediation_log',
                [
                    'attachment_id' => 42,
                    'remediation_id' => 'remediation-abc-123',
                    'pipeline_version' => '1.2.3',
                    'warning_message' => 'Replaced attachment file was not reachable after remediation.',
                    'created_gmt' => '2026-09-28 12:00:00',
                ],
                ['%d', '%s', '%s', '%s', '%s']
            );

        Remediation_Log::record_warning(
            42,
            'remediation-abc-123',
            '1.2.3',
            'Replaced attachment file was not reachable after remediation.'
        );

        $this->assertConditionsMet();
    }

    /**
     * dbDelta() silently ignores a PRIMARY KEY line unless it has exactly two spaces
     * before the opening parenthesis, and expects one field definition per line — both
     * are easy to break by accident when adding a column, and a break like that produces
     * no error, just a migration that silently never runs.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_install_sql_still_matches_dbdeltas_required_formatting(): void
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_charset_collate')->andReturn('DEFAULT CHARSET=utf8mb4');
        $GLOBALS['wpdb'] = $wpdb;

        $captured_sql = '';
        WP_Mock::userFunction('dbDelta', ['times' => 1])
            ->with(Mockery::on(function (string $sql) use (&$captured_sql): bool {
                $captured_sql = $sql;

                return true;
            }));

        Remediation_Log::install();

        $this->assertMatchesRegularExpression('/PRIMARY KEY {2}\(/', $captured_sql);
        $this->assertDoesNotMatchRegularExpression('/PRIMARY KEY {3,}\(/', $captured_sql);
        $this->assertMatchesRegularExpression('/^\s*warning_message\s+text\s+NULL,?\s*$/m', $captured_sql);
    }
}
