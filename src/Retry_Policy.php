<?php
/**
 * How long to wait before submitting a file again.
 *
 * @package AdaRemediationClient
 */

namespace AdaRemediationClient;

/**
 * The pipeline works through its queue in batches, so a slot only frees up when a batch runs, and
 * a rare very long document can hold one for hours. A submit refused because the account has its
 * maximum number of submissions queued and working is therefore retried a few times at a short
 * interval, then once after a long wait, then given up on.
 *
 * Both waits can be set in wp-config.php, because other sites and other pipelines run on other
 * schedules. The number of short retries is fixed.
 */
class Retry_Policy {

	/**
	 * The wp-config.php constant for the seconds between the short retries.
	 */
	public const INTERVAL_CONSTANT = 'ADA_REMEDIATION_RETRY_INTERVAL_SECONDS';

	/**
	 * The wp-config.php constant for the seconds to wait before the one final try.
	 */
	public const FINAL_INTERVAL_CONSTANT = 'ADA_REMEDIATION_RETRY_FINAL_INTERVAL_SECONDS';

	/**
	 * The pipeline's batch worker runs every 15 to 20 minutes, so 20 guarantees a run happened
	 * between two tries.
	 */
	public const DEFAULT_INTERVAL_SECONDS = 1200;

	/**
	 * Long enough for a very long document (up to 2-3 hours) to finish and free its slot.
	 */
	public const DEFAULT_FINAL_INTERVAL_SECONDS = 7200;

	/**
	 * The longest wait accepted from wp-config.php: a day. A typo with a few extra digits must not
	 * park a file for years or overflow the time it is scheduled for.
	 */
	public const MAX_SECONDS = 86400;

	/**
	 * How many short retries come before the final one. Not configurable.
	 */
	public const SHORT_RETRIES = 4;

	/**
	 * The one retry after the long wait. Retries run from 1 to this number.
	 */
	public const FINAL_RETRY = self::SHORT_RETRIES + 1;

	/**
	 * Seconds between the short retries.
	 *
	 * @var int
	 */
	private $interval;

	/**
	 * Seconds before the final try.
	 *
	 * @var int
	 */
	private $final_interval;

	/**
	 * Takes the waits as arguments so a caller, or a test, needs no global constant.
	 *
	 * @param int $interval_seconds       Seconds between the short retries. At least 1; more than
	 *                                    MAX_SECONDS counts as MAX_SECONDS.
	 * @param int $final_interval_seconds Seconds before the final try. Same limits.
	 * @throws \InvalidArgumentException If either is less than 1.
	 */
	public function __construct( int $interval_seconds, int $final_interval_seconds ) {
		if ( $interval_seconds < 1 || $final_interval_seconds < 1 ) {
			throw new \InvalidArgumentException( 'A retry wait must be at least 1 second.' );
		}

		$this->interval       = min( $interval_seconds, self::MAX_SECONDS );
		$this->final_interval = min( $final_interval_seconds, self::MAX_SECONDS );
	}

	/**
	 * The policy this site is configured for: the wp-config.php constants, or the defaults.
	 *
	 * @return self
	 */
	public static function from_constants(): self {
		return new self(
			self::seconds_from_constant( self::INTERVAL_CONSTANT, self::DEFAULT_INTERVAL_SECONDS ),
			self::seconds_from_constant( self::FINAL_INTERVAL_CONSTANT, self::DEFAULT_FINAL_INTERVAL_SECONDS )
		);
	}

	/**
	 * Seconds to wait before a retry.
	 *
	 * @param int $retry Which retry this is, counting from 1: the 1st to 4th (SHORT_RETRIES) use the
	 *                   short interval, the 5th (FINAL_RETRY) the final interval.
	 * @return int|null Null once the retries are used up: give up.
	 */
	public function delay_for( int $retry ): ?int {
		if ( $retry < 1 || $retry > self::FINAL_RETRY ) {
			return null;
		}

		return $retry <= self::SHORT_RETRIES ? $this->interval : $this->final_interval;
	}

	/**
	 * Seconds between the short retries, for a wait that has no better number of its own.
	 *
	 * @return int
	 */
	public function short_interval_seconds(): int {
		return $this->interval;
	}

	/**
	 * A wp-config.php constant as a positive number of seconds. A typo there must not switch the
	 * plugin off or make it retry in a tight loop, so anything but a positive whole number falls
	 * back to the default.
	 *
	 * @param string $name     The constant.
	 * @param int    $fallback Used if it is not defined or not usable.
	 * @return int
	 */
	private static function seconds_from_constant( string $name, int $fallback ): int {
		$seconds = defined( $name ) ? Whole_Number::from( constant( $name ) ) : null;

		return null !== $seconds && $seconds > 0 ? $seconds : $fallback;
	}
}
