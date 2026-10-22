<?php

namespace AdaRemediationClient\Tests;

use AdaRemediationClient\Submit_Result;
use PHPUnit\Framework\TestCase;

class Submit_ResultTest extends TestCase
{
    /**
     * @dataProvider results
     */
    public function test_each_named_constructor_reports_its_outcome(
        Submit_Result $result,
        string $outcome,
        bool $submitted,
        bool $refused,
        bool $failed
    ): void {
        $this->assertSame($outcome, $result->outcome());
        $this->assertSame($submitted, $result->is_submitted());
        $this->assertSame($refused, $result->is_refused());
        $this->assertSame($failed, $result->is_failed());
    }

    public function results(): array
    {
        return [
            'submitted' => [Submit_Result::submitted(), Submit_Result::SUBMITTED, true, false, false],
            'refused' => [Submit_Result::refused(), Submit_Result::REFUSED, false, true, false],
            'failed' => [Submit_Result::failed(), Submit_Result::FAILED, false, false, true],
            'rate limited' => [Submit_Result::rate_limited(null, null), Submit_Result::RATE_LIMITED, false, false, false],
        ];
    }

    public function test_only_a_rate_limited_result_is_rate_limited(): void
    {
        $this->assertTrue(Submit_Result::rate_limited(Submit_Result::LIMIT_RATE, 60)->is_rate_limited());
        $this->assertFalse(Submit_Result::failed()->is_rate_limited());
        $this->assertFalse(Submit_Result::submitted()->is_rate_limited());
    }

    /**
     * @dataProvider retry_afters
     */
    public function test_retry_after_is_kept_within_zero_and_the_cap(?int $given, ?int $expected): void
    {
        $this->assertSame($expected, Submit_Result::rate_limited(Submit_Result::LIMIT_RATE, $given)->retry_after());
    }

    public function retry_afters(): array
    {
        return [
            'none' => [null, null],
            'zero' => [0, 0],
            'normal' => [120, 120],
            'exactly the cap' => [Submit_Result::MAX_RETRY_AFTER_SECONDS, Submit_Result::MAX_RETRY_AFTER_SECONDS],
            'over the cap' => [Submit_Result::MAX_RETRY_AFTER_SECONDS + 1, Submit_Result::MAX_RETRY_AFTER_SECONDS],
            'negative' => [-30, 0],
        ];
    }

    /**
     * @dataProvider limits_with_no_wait
     */
    public function test_a_wait_is_dropped_unless_the_limit_is_the_time_period_one(?string $kind): void
    {
        $this->assertNull(Submit_Result::rate_limited($kind, 600)->retry_after());
    }

    public function limits_with_no_wait(): array
    {
        return [
            'max at once' => [Submit_Result::LIMIT_MAX_AT_ONCE],
            'unknown' => [null],
            'something else' => ['other'],
        ];
    }

    public function test_a_limit_kind_that_is_not_known_is_stored_as_null(): void
    {
        $this->assertNull(Submit_Result::rate_limited('other', 60)->limit_kind());
        $this->assertNull(Submit_Result::rate_limited('', 60)->limit_kind());
    }

    public function test_the_limit_kind_is_kept(): void
    {
        $this->assertSame(Submit_Result::LIMIT_MAX_AT_ONCE, Submit_Result::rate_limited(Submit_Result::LIMIT_MAX_AT_ONCE, null)->limit_kind());
        $this->assertNull(Submit_Result::rate_limited(null, null)->limit_kind());
        $this->assertNull(Submit_Result::failed()->limit_kind());
    }

    public function test_with_quota_returns_a_copy_and_leaves_the_original_alone(): void
    {
        $original = Submit_Result::rate_limited(Submit_Result::LIMIT_RATE, 60);
        $copy     = $original->with_quota([Submit_Result::QUOTA_REMAINING => 0]);

        $this->assertSame([], $original->quota());
        $this->assertSame([Submit_Result::QUOTA_REMAINING => 0], $copy->quota());
        $this->assertSame(Submit_Result::RATE_LIMITED, $copy->outcome());
        $this->assertSame(60, $copy->retry_after());
        $this->assertSame(Submit_Result::LIMIT_RATE, $copy->limit_kind());
    }
}
