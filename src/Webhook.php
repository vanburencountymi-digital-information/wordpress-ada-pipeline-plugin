<?php
/**
 * Receives and processes the pipeline's signed result callback.
 *
 * @package AdaRemediationClient
 */

namespace AdaRemediationClient;

/**
 * Receives the pipeline's signed result callback. Registered on rest_api_init by
 * Plugin::boot(). authorize()/handle() take plain strings/arrays.
 * The two small closures in register_routes() are the only place a real WP_REST_Request
 * or WP_Error is touched, and are thin enough (delegate, then convert the outcome) not to
 * need their own tests.
 */
class Webhook {

	public const ROUTE_NAMESPACE  = 'ada-remediation/v1';
	public const ROUTE            = '/callback';
	public const SIGNATURE_HEADER = 'X-Webhook-Signature';
	public const SIGNATURE_PREFIX = 'sha256=';

	/**
	 * The action adapters hook to receive a result. Public API: keep the value.
	 */
	public const RESULT_HOOK = 'ada_remediation_result';

	/**
	 * One-off WP-Cron event that fires RESULT_HOOK, so adapters' slow work (a file
	 * swap can take 30s+) runs after the webhook has answered instead of timing the pipeline out.
	 */
	public const APPLY_RESULT_HOOK = 'ada_remediation_apply_result';

	/**
	 * Failed signature checks one address may make per window before it is refused outright.
	 * Only failures count, so the pipeline's own valid callbacks are never throttled.
	 */
	private const FAILURE_LIMIT          = 20;
	private const FAILURE_WINDOW_SECONDS = 600;
	private const FAILURE_KEY_PREFIX     = 'ada_remediation_webhook_failures_';

	private const STEP_PRECHECK  = 'precheck';
	private const STEP_POSTCHECK = 'postcheck';

	private const STATUS_COMPLIANT    = 'compliant';
	private const STATUS_NONCOMPLIANT = 'noncompliant';

	private const SEVERITY_CRITICAL     = 'critical';
	private const SEVERITY_MAJOR        = 'major';
	private const SEVERITY_MINOR        = 'minor';
	private const SEVERITY_UNCLASSIFIED = 'unclassified';

	/**
	 * Registers the callback REST route.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => static function ( \WP_REST_Request $request ) {
					$outcome = self::handle( (string) $request->get_body(), (int) $request->get_param( 'attachment_id' ) );

					if ( ! $outcome['ok'] ) {
						return new \WP_Error( $outcome['code'], $outcome['message'], array( 'status' => $outcome['status'] ) );
					}

					return $outcome['body'];
				},
				'permission_callback' => static function ( \WP_REST_Request $request ) {
					$outcome = self::authorize( (string) $request->get_body(), $request->get_header( self::SIGNATURE_HEADER ), self::client_ip() );

					if ( ! $outcome['ok'] ) {
						return new \WP_Error( $outcome['code'], $outcome['message'], array( 'status' => $outcome['status'] ) );
					}

					return true;
				},
			)
		);
	}

	/**
	 * Checks the request's signature, and refuses an address that keeps failing it.
	 * The failure count is a transient and is not incremented atomically, so a burst can slip a
	 * few extra attempts past the limit. Behind a shared proxy, addresses that share one IP also
	 * share one count; a valid callback from a locked-out address is refused until the window passes.
	 *
	 * @param string      $raw_body         The raw request body.
	 * @param string|null $signature_header The X-Webhook-Signature header value.
	 * @param string      $client_ip        The caller's address; empty means don't throttle.
	 * @return array{ok: bool, status?: int, code?: string, message?: string}
	 */
	public static function authorize( string $raw_body, ?string $signature_header, string $client_ip ): array {
		$key = '' === $client_ip ? '' : self::FAILURE_KEY_PREFIX . md5( $client_ip );

		if ( '' !== $key && (int) get_transient( $key ) >= self::FAILURE_LIMIT ) {
			return self::error_outcome( 429, 'ada_remediation_rate_limited', 'Too many failed requests.' );
		}

		if ( self::verify_signature( $raw_body, $signature_header ) ) {
			return array( 'ok' => true );
		}

		if ( '' !== $key ) {
			set_transient( $key, (int) get_transient( $key ) + 1, self::FAILURE_WINDOW_SECONDS );
		}

		return self::error_outcome( 401, 'ada_remediation_invalid_signature', 'Invalid signature.' );
	}

	/**
	 * The caller's address. Only REMOTE_ADDR: forwarded headers are caller-supplied, so trusting
	 * them would let an attacker choose which address gets counted.
	 */
	private static function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * HMAC over the raw body, keyed by ADA_REMEDIATION_WEBHOOK_SECRET — replicates
	 * dice-document-pipeline-api's remediation/webhook_client.py signing exactly.
	 *
	 * @param string      $raw_body         The raw request body the signature was computed over.
	 * @param string|null $signature_header The request's X-Webhook-Signature header value.
	 */
	public static function verify_signature( string $raw_body, ?string $signature_header ): bool {
		$signature_header = (string) $signature_header;

		// Belt and braces: Plugin::is_configured() already refuses an empty secret, but an empty
		// HMAC key must never be able to validate anything.
		if ( '' === (string) ADA_REMEDIATION_WEBHOOK_SECRET ) {
			return false;
		}

		if ( strncmp( $signature_header, self::SIGNATURE_PREFIX, strlen( self::SIGNATURE_PREFIX ) ) !== 0 ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $raw_body, ADA_REMEDIATION_WEBHOOK_SECRET );
		$provided = substr( $signature_header, strlen( self::SIGNATURE_PREFIX ) );

		return hash_equals( $expected, $provided );
	}

	/**
	 * Verifies and processes a webhook payload, updating the matching attachment.
	 *
	 * @param string $raw_body           The raw, signature-verified request body.
	 * @param int    $attachment_id_hint The attachment_id Client::submit_attachment() embedded in
	 *                                   this submission's callback_url (DIC-2040). Trusted only
	 *                                   after confirming it actually points at this remediation_id
	 *                                   — the query string isn't covered by the HMAC signature, so
	 *                                   a stale/mismatched hint falls back to the pre-existing
	 *                                   search instead of being used blindly.
	 * @return array{ok: bool, status?: int, code?: string, message?: string, body?: array}
	 */
	public static function handle( string $raw_body, int $attachment_id_hint = 0 ): array {
		$data = json_decode( $raw_body, true );

		if ( ! is_array( $data ) || empty( $data['remediation_id'] ) ) {
			return self::error_outcome( 400, 'ada_remediation_invalid_payload', 'Malformed webhook payload.' );
		}

		$attachment_id = self::resolve_attachment_id(
			$attachment_id_hint,
			(string) $data['remediation_id'],
			isset( $data['document_id'] ) ? (string) $data['document_id'] : null
		);

		if ( ! $attachment_id ) {
			// Otherwise only Sentry would know this result was dropped.
			Remediation_Log::record_warning(
				$attachment_id_hint,
				(string) $data['remediation_id'],
				(string) ( $data['pipeline_version'] ?? '' ),
				'Webhook result could not be matched to an attachment and was not applied.'
			);

			return self::error_outcome( 404, 'ada_remediation_unknown_remediation', 'No attachment found for this remediation_id.' );
		}

		// A repeated delivery (a retry, or a replayed signed body) must not re-run the result
		// hooks: an adapter's file swap would otherwise create another attachment every time.
		if ( Submission_State_Service::applied_remediation_id( $attachment_id ) === (string) $data['remediation_id'] ) {
			return array(
				'ok'   => true,
				'body' => array(
					'status'    => 'ok',
					'duplicate' => true,
				),
			);
		}

		$badge = self::derive_badge( $data );

		Submission_State_Service::mark_result( $attachment_id, $badge );
		Submission_State_Service::mark_checked( $attachment_id, current_time( 'mysql', true ) );

		Remediation_Log::record(
			$attachment_id,
			(string) $data['remediation_id'],
			(string) ( $data['document_id'] ?? '' ),
			(string) ( $data['pipeline_version'] ?? '' ),
			$badge,
			self::step_json( $data, self::STEP_PRECHECK ),
			self::step_json( $data, self::STEP_POSTCHECK )
		);

		$result = array(
			'status'               => $data['status'] ?? '',
			'badge'                => $badge,
			'pipeline_version'     => $data['pipeline_version'] ?? '',
			'verification_results' => $data['verification_results'] ?? array(),
			'download_url'         => $data['download_url'] ?? null,
		);

		// Marked before the hooks run, so the side effects happen at most once per job.
		Submission_State_Service::mark_applied( $attachment_id, (string) $data['remediation_id'] );

		self::queue_result_hooks( $attachment_id, $result );

		return array(
			'ok'   => true,
			'body' => array( 'status' => 'ok' ),
		);
	}

	/**
	 * Fires RESULT_HOOK, the adapters' hook. Called by WP-Cron from the event queue_result_hooks()
	 * schedules. The job is already marked applied, so nothing retries a listener that fails:
	 * the failure is written to the remediation log so someone can find it.
	 *
	 * @param int   $attachment_id The attachment the result belongs to.
	 * @param array $result        The webhook result array (see handle()).
	 */
	public static function run_result_hooks( int $attachment_id, array $result ): void {
		try {
			do_action( self::RESULT_HOOK, $attachment_id, $result );
		} catch ( \Throwable $error ) {
			Remediation_Log::record_warning(
				$attachment_id,
				Submission_State_Service::remediation_id( $attachment_id ),
				(string) ( $result['pipeline_version'] ?? '' ),
				'A result listener failed and the result was not fully applied: ' . $error->getMessage()
			);
		}
	}

	/**
	 * Hands the result to adapters after this request has answered the pipeline, which gives up
	 * after about 10s. If the event can't be scheduled the hooks run now, slowly, rather than never.
	 *
	 * @param int   $attachment_id The attachment the result belongs to.
	 * @param array $result        The webhook result array (see handle()).
	 */
	private static function queue_result_hooks( int $attachment_id, array $result ): void {
		if ( Plugin::schedule_once( self::APPLY_RESULT_HOOK, array( $attachment_id, $result ) ) ) {
			spawn_cron();

			return;
		}

		self::run_result_hooks( $attachment_id, $result );
	}

	/**
	 * Builds a failure outcome array.
	 *
	 * @param int    $status  The HTTP status to respond with.
	 * @param string $code    A machine-readable error code.
	 * @param string $message A human-readable error message.
	 */
	private static function error_outcome( int $status, string $code, string $message ): array {
		return array(
			'ok'      => false,
			'status'  => $status,
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * Resolves which attachment a webhook result belongs to.
	 *
	 * A file that already carries the remediation_id always wins over the hint: the hint isn't
	 * covered by the HMAC, so a replayed signed body with some other file's id in it must land on
	 * the file that already has this job (and be skipped as a duplicate), never be salvaged onto
	 * a second one.
	 *
	 * @param int         $attachment_id_hint See handle()'s own docblock.
	 * @param string      $remediation_id     The payload's remediation_id.
	 * @param string|null $content_hash       The payload's document_id, stored if the hint is salvaged.
	 * @return int The attachment id the result belongs to, or 0 if none matches.
	 */
	private static function resolve_attachment_id( int $attachment_id_hint, string $remediation_id, ?string $content_hash = null ): int {
		if ( $attachment_id_hint > 0 && Submission_State_Service::remediation_id( $attachment_id_hint ) === $remediation_id ) {
			return $attachment_id_hint;
		}

		$existing = Submission_State_Service::find_attachment_id( $remediation_id );

		if ( $existing > 0 ) {
			return $existing;
		}

		// Nobody has this job. A hinted file whose submit response was lost (DIC-2184) may adopt it.
		// A hint carrying a *different* id is a newer/older job and is never salvaged, so an older
		// result can't overwrite a newer one. The hint isn't signed, so this is limited to files
		// already marked failed; with the lookup above, a replayed body can't reach a second file.
		if ( $attachment_id_hint > 0 && Submission_State_Service::is_failed_submission( $attachment_id_hint ) ) {
			// So normal result handling and "Fetch Most Recent Remediation Report" work afterwards.
			Submission_State_Service::record_submission( $attachment_id_hint, $remediation_id, $content_hash );

			return $attachment_id_hint;
		}

		return 0;
	}

	/**
	 * The pipeline's status is unambiguous as of ADR 0024 (dice-document-pipeline-api) —
	 * COMPLIANT (postcheck passed, or precheck was already compliant) and NONCOMPLIANT
	 * (postcheck ran, real failed_rules exist) are now distinct from ERROR (a crash, or
	 * postcheck's own adapter failing via PostCheckUnavailable — no verdict at all) and
	 * SKIPPED (postcheck disabled — also no verdict). No need to infer anything from
	 * verification_results' shape; branch on status directly. SKIPPED collapses into the
	 * same 'error' badge as ERROR for now — PLANNING's badge scheme has no slot for
	 * "deliberately not checked," and both mean "nothing to show" from a badge's perspective.
	 *
	 * @param array $data The decoded webhook payload.
	 */
	private static function derive_badge( array $data ): string {
		$status = $data['status'] ?? '';

		if ( self::STATUS_NONCOMPLIANT === $status ) {
			$postcheck = self::find_step( $data, self::STEP_POSTCHECK );

			return self::badge_for_severity( self::worst_severity( $postcheck['failed_rules'] ?? array() ) );
		}

		if ( self::STATUS_COMPLIANT === $status ) {
			return Submission_State_Service::BADGE_DARK_GREEN;
		}

		return Submission_State_Service::BADGE_ERROR;
	}

	/**
	 * The most severe severity present among a postcheck's failed rules.
	 *
	 * @param array $failed_rules The postcheck step's failed_rules array.
	 */
	private static function worst_severity( array $failed_rules ): ?string {
		$present = array_column( $failed_rules, 'severity' );

		foreach ( array( self::SEVERITY_CRITICAL, self::SEVERITY_MAJOR, self::SEVERITY_MINOR, self::SEVERITY_UNCLASSIFIED ) as $severity ) {
			if ( in_array( $severity, $present, true ) ) {
				return $severity;
			}
		}

		return null;
	}

	/**
	 * Maps a failed-rule severity to a badge color.
	 *
	 * @param string|null $severity The worst severity present, or null if none.
	 */
	private static function badge_for_severity( ?string $severity ): string {
		switch ( $severity ) {
			case self::SEVERITY_MINOR:
				return Submission_State_Service::BADGE_LIGHT_GREEN;
			case self::SEVERITY_MAJOR:
				return Submission_State_Service::BADGE_YELLOW;
			case self::SEVERITY_CRITICAL:
			case self::SEVERITY_UNCLASSIFIED:
				return Submission_State_Service::BADGE_RED;
			default:
				return Submission_State_Service::BADGE_DARK_GREEN;
		}
	}

	/**
	 * Finds one named step's entry within a payload's verification_results.
	 *
	 * @param array  $data The decoded webhook payload.
	 * @param string $step The step name to find, e.g. 'precheck' or 'postcheck'.
	 */
	private static function find_step( array $data, string $step ): ?array {
		foreach ( $data['verification_results'] ?? array() as $result ) {
			if ( ( $result['step'] ?? '' ) === $step ) {
				return $result;
			}
		}

		return null;
	}

	/**
	 * JSON-encodes one named verification step, for Remediation_Log::record().
	 *
	 * @param array  $data The decoded webhook payload.
	 * @param string $step The step name to encode, e.g. 'precheck' or 'postcheck'.
	 */
	private static function step_json( array $data, string $step ): ?string {
		$result = self::find_step( $data, $step );

		return null === $result ? null : wp_json_encode( $result );
	}
}
