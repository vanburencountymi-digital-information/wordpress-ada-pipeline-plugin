<?php

namespace AdaRemediationClient\Tests;

use AdaRemediationClient\Retry_Policy;
use AdaRemediationClient\Submit_Result;
use PHPUnit\Framework\TestCase;

class Retry_PolicyTest extends TestCase
{
    /**
     * @dataProvider delays
     */
    public function test_the_short_retries_then_the_final_one_then_nothing(int $retry, ?int $expected): void
    {
        $this->assertSame($expected, (new Retry_Policy(300, 3600))->delay_for($retry));
    }

    public function delays(): array
    {
        return [
            'before the first retry' => [0, null],
            'a negative retry' => [-1, null],
            '1st retry' => [1, 300],
            '2nd retry' => [2, 300],
            '3rd retry' => [3, 300],
            '4th retry: the last short one' => [4, 300],
            '5th retry: the final one' => [5, 3600],
            '6th retry: used up' => [6, null],
            'far past it' => [100, null],
        ];
    }

    /**
     * @dataProvider rate_limited_delays
     */
    public function test_delay_after_waits_out_a_time_period_limit_and_otherwise_follows_the_sequence(
        Submit_Result $result,
        int $retry,
        ?int $expected
    ): void {
        $this->assertSame($expected, (new Retry_Policy(300, 3600))->delay_after($result, $retry));
    }

    public function rate_limited_delays(): array
    {
        $rate = static function (?int $wait): Submit_Result {
            return Submit_Result::rate_limited(Submit_Result::LIMIT_RATE, $wait);
        };
        $at_once = Submit_Result::rate_limited(Submit_Result::LIMIT_MAX_AT_ONCE, null);

        return [
            'time period: the pipeline\'s wait' => [$rate(1800), 1, 1800],
            'time period: a day, and the retry is not capped by the sequence' => [$rate(86400), 12, 86400],
            'time period: a wait under a minute is raised to a minute' => [$rate(5), 1, Retry_Policy::MIN_RATE_LIMIT_WAIT_SECONDS],
            'time period: a wait of 0 is raised to a minute' => [$rate(0), 1, Retry_Policy::MIN_RATE_LIMIT_WAIT_SECONDS],
            'time period: the last allowed retry' => [$rate(600), Retry_Policy::MAX_RATE_LIMIT_RETRIES, 600],
            'time period: used up' => [$rate(600), Retry_Policy::MAX_RATE_LIMIT_RETRIES + 1, null],
            'time period with no wait named: the sequence' => [$rate(null), 1, 300],
            'time period with no wait named: the final retry' => [$rate(null), 5, 3600],
            'time period with no wait named: used up' => [$rate(null), 6, null],
            'at once: the sequence' => [$at_once, 2, 300],
            'at once: the final retry' => [$at_once, 5, 3600],
            'at once: used up' => [$at_once, 6, null],
            'at once: a stray wait is not used' => [Submit_Result::rate_limited(Submit_Result::LIMIT_MAX_AT_ONCE, 9000), 1, 300],
            'unknown limit (a proxy): the sequence' => [Submit_Result::rate_limited(null, null), 1, 300],
            'unknown limit: used up' => [Submit_Result::rate_limited(null, null), 6, null],
        ];
    }

    public function test_the_retry_numbers_match_the_constants(): void
    {
        $policy = new Retry_Policy(300, 3600);

        $this->assertSame(300, $policy->delay_for(Retry_Policy::SHORT_RETRIES));
        $this->assertSame(3600, $policy->delay_for(Retry_Policy::FINAL_RETRY));
        $this->assertNull($policy->delay_for(Retry_Policy::FINAL_RETRY + 1));
        $this->assertSame(Retry_Policy::SHORT_RETRIES + 1, Retry_Policy::FINAL_RETRY);
    }

    /**
     * @dataProvider waits_over_the_limit
     */
    public function test_a_wait_over_a_day_counts_as_a_day(int $given): void
    {
        $policy = new Retry_Policy($given, $given);

        $this->assertSame(Retry_Policy::MAX_SECONDS, $policy->delay_for(1));
        $this->assertSame(Retry_Policy::MAX_SECONDS, $policy->delay_for(5));
    }

    public function waits_over_the_limit(): array
    {
        return [
            'one second over' => [Retry_Policy::MAX_SECONDS + 1],
            'a typo with extra digits' => [999999999],
            'the biggest int' => [PHP_INT_MAX],
        ];
    }

    public function test_a_wait_of_exactly_a_day_is_kept(): void
    {
        $this->assertSame(Retry_Policy::MAX_SECONDS, (new Retry_Policy(Retry_Policy::MAX_SECONDS, 1))->delay_for(1));
    }

    /**
     * @dataProvider unusable_arguments
     */
    public function test_a_wait_under_a_second_is_refused(int $interval, int $final): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Retry_Policy($interval, $final);
    }

    public function unusable_arguments(): array
    {
        return [
            'zero interval' => [0, 3600],
            'zero final' => [300, 0],
            'negative interval' => [-1, 3600],
            'negative final' => [300, -1],
        ];
    }

    public function test_the_defaults_match_the_documented_numbers(): void
    {
        $this->assertSame(1200, Retry_Policy::DEFAULT_INTERVAL_SECONDS);
        $this->assertSame(7200, Retry_Policy::DEFAULT_FINAL_INTERVAL_SECONDS);
        $this->assertSame(4, Retry_Policy::SHORT_RETRIES);
        $this->assertSame(86400, Retry_Policy::MAX_SECONDS);
    }

    public function test_with_nothing_set_in_wp_config_the_defaults_apply(): void
    {
        $policy = Retry_Policy::from_constants();

        $this->assertSame(1200, $policy->delay_for(1));
        $this->assertSame(7200, $policy->delay_for(5));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_both_waits_can_be_set_in_wp_config(): void
    {
        define('ADA_REMEDIATION_RETRY_INTERVAL_SECONDS', 300);
        define('ADA_REMEDIATION_RETRY_FINAL_INTERVAL_SECONDS', '3600');

        $policy = Retry_Policy::from_constants();

        $this->assertSame(300, $policy->delay_for(4));
        $this->assertSame(3600, $policy->delay_for(5));
        $this->assertNull($policy->delay_for(6));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_setting_only_one_leaves_the_other_at_its_default(): void
    {
        define('ADA_REMEDIATION_RETRY_FINAL_INTERVAL_SECONDS', 600);

        $policy = Retry_Policy::from_constants();

        $this->assertSame(1200, $policy->delay_for(1));
        $this->assertSame(600, $policy->delay_for(5));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_wp_config_value_over_a_day_counts_as_a_day(): void
    {
        define('ADA_REMEDIATION_RETRY_INTERVAL_SECONDS', 999999999);
        define('ADA_REMEDIATION_RETRY_FINAL_INTERVAL_SECONDS', '999999999');

        $policy = Retry_Policy::from_constants();

        $this->assertSame(86400, $policy->delay_for(1));
        $this->assertSame(86400, $policy->delay_for(5));
    }

    /**
     * @dataProvider unusable_values
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     * @param mixed $value
     */
    public function test_an_unusable_wp_config_value_falls_back_to_the_default($value): void
    {
        define('ADA_REMEDIATION_RETRY_INTERVAL_SECONDS', $value);
        define('ADA_REMEDIATION_RETRY_FINAL_INTERVAL_SECONDS', $value);

        $policy = Retry_Policy::from_constants();

        $this->assertSame(1200, $policy->delay_for(1));
        $this->assertSame(7200, $policy->delay_for(5));
    }

    public function unusable_values(): array
    {
        return [
            'zero' => [0],
            'negative' => [-60],
            'zero as text' => ['0'],
            'negative as text' => ['-60'],
            'words' => ['soon'],
            'a fraction' => ['1.5'],
            'a float' => [90.5],
            'digits too many for an int' => ['99999999999999999999'],
            'empty' => [''],
            'true' => [true],
            'null' => [null],
            'an array' => [[300]],
        ];
    }

    public function test_the_readme_documents_the_current_defaults(): void
    {
        // Line wrapping in the README must not matter, only the words and numbers.
        $readme = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(dirname(__DIR__) . '/README.md'));

        $this->assertStringContainsString(
            sprintf("define('%s', %d);", Retry_Policy::INTERVAL_CONSTANT, Retry_Policy::DEFAULT_INTERVAL_SECONDS),
            $readme
        );
        $this->assertStringContainsString(
            sprintf("define('%s', %d);", Retry_Policy::FINAL_INTERVAL_CONSTANT, Retry_Policy::DEFAULT_FINAL_INTERVAL_SECONDS),
            $readme
        );
        $this->assertStringContainsString(sprintf('tries again %d times', Retry_Policy::SHORT_RETRIES), $readme);
        $this->assertStringContainsString(sprintf('(%d)', Retry_Policy::MAX_SECONDS), $readme);
    }
}
