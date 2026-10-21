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
        ];
    }
}
