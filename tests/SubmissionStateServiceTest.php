<?php

namespace AdaRemediationClient\Tests;

use AdaRemediationClient\Client;
use AdaRemediationClient\Submission_State_Service;
use AdaRemediationClient\Submit_Result;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

class SubmissionStateServiceTest extends TestCase
{
    private const CLAIM_OPTION = 'ada_remediation_submit_claim_42';
    private const BADGE = '_ada_remediation_badge';
    private const STAMP = '_ada_remediation_submitted_at';
    private const LEGACY_STAMP = '_county_ada_submitted_at';

    /** @var string */
    private $log_file;

    /** @var string|false */
    private $previous_error_log;

    public function setUp(): void
    {
        parent::setUp();
        $this->log_file = tempnam(sys_get_temp_dir(), 'ada-log');
        $this->previous_error_log = ini_set('error_log', $this->log_file);
    }

    public function tearDown(): void
    {
        ini_set('error_log', (string) $this->previous_error_log);
        unlink($this->log_file);
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function test_run_exclusive_claims_with_insert_ignore_runs_the_callback_and_releases_only_its_own_claim(): void
    {
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        $this->meta([]);
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('prepare')
            ->with(Mockery::pattern('/^INSERT IGNORE INTO wp_options /'), self::CLAIM_OPTION, Mockery::pattern('/^\d+\|uuid$/'))
            ->once()
            ->andReturn('SQL');
        $wpdb->shouldReceive('query')->once()->with('SQL')->andReturn(1);
        $wpdb->shouldReceive('delete')
            ->once()
            ->with(
                'wp_options',
                Mockery::on(static function ($where): bool {
                    return is_array($where)
                        && self::CLAIM_OPTION === ($where['option_name'] ?? null)
                        && 1 === preg_match('/^\d+\|uuid$/', (string) ($where['option_value'] ?? ''));
                })
            );

        $this->assertSame(Submit_Result::SUBMITTED, Submission_State_Service::run_exclusive(42, static function (): Submit_Result {
            return Submit_Result::submitted();
        })->outcome());
    }

    /**
     * @dataProvider callback_results
     */
    public function test_run_exclusive_returns_what_the_callback_returns(Submit_Result $result): void
    {
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        $this->meta([]);
        $this->free_wpdb();

        $this->assertSame($result, Submission_State_Service::run_exclusive(42, static function () use ($result): Submit_Result {
            return $result;
        }));
    }

    public function callback_results(): array
    {
        return [
            'success' => [Submit_Result::submitted()],
            'failure' => [Submit_Result::failed()],
        ];
    }

    /**
     * @dataProvider wrong_callback_results
     * @param mixed $wrong
     */
    public function test_run_exclusive_rejects_a_callback_that_does_not_return_a_submit_result_and_still_releases($wrong): void
    {
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        $this->meta([]);
        $wpdb = $this->free_wpdb(false);
        $wpdb->shouldReceive('delete')->once();

        $this->expectException(\UnexpectedValueException::class);

        Submission_State_Service::run_exclusive(42, static function () use ($wrong) {
            return $wrong;
        });
    }

    public function wrong_callback_results(): array
    {
        return [
            'bool' => [true],
            'null' => [null],
            'string' => ['submitted'],
        ];
    }

    public function test_run_exclusive_does_nothing_when_the_precondition_fails_but_still_releases(): void
    {
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        $this->meta([]);
        $wpdb = $this->free_wpdb(false);
        $wpdb->shouldReceive('delete')->once();
        $called = false;

        $result = Submission_State_Service::run_exclusive(
            42,
            static function () use (&$called): Submit_Result {
                $called = true;

                return Submit_Result::submitted();
            },
            static function (): bool {
                return false;
            }
        );

        $this->assertSame(Submit_Result::REFUSED, $result->outcome());
        $this->assertFalse($called);
        $this->assertStringContainsString('submit skipped for attachment 42: the file is no longer waiting for this submit', (string) file_get_contents($this->log_file));
    }

    public function test_run_exclusive_checks_the_precondition_only_once_the_claim_is_held(): void
    {
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('query')->andReturn(0);
        $wpdb->shouldReceive('get_var')->andReturn((time() - 10) . '|other');
        $wpdb->shouldReceive('update')->never();
        $wpdb->shouldReceive('delete')->never();
        $asked = false;

        $result = Submission_State_Service::run_exclusive(
            42,
            static function (): Submit_Result {
                return Submit_Result::submitted();
            },
            static function () use (&$asked): bool {
                $asked = true;

                return true;
            }
        );

        // Someone else holds the claim: refused, and the precondition was never asked.
        $this->assertSame(Submit_Result::REFUSED, $result->outcome());
        $this->assertFalse($asked);
    }

    public function test_run_exclusive_runs_the_callback_when_the_precondition_holds(): void
    {
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        $this->meta([]);
        $this->free_wpdb();

        $result = Submission_State_Service::run_exclusive(
            42,
            static function (): Submit_Result {
                return Submit_Result::submitted();
            },
            static function (): bool {
                return true;
            }
        );

        $this->assertSame(Submit_Result::SUBMITTED, $result->outcome());
    }

    /**
     * @dataProvider first_try_badges
     */
    public function test_a_first_try_is_awaited_only_by_a_queued_or_unmarked_file(string $badge, bool $expected): void
    {
        $this->meta($badge === '' ? [] : [self::BADGE => $badge]);

        $this->assertSame($expected, Submission_State_Service::is_awaiting_first_try(42));
    }

    public function first_try_badges(): array
    {
        return [
            'never marked' => ['', true],
            'queued' => ['queued', true],
            'retrying' => ['retrying', false],
            'pending' => ['pending', false],
            'error' => ['error', false],
            'compliant' => ['dark-green', false],
            'an adapter badge' => ['county-custom', false],
        ];
    }

    public function test_run_exclusive_releases_the_claim_when_the_callback_throws(): void
    {
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        $this->meta([]);
        $wpdb = $this->free_wpdb(false);
        $wpdb->shouldReceive('delete')->once();

        try {
            Submission_State_Service::run_exclusive(42, static function (): Submit_Result {
                throw new \RuntimeException('boom');
            });
            $this->fail('The exception should propagate.');
        } catch (\RuntimeException $e) {
            $this->addToAssertionCount(1); // The delete()->once() is verified on teardown.
        }
    }

    public function test_run_exclusive_does_nothing_while_a_recent_claim_is_held(): void
    {
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        $called = false;
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('query')->andReturn(0);
        $wpdb->shouldReceive('get_var')->andReturn((time() - 10) . '|other');
        // Not ours, so it must be neither taken over nor released.
        $wpdb->shouldReceive('update')->never();
        $wpdb->shouldReceive('delete')->never();

        $this->assertSame(Submit_Result::REFUSED, Submission_State_Service::run_exclusive(42, static function () use (&$called): Submit_Result {
            $called = true;

            return Submit_Result::submitted();
        })->outcome());
        $this->assertFalse($called);
        $this->assertStringContainsString('submit skipped for attachment 42: another submit holds the claim', (string) file_get_contents($this->log_file));
    }

    public function test_run_exclusive_does_nothing_while_the_pipeline_has_a_pending_job_but_still_releases(): void
    {
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        $this->meta(['_ada_remediation_badge' => 'pending', self::STAMP => time() - 5]);
        $called = false;
        $wpdb = $this->free_wpdb(false);
        $wpdb->shouldReceive('delete')->once();

        $this->assertSame(Submit_Result::REFUSED, Submission_State_Service::run_exclusive(42, static function () use (&$called): Submit_Result {
            $called = true;

            return Submit_Result::submitted();
        })->outcome());
        $this->assertFalse($called);
        $this->assertStringContainsString('submit skipped for attachment 42: the pipeline has a pending job', (string) file_get_contents($this->log_file));
    }

    public function test_a_queued_file_is_not_blocked_by_its_own_queued_badge(): void
    {
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        $this->meta(['_ada_remediation_badge' => 'queued', self::STAMP => time() - 5]);
        $this->free_wpdb();

        $this->assertSame(Submit_Result::SUBMITTED, Submission_State_Service::run_exclusive(42, static function (): Submit_Result {
            return Submit_Result::submitted();
        })->outcome());
    }

    public function test_run_exclusive_retries_the_insert_when_the_holder_released_in_between(): void
    {
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        $this->meta([]);
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        // First insert loses to a holder, who releases before our read; the second insert wins.
        $wpdb->shouldReceive('query')->twice()->andReturn(0, 1);
        $wpdb->shouldReceive('get_var')->once()->andReturn('');
        $wpdb->shouldReceive('update')->never();
        $wpdb->shouldReceive('delete')->andReturn(1);

        $this->assertSame(Submit_Result::SUBMITTED, Submission_State_Service::run_exclusive(42, static function (): Submit_Result {
            return Submit_Result::submitted();
        })->outcome());
    }

    public function test_run_exclusive_retries_the_insert_only_once(): void
    {
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('query')->twice()->andReturn(0, 0);
        $wpdb->shouldReceive('get_var')->once()->andReturn('');
        $wpdb->shouldReceive('delete')->never();

        $this->assertSame(Submit_Result::REFUSED, Submission_State_Service::run_exclusive(42, static function (): Submit_Result {
            return Submit_Result::submitted();
        })->outcome());
    }

    /**
     * @dataProvider takeover_results
     */
    public function test_run_exclusive_takes_over_a_stale_claim_only_if_the_conditional_update_changes_a_row($rows_changed, bool $won): void
    {
        $stale = (time() - Submission_State_Service::CLAIM_TTL_SECONDS - 5) . '|dead';
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        $this->meta([]);
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('query')->andReturn(0);
        $wpdb->shouldReceive('get_var')->andReturn($stale);
        $wpdb->shouldReceive('delete')->andReturn(1);
        // The WHERE must match the value just read, or two requests could both win.
        $wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_options',
                Mockery::on(static function ($data): bool {
                    return is_array($data) && 1 === preg_match('/^\d+\|uuid$/', (string) ($data['option_value'] ?? ''));
                }),
                ['option_name' => self::CLAIM_OPTION, 'option_value' => $stale]
            )
            ->andReturn($rows_changed);
        $called = false;

        Submission_State_Service::run_exclusive(42, static function () use (&$called): Submit_Result {
            $called = true;

            return Submit_Result::submitted();
        });

        $this->assertSame($won, $called);
    }

    public function takeover_results(): array
    {
        return [
            'one row changed: we won' => [1, true],
            'no row changed: someone else won' => [0, false],
            'query failed' => [false, false],
        ];
    }

    /**
     * @dataProvider mark_methods
     */
    public function test_mark_writes_the_badge_and_the_stamp_together(string $method, string $badge): void
    {
        $this->meta([]);
        WP_Mock::userFunction('update_post_meta', ['times' => 1])->with(42, self::BADGE, $badge);
        WP_Mock::userFunction('update_post_meta', ['times' => 1])->with(42, self::STAMP, Mockery::type('integer'));

        Submission_State_Service::$method(42);
        $this->assertConditionsMet();
    }

    /**
     * @dataProvider mark_methods
     */
    public function test_mark_writes_the_stamp_before_the_badge(string $method): void
    {
        $this->meta([]);
        $order = [];
        WP_Mock::userFunction('update_post_meta', [
            'return' => static function ($id, $key) use (&$order): bool {
                $order[] = $key;

                return true;
            },
        ]);

        Submission_State_Service::$method(42);

        $this->assertSame([self::STAMP, self::BADGE], $order);
    }

    public function test_mark_queued_does_nothing_while_the_pipeline_has_a_pending_job(): void
    {
        $this->meta(['_ada_remediation_badge' => 'pending', self::STAMP => time() - 5]);
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);

        $this->assertFalse(Submission_State_Service::mark_queued(42));
        $this->assertStringContainsString('submit skipped for attachment 42: the pipeline has a pending job', (string) file_get_contents($this->log_file));
        $this->assertConditionsMet();
    }

    public function test_mark_queued_requeues_a_pending_file_whose_stamp_has_gone_stale(): void
    {
        $this->meta(['_ada_remediation_badge' => 'pending', self::STAMP => time() - Submission_State_Service::STALE_AFTER_SECONDS - 5]);
        WP_Mock::userFunction('update_post_meta', ['times' => 1])->with(42, self::BADGE, 'queued');
        WP_Mock::userFunction('update_post_meta', ['times' => 1])->with(42, self::STAMP, Mockery::type('integer'));

        $this->assertTrue(Submission_State_Service::mark_queued(42));
        $this->assertConditionsMet();
    }

    /**
     * @dataProvider badge_inputs
     */
    public function test_mark_result_stores_the_badge_as_given(string $badge): void
    {
        WP_Mock::userFunction('update_post_meta', ['times' => 1])->with(42, self::BADGE, $badge);

        Submission_State_Service::mark_result(42, $badge);
        $this->assertConditionsMet();
    }

    public function badge_inputs(): array
    {
        return [
            'dark green' => ['dark-green'],
            'red' => ['red'],
            'error' => ['error'],
            'adapter-defined badge' => ['needs-review'],
        ];
    }

    public function test_the_column_has_a_color_and_label_for_every_badge_constant(): void
    {
        $constants = array_values(array_filter(
            (new \ReflectionClass(Submission_State_Service::class))->getConstants(),
            static function ($value, $name): bool {
                return 0 === strpos($name, 'BADGE_');
            },
            ARRAY_FILTER_USE_BOTH
        ));

        $this->assertEqualsCanonicalizing($constants, array_keys(\AdaRemediationClient\Media_Library_Badge_Column::BADGE_COLORS));
        $this->assertEqualsCanonicalizing($constants, array_keys(\AdaRemediationClient\Media_Library_Badge_Column::TOOLTIP_LABELS));
    }

    public function test_mark_retrying_stamps_the_time_of_the_next_try_and_badges_it_retrying(): void
    {
        $next_try = time() + 7200;
        $order = [];
        $this->meta([]);
        WP_Mock::userFunction('update_post_meta', [
            'return' => static function ($id, $key, $value) use (&$order): bool {
                $order[] = [$key, $value];

                return true;
            },
        ]);

        $this->assertTrue(Submission_State_Service::mark_retrying(42, $next_try));
        // The stamp first, then the badge, as for mark_queued().
        $this->assertSame([[self::STAMP, $next_try], [self::BADGE, 'retrying']], $order);
    }

    public function test_mark_retrying_does_nothing_while_the_pipeline_has_a_pending_job(): void
    {
        $this->meta(['_ada_remediation_badge' => 'pending', self::STAMP => time() - 5]);
        WP_Mock::userFunction('update_post_meta', ['times' => 0]);

        $this->assertFalse(Submission_State_Service::mark_retrying(42, time() + 7200));
        $this->assertStringContainsString('the pipeline has a pending job, so it was not marked retrying', (string) file_get_contents($this->log_file));
        $this->assertConditionsMet();
    }

    /**
     * @dataProvider retry_states
     */
    public function test_a_retry_is_scheduled_only_for_a_file_retrying_with_exactly_that_due_time(array $meta, int $due_at, bool $expected): void
    {
        $this->meta($meta);

        $this->assertSame($expected, Submission_State_Service::is_retry_scheduled_for(42, $due_at));
    }

    public function retry_states(): array
    {
        $due = 1800000000;

        return [
            'retrying, same time' => [[self::BADGE => 'retrying', self::STAMP => $due], $due, true],
            'retrying, a newer retry replaced it' => [[self::BADGE => 'retrying', self::STAMP => $due + 1200], $due, false],
            'retrying, an older stamp' => [[self::BADGE => 'retrying', self::STAMP => $due - 1], $due, false],
            'submitted another way: pending' => [[self::BADGE => 'pending', self::STAMP => $due], $due, false],
            'queued again' => [[self::BADGE => 'queued', self::STAMP => $due], $due, false],
            'a final result' => [[self::BADGE => 'dark-green', self::STAMP => $due], $due, false],
            'failed' => [[self::BADGE => 'error', self::STAMP => $due], $due, false],
            'no badge' => [[self::STAMP => $due], $due, false],
            'no stamp' => [[self::BADGE => 'retrying'], $due, false],
            'the adapter legacy stamp does not count' => [[self::BADGE => 'retrying', self::LEGACY_STAMP => $due], $due, false],
        ];
    }

    public function mark_methods(): array
    {
        return [
            'queued' => ['mark_queued', 'queued'],
            'pending' => ['mark_pending', 'pending'],
        ];
    }

    /**
     * A webhook can match as soon as the id is stored, so a pending badge written after that
     * would overwrite its result.
     */
    public function test_mark_submitted_writes_the_stamp_and_pending_badge_before_the_id(): void
    {
        $order = [];
        WP_Mock::userFunction('update_post_meta', [
            'return' => static function ($id, $key, $value) use (&$order): bool {
                $order[] = $key . '=' . (is_int($value) ? 'int' : $value);

                return true;
            },
        ]);

        Submission_State_Service::mark_submitted(42, 'rem-1', 'hash-1', '1.2.3');

        $this->assertSame([
            self::STAMP . '=int',
            self::BADGE . '=pending',
            '_ada_remediation_id=rem-1',
            '_ada_remediation_content_hash=hash-1',
            '_ada_remediation_pipeline_version=1.2.3',
        ], $order);
    }

    public function test_record_submission_stores_the_id_hash_and_version(): void
    {
        WP_Mock::userFunction('update_post_meta', ['times' => 1])->with(42, '_ada_remediation_id', 'rem-1');
        WP_Mock::userFunction('update_post_meta', ['times' => 1])->with(42, '_ada_remediation_content_hash', 'hash-1');
        WP_Mock::userFunction('update_post_meta', ['times' => 1])->with(42, '_ada_remediation_pipeline_version', '1.2.3');

        Submission_State_Service::record_submission(42, 'rem-1', 'hash-1', '1.2.3');
        $this->assertConditionsMet();
    }

    /**
     * @dataProvider blank_optional_values
     */
    public function test_record_submission_skips_a_hash_or_version_that_is_missing_or_empty(?string $blank): void
    {
        WP_Mock::userFunction('update_post_meta', ['times' => 1])->with(42, '_ada_remediation_id', 'rem-1');

        Submission_State_Service::record_submission(42, 'rem-1', $blank, $blank);
        $this->assertConditionsMet();
    }

    public function blank_optional_values(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
        ];
    }

    public function test_remediation_id_reads_the_stored_id_as_a_string(): void
    {
        $this->meta(['_ada_remediation_id' => 'rem-1']);

        $this->assertSame('rem-1', Submission_State_Service::remediation_id(42));
    }

    public function test_remediation_id_is_an_empty_string_when_none_is_stored(): void
    {
        $this->meta([]);

        $this->assertSame('', Submission_State_Service::remediation_id(42));
    }

    public function test_applied_and_checked_markers_use_their_own_keys(): void
    {
        WP_Mock::userFunction('update_post_meta', ['times' => 1])->with(42, '_ada_remediation_applied_id', 'rem-1');
        WP_Mock::userFunction('update_post_meta', ['times' => 1])->with(42, '_ada_remediation_checked_at', '2026-10-21 12:00:00');
        $this->meta(['_ada_remediation_applied_id' => 'rem-1']);

        Submission_State_Service::mark_applied(42, 'rem-1');
        Submission_State_Service::mark_checked(42, '2026-10-21 12:00:00');

        $this->assertSame('rem-1', Submission_State_Service::applied_remediation_id(42));
        $this->assertConditionsMet();
    }

    public function test_find_attachment_id_looks_up_by_the_remediation_id_meta(): void
    {
        WP_Mock::userFunction('get_posts', ['times' => 1])
            ->with(Mockery::on(static function ($args): bool {
                return 'attachment' === $args['post_type']
                    && '_ada_remediation_id' === $args['meta_key']
                    && 'rem-1' === $args['meta_value'];
            }))
            ->andReturn(array(42));
        WP_Mock::userFunction('get_posts', ['times' => 1])->with(Mockery::any())->andReturn(array());

        $this->assertSame(42, Submission_State_Service::find_attachment_id('rem-1'));
        $this->assertSame(0, Submission_State_Service::find_attachment_id('nope'));
    }

    public function test_a_claim_token_gives_back_the_time_it_was_taken(): void
    {
        WP_Mock::userFunction('wp_generate_uuid4', ['return' => 'uuid']);
        $class = new \ReflectionClass(Submission_State_Service::class);
        $build = $class->getMethod('build_claim_token');
        $build->setAccessible(true);
        $started = $class->getMethod('claim_started_at');
        $started->setAccessible(true);

        $token = $build->invoke(null, 1700000000);

        $this->assertSame('1700000000|uuid', $token);
        $this->assertSame(1700000000, $started->invoke(null, $token));
        $this->assertSame(0, $started->invoke(null, ''));
    }

    /**
     * @dataProvider failed_submission_cases
     */
    public function test_is_failed_submission_needs_an_attachment_with_the_error_badge_and_no_id(string $type, array $meta, bool $expected): void
    {
        WP_Mock::userFunction('get_post_type', ['return' => $type]);
        $this->meta($meta);

        $this->assertSame($expected, Submission_State_Service::is_failed_submission(42));
    }

    public function failed_submission_cases(): array
    {
        return [
            'attachment, error, no id' => ['attachment', ['_ada_remediation_badge' => 'error'], true],
            'has an id' => ['attachment', ['_ada_remediation_badge' => 'error', '_ada_remediation_id' => 'rem-1'], false],
            'not the error badge' => ['attachment', ['_ada_remediation_badge' => 'pending'], false],
            'not an attachment' => ['post', ['_ada_remediation_badge' => 'error'], false],
        ];
    }

    public function test_the_claim_outlasts_the_submit_request(): void
    {
        $timeout = (new \ReflectionClassConstant(Client::class, 'SUBMIT_TIMEOUT_SECONDS'))->getValue();

        $this->assertGreaterThan($timeout, Submission_State_Service::CLAIM_TTL_SECONDS);
        $this->assertLessThan(Submission_State_Service::STALE_AFTER_SECONDS, Submission_State_Service::QUEUED_STALE_AFTER_SECONDS);
    }

    /**
     * @dataProvider in_flight_cases
     */
    public function test_is_in_flight(array $meta, bool $expected): void
    {
        $claim = (string) ($meta['claim'] ?? '');
        unset($meta['claim']);
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn($claim);
        $this->meta($meta);

        $this->assertSame($expected, Submission_State_Service::is_in_flight(42));
    }

    public function in_flight_cases(): array
    {
        $recent = time() - 5;
        $stale_queued = time() - Submission_State_Service::QUEUED_STALE_AFTER_SECONDS - 5;
        $stale_pending = time() - Submission_State_Service::STALE_AFTER_SECONDS - 5;

        return [
            'live claim' => [['claim' => $recent . '|x'], true],
            'expired claim' => [['claim' => (time() - Submission_State_Service::CLAIM_TTL_SECONDS - 5) . '|x'], false],
            'pending and recent' => [['_ada_remediation_badge' => 'pending', self::STAMP => $recent], true],
            'pending past STALE_AFTER_SECONDS' => [['_ada_remediation_badge' => 'pending', self::STAMP => $stale_pending], false],
            'pending but still queued-fresh stamp' => [['_ada_remediation_badge' => 'pending', self::STAMP => time() - Submission_State_Service::QUEUED_STALE_AFTER_SECONDS - 5], true],
            'queued and recent' => [['_ada_remediation_badge' => 'queued', self::STAMP => $recent], true],
            'queued past QUEUED_STALE_AFTER_SECONDS' => [['_ada_remediation_badge' => 'queued', self::STAMP => $stale_queued], false],
            'retrying, next try two hours from now' => [['_ada_remediation_badge' => 'retrying', self::STAMP => time() + 7200], true],
            'retrying, next try just passed' => [['_ada_remediation_badge' => 'retrying', self::STAMP => time() - 5], true],
            'retrying, next try long gone' => [['_ada_remediation_badge' => 'retrying', self::STAMP => $stale_queued], false],
            'queued with a future stamp' => [['_ada_remediation_badge' => 'queued', self::STAMP => time() + 7200], true],
            'error, with a retry time ahead' => [['_ada_remediation_badge' => 'error', self::STAMP => time() + 7200], false],
            'in progress with no stamp' => [['_ada_remediation_badge' => 'pending'], false],
            'finished badge, recent stamp' => [['_ada_remediation_badge' => 'complete', self::STAMP => $recent], false],
            'legacy adapter stamp counts' => [['_ada_remediation_badge' => 'pending', self::LEGACY_STAMP => $recent], true],
            'newer of the two stamps wins' => [['_ada_remediation_badge' => 'pending', self::STAMP => $stale_pending, self::LEGACY_STAMP => $recent], true],
            'nothing recorded' => [[], false],
        ];
    }

    /**
     * A $wpdb where nobody holds the claim: the INSERT wins.
     */
    private function free_wpdb(bool $allow_release = true): \Mockery\MockInterface
    {
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('query')->andReturn(1);
        if ($allow_release) {
            $wpdb->shouldReceive('delete')->andReturn(1);
        }

        return $wpdb;
    }

    private function wpdb(): \Mockery\MockInterface
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->options = 'wp_options';
        $GLOBALS['wpdb'] = $wpdb;

        return $wpdb;
    }

    /**
     * Stubs get_post_meta() from a key => value map ('' for anything not in it).
     */
    private function meta(array $values): void
    {
        WP_Mock::userFunction('get_post_meta', [
            'return' => static function ($id, $key) use ($values) {
                return $values[$key] ?? '';
            },
        ]);
    }
}
