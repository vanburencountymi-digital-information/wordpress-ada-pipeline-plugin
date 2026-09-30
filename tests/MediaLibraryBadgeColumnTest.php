<?php

namespace AdaRemediationClient\Tests;

use AdaRemediationClient\Media_Library_Badge_Column;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class MediaLibraryBadgeColumnTest extends TestCase
{
    /**
     * Asserts nothing about post type or any Document-CPT-specific class — the
     * column is appended unconditionally, so it registers on a plain attachment
     * list with no county-documents classes present.
     */
    public function test_register_column_appends_the_badge_column_without_touching_existing_ones(): void
    {
        $columns = [
            'cb' => '<input type="checkbox" />',
            'title' => 'File',
            'author' => 'Author',
            'date' => 'Date',
        ];

        $result = Media_Library_Badge_Column::register_column($columns);

        $this->assertSame([
            'cb' => '<input type="checkbox" />',
            'title' => 'File',
            'author' => 'Author',
            'date' => 'Date',
            Media_Library_Badge_Column::COLUMN => 'ADA Remediation Status',
        ], $result);
    }

    public function test_render_column_does_nothing_for_a_different_column(): void
    {
        WP_Mock::userFunction('get_post_meta', ['times' => 0]);

        Media_Library_Badge_Column::render_column('title', 42);

        $this->expectOutputString('');
        $this->assertConditionsMet();
    }

    public function test_render_column_renders_nothing_when_attachment_has_no_remediation_postmeta_yet(): void
    {
        WP_Mock::userFunction('get_post_meta', [
            'args' => [42, '_ada_remediation_badge', true],
            'return' => '',
        ]);

        Media_Library_Badge_Column::render_column(Media_Library_Badge_Column::COLUMN, 42);

        $this->expectOutputString('');
        $this->assertConditionsMet();
    }

    /**
     * @dataProvider badgeColorProvider
     */
    public function test_render_column_outputs_the_correct_color_swatch_and_label_for_each_badge_state(string $badge, string $expected_color, string $expected_label): void
    {
        WP_Mock::userFunction('get_post_meta', [
            'args' => [42, '_ada_remediation_badge', true],
            'return' => $badge,
        ]);
        WP_Mock::userFunction('esc_attr', [
            'return' => static function (string $value): string {
                return $value;
            },
        ]);

        ob_start();
        Media_Library_Badge_Column::render_column(Media_Library_Badge_Column::COLUMN, 42);
        $output = ob_get_clean();

        $this->assertStringContainsString("background:{$expected_color};", $output);
        $this->assertStringContainsString('title="' . $expected_label . '"', $output);
        $this->assertConditionsMet();
    }

    public static function badgeColorProvider(): array
    {
        return [
            'queued (scheduled, not yet submitted)' => ['queued', '#b0c4de', 'Queued for submission'],
            'pending (submitted, pipeline processing)' => ['pending', '#4169e1', 'Remediation in progress'],
            'dark-green (compliant)' => ['dark-green', '#006400', 'Compliant'],
            'light-green (minor)' => ['light-green', '#90ee90', 'Remediation completed with minor issues'],
            'yellow (major)' => ['yellow', '#ffc107', 'Remediation completed with major issues'],
            'red (critical/unclassified)' => ['red', '#dc3545', 'Remediation completed with critical or unclassified issues'],
            'error (error/skipped)' => ['error', '#616161', 'No remediation result available'],
        ];
    }
}
