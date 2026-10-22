<?php
/**
 * Submits attachments to the remediation pipeline.
 *
 * @package AdaRemediationClient
 */

namespace AdaRemediationClient;

/**
 * Adapters can extend the plugin instead of using its defaults — for example,
 * calling submit_attachment() directly from their own upload hook, suppressing
 * the default add_attachment trigger, and adding their own logic (like file
 * versioning or a richer badge UI).
 */
class Client {

	/**
	 * $context key: true re-runs past the pipeline's content-hash dedupe.
	 */
	public const CONTEXT_FORCE = 'force';

	/**
	 * How many arguments the WP-Cron callback submit_scheduled() takes. Its hook must be registered
	 * to accept exactly this many: WP-Cron hands a callback only as many as it accepts, and the
	 * ones with defaults (the retry number and the due time) would arrive as 0, running a stale
	 * retry as a first try. A test checks this against the method.
	 */
	public const SCHEDULED_SUBMIT_ARGS = 4;

	private const SUBMIT_PATH = '/api/submit-document/';

	private const HTTP_TOO_MANY_REQUESTS = 429;

	/**
	 * What the pipeline's resets_at must look like: an ISO 8601 date and time with a UTC offset,
	 * with or without fractional seconds (2026-10-21T17:00:00Z, 2026-10-21T17:00:00.123456+00:00).
	 * Stricter than DateTimeImmutable, which would also read "tomorrow" or a bare year as a time.
	 * It ends in \z, not $, because $ would also accept a trailing newline.
	 */
	private const ISO_8601_WITH_OFFSET = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})\z/';

	/**
	 * The response headers that report the account's limits, keyed by Submit_Result's QUOTA_ keys.
	 */
	private const QUOTA_HEADERS = array(
		Submit_Result::QUOTA_LIMIT     => 'x-ratelimit-limit',
		Submit_Result::QUOTA_REMAINING => 'x-ratelimit-remaining',
		Submit_Result::QUOTA_RESET     => 'x-ratelimit-reset',
	);

	/**
	 * Generous on purpose: submits run on a scheduled WP-Cron hit, not inside anyone's
	 * upload request, and this covers a cold start of the scale-to-zero API. Submission_State_Service's
	 * claim must outlast it.
	 */
	private const SUBMIT_TIMEOUT_SECONDS = 120;

	/**
	 * Submits an attachment's PDF to the remediation pipeline.
	 *
	 * @param int   $attachment_id The attachment to submit.
	 * @param array $context       Optional. self::CONTEXT_FORCE => true bypasses the pipeline's
	 *                             content-hash dedupe. It does not bypass the in-flight check.
	 * @return bool False if the attachment is not a PDF, a submission is already in flight
	 *              (nothing is changed for either), the pipeline said to wait (a limit), or the
	 *              submission failed. Use submit_with_result() to tell those apart.
	 */
	public static function submit_attachment( int $attachment_id, array $context = array() ): bool {
		return self::submit_with_result( $attachment_id, $context )->is_submitted();
	}

	/**
	 * Submits an attachment's PDF to the remediation pipeline, saying what happened.
	 *
	 * @param int           $attachment_id The attachment to submit.
	 * @param array         $context       See submit_attachment().
	 * @param callable|null $still_wanted  Optional. Returns false, once the claim is held, if the
	 *                                     submit is no longer wanted; see run_exclusive().
	 * @return Submit_Result Refused (nothing changed), rate limited (the pipeline said to wait;
	 *                       nothing changed), failed, or submitted.
	 */
	public static function submit_with_result( int $attachment_id, array $context = array(), ?callable $still_wanted = null ): Submit_Result {
		if ( ! Plugin::is_pdf( $attachment_id ) ) {
			return Submit_Result::refused();
		}

		return Submission_State_Service::run_exclusive(
			$attachment_id,
			static function () use ( $attachment_id, $context ): Submit_Result {
				return self::send( $attachment_id, $context );
			},
			$still_wanted
		);
	}

	/**
	 * The WP-Cron callback for a scheduled submit: submits, and if the pipeline said to wait,
	 * schedules the next try.
	 *
	 * A retry event carries its retry number and the time it is due. It runs only if the file is
	 * still waiting for exactly that event (the retrying badge, stamped with that time). If the
	 * file was submitted another way meanwhile, or a newer retry replaced this one, it does nothing,
	 * so there is one retry chain per file however many events were scheduled. The state decides,
	 * not timing; WP-Cron's own de-duplication only merges identical events close together.
	 *
	 * A first try runs only if the file is still queued (or was never marked), so a late event
	 * can't resubmit a file that has a result, failed, or is waiting for a retry of its own.
	 *
	 * The original $context goes with every retry: a limit means the pipeline created nothing, so
	 * a forced submit that was limited should still be forced when it goes through.
	 *
	 * @param int   $attachment_id The attachment to submit.
	 * @param array $context       See submit_attachment().
	 * @param int   $retry         Which retry this is, counting from 1; 0 for the first try.
	 * @param int   $due_at        Unix time this retry was scheduled for; 0 for the first try.
	 * @return void
	 */
	public static function submit_scheduled( int $attachment_id, array $context = array(), int $retry = 0, int $due_at = 0 ): void {
		// Checked once the claim is held, so two events can't both pass it and both submit.
		$still_wanted = static function () use ( $attachment_id, $retry, $due_at ): bool {
			return $retry > 0
				? Submission_State_Service::is_retry_scheduled_for( $attachment_id, $due_at )
				: Submission_State_Service::is_awaiting_first_try( $attachment_id );
		};

		$result = self::submit_with_result( $attachment_id, $context, $still_wanted );

		if ( $result->is_rate_limited() ) {
			self::retry_later( $attachment_id, $context, $retry + 1, $result );
		}
	}

	/**
	 * Schedules the next try of a rate-limited submit, or marks the file failed once Retry_Policy
	 * has run out of retries.
	 *
	 * @param int           $attachment_id The attachment.
	 * @param array         $context       The context to submit with again.
	 * @param int           $retry         The retry being scheduled, counting from 1.
	 * @param Submit_Result $result        The rate-limited result.
	 * @return void
	 */
	private static function retry_later( int $attachment_id, array $context, int $retry, Submit_Result $result ): void {
		$delay = Retry_Policy::from_constants()->delay_after( $result, $retry );

		if ( null === $delay ) {
			self::fail_and_mark( $attachment_id );

			return;
		}

		$next_try_at = time() + $delay;

		// Stamped before the event exists, so the file never looks abandoned while it waits, and so
		// the event (which carries this time) finds it is the one the file is waiting for. False
		// means the pipeline took a job for it meanwhile: nothing is left to retry, and it is not a failure.
		if ( ! Submission_State_Service::mark_retrying( $attachment_id, $next_try_at ) ) {
			return;
		}

		// A file left retrying with no event would look in flight for a while and then never be sent.
		if ( ! Plugin::schedule_once( Plugin::SUBMIT_ATTACHMENT_HOOK, array( $attachment_id, $context, $retry, $next_try_at ), $next_try_at ) ) {
			self::fail_and_mark( $attachment_id );
		}
	}

	/**
	 * Marks the submission failed and says so.
	 *
	 * @param int $attachment_id The attachment whose submission failed.
	 * @return Submit_Result Always failed.
	 */
	private static function fail_and_mark( int $attachment_id ): Submit_Result {
		self::mark_submission_failed( $attachment_id );

		return Submit_Result::failed();
	}

	/**
	 * Reads the file and posts it to the pipeline. Callers hold the claim.
	 *
	 * @param int   $attachment_id The attachment to submit.
	 * @param array $context       See submit_attachment().
	 * @return Submit_Result Submitted if the pipeline accepted it, rate limited if it said to wait,
	 *                       otherwise failed.
	 */
	private static function send( int $attachment_id, array $context ): Submit_Result {
		$file_contents = self::read_attachment_contents( $attachment_id );

		if ( false === $file_contents || null === $file_contents ) {
			return self::fail_and_mark( $attachment_id );
		}

		$filename = basename( (string) get_attached_file( $attachment_id ) );
		$filename = $filename ? $filename : "attachment-{$attachment_id}.pdf";
		$boundary = uniqid( 'ada-remediation-', true );

		$fields = array(
			// Includes the attachment_id so every submission gets its own callback_url as
			// the pipeline dedupes (remediation, callback_url) pairs. See Webhook::handle()'s
			// $attachment_id_hint, which this is read back out as.
			'callback_url' => add_query_arg( 'attachment_id', $attachment_id, rest_url( 'ada-remediation/v1/callback' ) ),
			'force'        => ! empty( $context[ self::CONTEXT_FORCE ] ) ? 'true' : 'false',
		);

		$response = wp_remote_post(
			rtrim( ADA_REMEDIATION_API_BASE_URL, '/' ) . self::SUBMIT_PATH,
			array(
				'headers' => array(
					'Authorization' => 'Token ' . ADA_REMEDIATION_API_TOKEN,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => self::build_multipart_body( $boundary, $fields, $filename, $file_contents ),
				'timeout' => self::SUBMIT_TIMEOUT_SECONDS,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::fail_and_mark( $attachment_id );
		}

		// Quota headers ride on every response, success or limit, so they are attached here and not
		// in each branch of record_outcome(), where a new branch could forget them.
		return self::record_outcome( $attachment_id, $response )->with_quota( self::read_quota( $response ) );
	}

	/**
	 * Says what the pipeline's answer means, and records it: the error badge and log for a failure,
	 * the remediation id for an accepted submission, and nothing at all for a limit.
	 *
	 * @param int   $attachment_id The attachment that was submitted.
	 * @param array $response      The pipeline's response (not a WP_Error).
	 * @return Submit_Result Submitted, rate limited, or failed.
	 */
	private static function record_outcome( int $attachment_id, array $response ): Submit_Result {
		$code = wp_remote_retrieve_response_code( $response );

		// A limit is not an error: the badge and log stay as they were, so a live upload that hits
		// one never shows red. The pipeline created nothing, so submitting again later is safe.
		if ( self::HTTP_TOO_MANY_REQUESTS === $code ) {
			return self::read_rate_limited( $response );
		}

		if ( ! self::is_success_status( $code ) ) {
			return self::fail_and_mark( $attachment_id );
		}

		if ( ! self::store_submission_result( $attachment_id, wp_remote_retrieve_body( $response ) ) ) {
			return self::fail_and_mark( $attachment_id );
		}

		return Submit_Result::submitted();
	}

	/**
	 * Turns a 429 response into a result. The pipeline's body says which limit was hit; a 429
	 * with no readable body (for example from a proxy in front of it) still counts as limited,
	 * with no known limit.
	 *
	 * @param array $response The 429 response.
	 * @return Submit_Result Rate limited. Submit_Result decides which limits are known and keeps a
	 *                       wait only for a time-period limit.
	 */
	private static function read_rate_limited( array $response ): Submit_Result {
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$body = is_array( $body ) ? $body : array();
		$kind = is_string( $body['limit'] ?? null ) ? $body['limit'] : null;

		return Submit_Result::rate_limited( $kind, self::read_retry_after( $response, $body ) );
	}

	/**
	 * Seconds until a time-period limit resets, from the first of: the Retry-After header (whole
	 * seconds; an HTTP date is ignored), the body's retry_after, the body's resets_at.
	 *
	 * The body's resets_at must be an ISO 8601 date with a UTC offset (see ISO_8601_WITH_OFFSET);
	 * anything else counts as no wait, so a vague value can't produce a wrong one.
	 *
	 * @param array $response The 429 response.
	 * @param array $body     Its decoded JSON body.
	 * @return int|null Null if none of them is usable. Negative if resets_at has already passed;
	 *                  Submit_Result::rate_limited() clamps that to no wait.
	 */
	private static function read_retry_after( array $response, array $body ): ?int {
		$seconds = Whole_Number::from( wp_remote_retrieve_header( $response, 'retry-after' ) );

		if ( null === $seconds ) {
			$seconds = Whole_Number::from( $body['retry_after'] ?? null );
		}

		if ( null !== $seconds ) {
			return $seconds;
		}

		$resets_at = $body['resets_at'] ?? null;

		if ( ! is_string( $resets_at ) || 1 !== preg_match( self::ISO_8601_WITH_OFFSET, $resets_at ) ) {
			return null;
		}

		try {
			$timestamp = ( new \DateTimeImmutable( $resets_at ) )->getTimestamp();
		} catch ( \Exception $e ) {
			// Right shape but not a real date, such as month 13.
			return null;
		}

		// Negative if the date has passed; Submit_Result::rate_limited() turns that into no wait.
		return $timestamp - time();
	}

	/**
	 * The X-RateLimit-Limit, -Remaining and -Reset headers, which the pipeline sends on every
	 * response so a caller can slow down before it hits a limit.
	 *
	 * @param array $response The pipeline's response.
	 * @return int[] Keyed by Submit_Result's QUOTA_ constants; a key is left out if its header is
	 *               missing or not a whole number.
	 */
	private static function read_quota( array $response ): array {
		$quota = array();

		foreach ( self::QUOTA_HEADERS as $key => $header ) {
			$value = Whole_Number::from( wp_remote_retrieve_header( $response, $header ) );

			if ( null !== $value ) {
				$quota[ $key ] = $value;
			}
		}

		return $quota;
	}

	/**
	 * GETs a pipeline URL (e.g. a webhook payload's download_url), authenticated as this
	 * site's ServiceAccount — the pipeline's download endpoint rejects anonymous requests.
	 *
	 * Refuses any URL that isn't on the configured pipeline's own origin. The URL comes from a
	 * webhook payload, and a forged or compromised one must not be able to make this site
	 * request an internal address (SSRF) or hand the token to another host. Everything this
	 * plugin and its adapters fetch with it is a pipeline URL anyway.
	 *
	 * @param string $url  The pipeline URL to fetch.
	 * @param array  $args Optional extra wp_remote_get() arguments.
	 * @return array|\WP_Error
	 */
	public static function authenticated_get( string $url, array $args = array() ) {
		if ( ! self::is_pipeline_origin( $url ) ) {
			return new \WP_Error( 'ada_remediation_foreign_url', 'Refusing to fetch a URL that is not on the remediation pipeline.' );
		}

		$args['headers']['Authorization'] = 'Token ' . ADA_REMEDIATION_API_TOKEN;

		// Never follow a redirect: it could lead off the pipeline's origin, carrying the token with it.
		$args['redirection'] = 0;

		return wp_remote_get( $url, $args );
	}

	/**
	 * Downloads a remediated file to a temp file, for adapters that sideload it as a new
	 * attachment. The caller owns (and must unlink) the returned file.
	 *
	 * @param string $url The remediated file's download URL.
	 * @return string|\WP_Error The temp file path.
	 */
	public static function download_remediated_file( string $url ) {
		// Checked before touching the filesystem, so a refused URL leaves no temp file behind.
		// authenticated_get() enforces the same rule, but only after the temp file exists.
		if ( ! self::is_pipeline_origin( $url ) ) {
			return new \WP_Error( 'ada_remediation_foreign_url', 'Refusing to download from a URL that is not on the remediation pipeline.' );
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp_file = wp_tempnam( $url );

		if ( ! $tmp_file ) {
			return new \WP_Error( 'ada_remediation_tempnam_failed', 'Could not create a temporary file.' );
		}

		$response = self::authenticated_get(
			$url,
			array(
				'stream'   => true,
				'filename' => $tmp_file,
				'timeout'  => 60,
			)
		);

		if ( is_wp_error( $response ) || ! self::is_success_status( wp_remote_retrieve_response_code( $response ) ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- our own temp file, never an upload.
			unlink( $tmp_file );

			return is_wp_error( $response )
				? $response
				: new \WP_Error( 'ada_remediation_download_failed', 'Download failed with HTTP ' . wp_remote_retrieve_response_code( $response ) . '.' );
		}

		return $tmp_file;
	}

	/**
	 * Whether a URL shares scheme, host and port with ADA_REMEDIATION_API_BASE_URL.
	 *
	 * @param string $url The URL to check.
	 * @return bool
	 */
	private static function is_pipeline_origin( string $url ): bool {
		return self::origin_of( $url ) === self::origin_of( (string) ADA_REMEDIATION_API_BASE_URL );
	}

	/**
	 * A URL's scheme://host:port, lowercased ('' if it has none).
	 *
	 * @param string $url The URL to reduce.
	 * @return string
	 */
	private static function origin_of( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$port = $parts['port'] ?? ( 'https' === strtolower( $parts['scheme'] ) ? 443 : 80 );

		return strtolower( $parts['scheme'] . '://' . $parts['host'] . ':' . $port );
	}

	/**
	 * A failed submission (unreadable file, or the pipeline rejecting the request) used
	 * to leave no trace: no badge, no log row, nothing to show it ever happened. There's
	 * no remediation_id/content_hash/pipeline_version to store at either failure point,
	 * so the log row leaves those empty/null — the table's own column defaults already
	 * support that.
	 *
	 * @param int $attachment_id The attachment whose submission failed.
	 * @return void
	 */
	public static function mark_submission_failed( int $attachment_id ): void {
		Submission_State_Service::mark_error( $attachment_id );

		Remediation_Log::record( $attachment_id, '', '', '', Submission_State_Service::BADGE_ERROR, null, null );
	}

	/**
	 * The submit-document response body is the pipeline's RemediationSerializer —
	 * capturing `id` here (as _ada_remediation_id) is the only way an incoming webhook
	 * (DIC-1900), which carries no WordPress-specific identifier at all, can later be
	 * traced back to this attachment.
	 *
	 * @param int    $attachment_id The attachment that was just submitted.
	 * @param string $response_body The submit-document endpoint's raw JSON response body.
	 * @return bool False if the body carries no remediation id. Nothing is stored then: a file
	 *              marked pending with no id could never match a webhook, and the pending
	 *              badge would block every retry until it went stale.
	 */
	private static function store_submission_result( int $attachment_id, string $response_body ): bool {
		$data = json_decode( $response_body, true );

		if ( ! is_array( $data ) || ! isset( $data['id'] ) ) {
			return false;
		}

		Submission_State_Service::mark_submitted(
			$attachment_id,
			(string) $data['id'],
			isset( $data['document_id'] ) ? (string) $data['document_id'] : null,
			isset( $data['pipeline_version'] ) ? (string) $data['pipeline_version'] : null
		);

		return true;
	}

	/**
	 * Local path first, HTTP GET of the attachment URL as fallback (e.g. a site
	 * that's offloaded the file to remote storage). Returns false on any failure —
	 * including a non-2xx response, so an HTML error page never gets shipped to the
	 * pipeline as if it were the PDF.
	 *
	 * @param int $attachment_id The attachment to read.
	 * @return string|false
	 */
	private static function read_attachment_contents( int $attachment_id ) {
		$local_path = get_attached_file( $attachment_id );

		if ( $local_path && file_exists( $local_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file path, not a remote URL; WP_Filesystem offers no benefit here.
			return file_get_contents( $local_path );
		}

		$response = wp_remote_get( wp_get_attachment_url( $attachment_id ) );

		if ( is_wp_error( $response ) || ! self::is_success_status( wp_remote_retrieve_response_code( $response ) ) ) {
			return false;
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Whether an HTTP status code is a 2xx success.
	 *
	 * @param int $status_code The status code to check.
	 * @return bool
	 */
	private static function is_success_status( int $status_code ): bool {
		return $status_code >= 200 && $status_code < 300;
	}

	/**
	 * Builds a multipart/form-data request body.
	 *
	 * @param string $boundary      The multipart boundary string.
	 * @param array  $fields        Form fields as name => value.
	 * @param string $filename      The uploaded file's name.
	 * @param string $file_contents The uploaded file's raw contents.
	 * @return string
	 */
	private static function build_multipart_body( string $boundary, array $fields, string $filename, string $file_contents ): string {
		$body = '';

		// The name goes inside a quoted header value, so a quote or line break would end it early.
		$filename = str_replace( array( '"', "\r", "\n" ), '', $filename );

		foreach ( $fields as $name => $value ) {
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
			$body .= "{$value}\r\n";
		}

		$body .= "--{$boundary}\r\n";
		$body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
		$body .= 'Content-Type: ' . Plugin::PDF_MIME_TYPE . "\r\n\r\n";
		$body .= $file_contents . "\r\n";
		$body .= "--{$boundary}--\r\n";

		return $body;
	}
}
