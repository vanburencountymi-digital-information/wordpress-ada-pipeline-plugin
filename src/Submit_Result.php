<?php
/**
 * What happened to one submit.
 *
 * @package AdaRemediationClient
 */

namespace AdaRemediationClient;

/**
 * Lets a caller tell the outcomes of a submit apart: a refused submit changed nothing and may be
 * skipped quietly, a failed one set the error badge and is worth counting and retrying. A
 * rate-limited one changed nothing either, but the pipeline asked to wait: submit again later,
 * and don't count it as a failure.
 */
class Submit_Result {

	public const SUBMITTED = 'submitted';

	/**
	 * Nothing was sent or changed: not a PDF, another submit holds the claim, or the pipeline
	 * already has a pending job for the file.
	 */
	public const REFUSED = 'refused';

	public const FAILED = 'failed';

	/**
	 * The pipeline answered 429. Nothing was created there, and the file's badge and log were
	 * left alone, so waiting and submitting again is safe.
	 */
	public const RATE_LIMITED = 'rate_limited';

	/**
	 * A 429 body's `limit`: the account already has its maximum number of submissions queued
	 * and working. The pipeline can't know when one finishes, so it names no wait.
	 */
	public const LIMIT_MAX_AT_ONCE = 'max_at_once';

	/**
	 * A 429 body's `limit`: the account used its submission limit per time period. The pipeline
	 * knows when that resets, so it names the wait.
	 */
	public const LIMIT_RATE = 'rate_limit';

	/**
	 * Keys of quota(): the X-RateLimit-Limit, -Remaining and -Reset headers.
	 */
	public const QUOTA_LIMIT     = 'limit';
	public const QUOTA_REMAINING = 'remaining';
	public const QUOTA_RESET     = 'reset';

	/**
	 * The longest wait trusted from the pipeline: a day (the longest limit period) and an hour
	 * of margin. A larger value is a mistake or an attack, and would park a file for good.
	 */
	public const MAX_RETRY_AFTER_SECONDS = 90000;

	/**
	 * The outcome, one of the class constants.
	 *
	 * @var string
	 */
	private $outcome;

	/**
	 * Which limit a RATE_LIMITED result hit (a LIMIT_ constant), or null if unknown.
	 *
	 * @var string|null
	 */
	private $limit_kind;

	/**
	 * Seconds to wait before submitting again, or null if the pipeline named none.
	 *
	 * @var int|null
	 */
	private $retry_after;

	/**
	 * The X-RateLimit-* headers the pipeline sent, keyed by the QUOTA_ constants. A key is missing
	 * when its header was missing or not a whole number.
	 *
	 * @var int[]
	 */
	private $quota = array();

	/**
	 * Use the named constructors.
	 *
	 * @param string $outcome One of the class constants.
	 */
	private function __construct( string $outcome ) {
		$this->outcome = $outcome;
	}

	/**
	 * The pipeline accepted the file.
	 *
	 * @return self
	 */
	public static function submitted(): self {
		return new self( self::SUBMITTED );
	}

	/**
	 * Nothing was sent or changed.
	 *
	 * @return self
	 */
	public static function refused(): self {
		return new self( self::REFUSED );
	}

	/**
	 * The submit was tried and did not work.
	 *
	 * @return self
	 */
	public static function failed(): self {
		return new self( self::FAILED );
	}

	/**
	 * The pipeline refused the submit because of a limit.
	 *
	 * @param string|null $limit_kind  A LIMIT_ constant. Anything else, or null, is stored as null
	 *                                 (a limit the pipeline didn't name, or a proxy's 429).
	 * @param int|null    $retry_after Seconds to wait, or null if the pipeline named none. Kept
	 *                                 only for LIMIT_RATE: the pipeline can't know when a
	 *                                 LIMIT_MAX_AT_ONCE one clears, so for that, or an unknown
	 *                                 limit, it is dropped. Capped at MAX_RETRY_AFTER_SECONDS; a
	 *                                 negative value counts as 0.
	 * @return self
	 */
	public static function rate_limited( ?string $limit_kind, ?int $retry_after ): self {
		$result             = new self( self::RATE_LIMITED );
		$result->limit_kind = in_array( $limit_kind, array( self::LIMIT_RATE, self::LIMIT_MAX_AT_ONCE ), true ) ? $limit_kind : null;

		if ( self::LIMIT_RATE === $result->limit_kind && null !== $retry_after ) {
			$result->retry_after = max( 0, min( $retry_after, self::MAX_RETRY_AFTER_SECONDS ) );
		}

		return $result;
	}

	/**
	 * A copy of this result carrying the pipeline's X-RateLimit-* numbers.
	 *
	 * @param int[] $quota Keyed by the QUOTA_ constants, each optional.
	 * @return self
	 */
	public function with_quota( array $quota ): self {
		$copy        = clone $this;
		$copy->quota = $quota;

		return $copy;
	}

	/**
	 * One of the class constants.
	 *
	 * @return string
	 */
	public function outcome(): string {
		return $this->outcome;
	}

	/**
	 * Whether the pipeline accepted the file.
	 *
	 * @return bool
	 */
	public function is_submitted(): bool {
		return self::SUBMITTED === $this->outcome;
	}

	/**
	 * Whether nothing was sent or changed.
	 *
	 * @return bool
	 */
	public function is_refused(): bool {
		return self::REFUSED === $this->outcome;
	}

	/**
	 * Whether the submit was tried and did not work.
	 *
	 * @return bool
	 */
	public function is_failed(): bool {
		return self::FAILED === $this->outcome;
	}

	/**
	 * Whether the pipeline said to wait and try again.
	 *
	 * @return bool
	 */
	public function is_rate_limited(): bool {
		return self::RATE_LIMITED === $this->outcome;
	}

	/**
	 * Which limit was hit.
	 *
	 * @return string|null A LIMIT_ constant, or null if this wasn't rate limited or the pipeline
	 *                     didn't say.
	 */
	public function limit_kind(): ?string {
		return $this->limit_kind;
	}

	/**
	 * How long the pipeline said to wait.
	 *
	 * @return int|null Seconds, or null if it named none (always so for LIMIT_MAX_AT_ONCE).
	 */
	public function retry_after(): ?int {
		return $this->retry_after;
	}

	/**
	 * The pipeline's X-RateLimit-* numbers, if it sent any.
	 *
	 * @return int[] Keyed by the QUOTA_ constants, each present only if sent.
	 */
	public function quota(): array {
		return $this->quota;
	}
}
