<?php
/**
 * Flags bare badge, pipeline-vocabulary and post-meta-key strings.
 *
 * @package AdaRemediationClient
 */

namespace AdaRemediation\Sniffs\Strings;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * A string that means something to this plugin (a badge, a pipeline status, step or severity,
 * a `_ada_remediation_*` meta key) is spelled once, as a constant, and used by name. A typo in
 * a bare copy fails silently: the badge falls back to grey, or a lookup quietly returns nothing.
 *
 * Declaring the constant is the one place the literal is allowed.
 */
class NoMagicStringsSniff implements Sniff {

	/**
	 * Prefix of every post meta key this plugin owns. Override in a ruleset with
	 * `<property name="metaKeyPrefix" value="..."/>`.
	 *
	 * @var string
	 */
	public $metaKeyPrefix = '_ada_remediation_';

	/**
	 * Vocabulary that must come from a constant. Not 'error': it is also a generic word
	 * (WP_Error codes), so banning it would flag unrelated code. Override in a ruleset with a
	 * comma-separated `<property name="vocabulary" type="array" value="a,b"/>`.
	 *
	 * @var string[]
	 */
	public $vocabulary = array(
		'queued',
		'pending',
		'dark-green',
		'light-green',
		'yellow',
		'red',
		'precheck',
		'postcheck',
		'compliant',
		'noncompliant',
		'critical',
		'major',
		'minor',
		'unclassified',
		'attachment',
	);

	/**
	 * Tokens this sniff listens for.
	 *
	 * @return array
	 */
	public function register() {
		return array( T_CONSTANT_ENCAPSED_STRING );
	}

	/**
	 * Reports a bare literal that should be a constant.
	 *
	 * @param File $phpcs_file The file being checked.
	 * @param int  $stack_ptr  The position of the string token.
	 */
	public function process( File $phpcs_file, $stack_ptr ) {
		$tokens = $phpcs_file->getTokens();
		$value  = trim( $tokens[ $stack_ptr ]['content'], '\'"' );

		if ( $this->is_constant_declaration( $phpcs_file, $stack_ptr ) ) {
			return;
		}

		if ( '' !== $this->metaKeyPrefix && 0 === strpos( $value, $this->metaKeyPrefix ) ) {
			$phpcs_file->addError(
				'Post meta key "%s" must be used through Submission_State_Service, not spelled out.',
				$stack_ptr,
				'MetaKey',
				array( $value )
			);

			return;
		}

		if ( in_array( $value, $this->vocabulary, true ) ) {
			$phpcs_file->addError(
				'"%s" is a badge or pipeline value: use its constant instead of a bare string.',
				$stack_ptr,
				'Vocabulary',
				array( $value )
			);
		}
	}

	/**
	 * Whether the string is the value in `const NAME = '...'`, the one allowed spelling.
	 *
	 * @param File $phpcs_file The file being checked.
	 * @param int  $stack_ptr  The position of the string token.
	 * @return bool
	 */
	private function is_constant_declaration( File $phpcs_file, $stack_ptr ) {
		$tokens = $phpcs_file->getTokens();

		$equals = $phpcs_file->findPrevious( T_WHITESPACE, $stack_ptr - 1, null, true );

		if ( false === $equals || T_EQUAL !== $tokens[ $equals ]['code'] ) {
			return false;
		}

		$name = $phpcs_file->findPrevious( T_WHITESPACE, $equals - 1, null, true );

		if ( false === $name || T_STRING !== $tokens[ $name ]['code'] ) {
			return false;
		}

		$keyword = $phpcs_file->findPrevious( T_WHITESPACE, $name - 1, null, true );

		return false !== $keyword && T_CONST === $tokens[ $keyword ]['code'];
	}
}
