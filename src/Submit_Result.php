<?php
/**
 * What happened to one submit.
 *
 * @package AdaRemediationClient
 */

namespace AdaRemediationClient;

/**
 * Lets a caller tell a refusal from a failure: a refused submit changed nothing and may be
 * skipped quietly, a failed one set the error badge and is worth counting and retrying.
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
	 * The outcome, one of the class constants.
	 *
	 * @var string
	 */
	private $outcome;

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
}
