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

	private const SUBMIT_PATH = '/api/submit-document/';

	/**
	 * Submits an attachment's PDF to the remediation pipeline.
	 *
	 * @param int   $attachment_id The attachment to submit.
	 * @param array $context       Optional. 'force' => true bypasses the pipeline's
	 *                             content-hash dedupe.
	 */
	public static function submit_attachment( int $attachment_id, array $context = array() ): bool {
		$file_contents = self::read_attachment_contents( $attachment_id );

		if ( false === $file_contents || null === $file_contents ) {
			self::mark_submission_failed( $attachment_id );

			return false;
		}

		$filename = basename( (string) get_attached_file( $attachment_id ) );
		$filename = $filename ? $filename : "attachment-{$attachment_id}.pdf";
		$boundary = uniqid( 'ada-remediation-', true );

		$fields = array(
			// Includes the attachment_id so every submission gets its own callback_url as
			// the pipeline dedupes (remediation, callback_url) pairs. See Webhook::handle()'s
			// $attachment_id_hint, which this is read back out as.
			'callback_url' => add_query_arg( 'attachment_id', $attachment_id, rest_url( 'ada-remediation/v1/callback' ) ),
			// Unreachable today — no caller passes $context['force'] yet. Reserved for a
			// future feature that needs to force a re-run past the pipeline's
			// content-hash dedupe.
			'force'        => ! empty( $context['force'] ) ? 'true' : 'false',
		);

		$response = wp_remote_post(
			rtrim( ADA_REMEDIATION_API_BASE_URL, '/' ) . self::SUBMIT_PATH,
			array(
				'headers' => array(
					'Authorization' => 'Token ' . ADA_REMEDIATION_API_TOKEN,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => self::build_multipart_body( $boundary, $fields, $filename, $file_contents ),
				// Generous on purpose: this runs on a scheduled WP-Cron hit, not inside
				// anyone's upload request (see Plugin::maybe_submit_on_upload). This allows
				// comfortable coverage of cold start on scale to zero services on the API side.
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) || ! self::is_success_status( wp_remote_retrieve_response_code( $response ) ) ) {
			self::mark_submission_failed( $attachment_id );

			return false;
		}

		self::store_submission_result( $attachment_id, wp_remote_retrieve_body( $response ) );

		return true;
	}

	/**
	 * A failed submission (unreadable file, or the pipeline rejecting the request) used
	 * to leave no trace: no badge, no log row, nothing to show it ever happened. There's
	 * no remediation_id/content_hash/pipeline_version to store at either failure point,
	 * so the log row leaves those empty/null — the table's own column defaults already
	 * support that.
	 *
	 * @param int $attachment_id The attachment whose submission failed.
	 */
	private static function mark_submission_failed( int $attachment_id ): void {
		update_post_meta( $attachment_id, '_ada_remediation_badge', 'error' );

		Remediation_Log::record( $attachment_id, '', '', '', 'error', null, null );
	}

	/**
	 * The submit-document response body is the pipeline's RemediationSerializer —
	 * capturing `id` here (as _ada_remediation_id) is the only way an incoming webhook
	 * (DIC-1900), which carries no WordPress-specific identifier at all, can later be
	 * traced back to this attachment.
	 *
	 * @param int    $attachment_id The attachment that was just submitted.
	 * @param string $response_body The submit-document endpoint's raw JSON response body.
	 */
	private static function store_submission_result( int $attachment_id, string $response_body ): void {
		$data = json_decode( $response_body, true );

		if ( ! is_array( $data ) ) {
			return;
		}

		if ( isset( $data['id'] ) ) {
			update_post_meta( $attachment_id, '_ada_remediation_id', $data['id'] );
		}

		if ( isset( $data['document_id'] ) ) {
			update_post_meta( $attachment_id, '_ada_remediation_content_hash', $data['document_id'] );
		}

		if ( isset( $data['pipeline_version'] ) ) {
			update_post_meta( $attachment_id, '_ada_remediation_pipeline_version', $data['pipeline_version'] );
		}

		update_post_meta( $attachment_id, '_ada_remediation_badge', 'pending' );
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
	 */
	private static function build_multipart_body( string $boundary, array $fields, string $filename, string $file_contents ): string {
		$body = '';

		foreach ( $fields as $name => $value ) {
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
			$body .= "{$value}\r\n";
		}

		$body .= "--{$boundary}\r\n";
		$body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
		$body .= "Content-Type: application/pdf\r\n\r\n";
		$body .= $file_contents . "\r\n";
		$body .= "--{$boundary}--\r\n";

		return $body;
	}
}
