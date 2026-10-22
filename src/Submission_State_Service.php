<?php
/**
 * Tracks whether a submission is in flight for an attachment.
 *
 * @package AdaRemediationClient
 */

namespace AdaRemediationClient;

/**
 * The pipeline keeps one `_ada_remediation_id` per attachment, overwritten by every submit.
 * If a second submit lands while the first job is still running, the first job's webhook finds
 * no attachment carrying its id and gets a 404, so the result is lost. This class is how every
 * submit path (upload trigger, cron, adapters, batch runner) agrees on what "in flight" means.
 *
 * Two pieces of state:
 * - a claim: held only while one request is inside Client::submit_with_result(). It expires by
 *   itself, so a request that dies mid-submit can't lock the file. It lives in wp_options, whose
 *   option_name is a unique key, because postmeta has none and add_post_meta( $unique ) is a
 *   SELECT then an INSERT that two simultaneous requests can both pass.
 * - a stamp: when the file was last queued or submitted. A file counts as in flight while its
 *   badge is queued or pending AND the stamp is recent.
 *
 * Claim reads and writes go straight to the database, never through get_option(), so an object
 * cache can't hand back a claim another process already released.
 */
class Submission_State_Service {

	private const META_BADGE        = '_ada_remediation_badge';
	private const META_SUBMITTED_AT = '_ada_remediation_submitted_at';

	private const POST_TYPE_ATTACHMENT = 'attachment';

	private const META_REMEDIATION_ID   = '_ada_remediation_id';
	private const META_CONTENT_HASH     = '_ada_remediation_content_hash';
	private const META_PIPELINE_VERSION = '_ada_remediation_pipeline_version';
	private const META_APPLIED_ID       = '_ada_remediation_applied_id';
	private const META_CHECKED_AT       = '_ada_remediation_checked_at';

	public const BADGE_QUEUED      = 'queued';
	public const BADGE_RETRYING    = 'retrying';
	public const BADGE_PENDING     = 'pending';
	public const BADGE_ERROR       = 'error';
	public const BADGE_DARK_GREEN  = 'dark-green';
	public const BADGE_LIGHT_GREEN = 'light-green';
	public const BADGE_YELLOW      = 'yellow';
	public const BADGE_RED         = 'red';

	/**
	 * Stamp written by the county adapter before this plugin tracked it. Read as a fallback so
	 * files already in flight when this shipped are not treated as free. Remove it once the
	 * county adapter no longer writes it (DIC-2410) and a STALE_AFTER_SECONDS window has passed.
	 */
	public const LEGACY_META_SUBMITTED_AT = '_county_ada_submitted_at';

	/**
	 * Option name prefix; the attachment ID is appended.
	 */
	public const CLAIM_OPTION_PREFIX = 'ada_remediation_submit_claim_';

	/**
	 * Splits a claim value into "<timestamp><separator><uuid>".
	 */
	private const CLAIM_SEPARATOR = '|';

	/**
	 * Must outlast Client::SUBMIT_TIMEOUT_SECONDS (plus the local file read), so a claim is
	 * only taken over after the request that held it has certainly died.
	 */
	public const CLAIM_TTL_SECONDS = 180;

	/**
	 * The pipeline's queue can lag by ~15 minutes, so allow double that before a
	 * pending file may be retried.
	 */
	public const STALE_AFTER_SECONDS = 1800;

	/**
	 * A merely queued file is waiting for WP-Cron to fire its job (seconds to minutes), and a
	 * retrying one for the time of its next try. If it is still waiting this long after that
	 * time, the job is not going to run.
	 */
	public const QUEUED_STALE_AFTER_SECONDS = 600;

	/**
	 * Marks the file as waiting for its scheduled submit. Does nothing if the pipeline already
	 * has a pending job for it: flipping pending back to queued would hide that job from
	 * has_pending_job(), and the next submit would overwrite its remediation id.
	 *
	 * Meant for a freshly uploaded file. The check and the write are not atomic, so a caller
	 * queueing existing files that other requests may be submitting must not rely on this alone
	 * (DIC-2312 decides whether the runner needs a guarded queue entry point).
	 *
	 * @param int $attachment_id The attachment.
	 * @return bool True if the file was marked queued; false if a pending job blocked it.
	 */
	public static function mark_queued( int $attachment_id ): bool {
		return self::mark_waiting( $attachment_id, self::BADGE_QUEUED, time() );
	}

	/**
	 * Marks the file as retrying: the pipeline said to wait, and a retry is scheduled. The stamp is
	 * the time of that retry, not now, so the file stays "in flight" through a wait of minutes to a
	 * day instead of looking abandoned once QUEUED_STALE_AFTER_SECONDS passes, and so the retry
	 * event can check it is still the one the file is waiting for (see is_retry_scheduled_for()).
	 * Like mark_queued(), it does nothing if the pipeline already has a pending job for the file.
	 *
	 * @param int $attachment_id The attachment.
	 * @param int $next_try_at   Unix time the retry is scheduled for.
	 * @return bool True if the file was marked retrying; false if a pending job blocked it.
	 */
	public static function mark_retrying( int $attachment_id, int $next_try_at ): bool {
		return self::mark_waiting( $attachment_id, self::BADGE_RETRYING, $next_try_at );
	}

	/**
	 * Whether the file is still waiting for the retry scheduled for $due_at. A retry event that
	 * finds it is not (the file was submitted another way, or a newer retry replaced this one)
	 * must do nothing: that is what keeps one retry chain per file, whatever else was scheduled.
	 *
	 * @param int $attachment_id The attachment.
	 * @param int $due_at        The time the retry event was scheduled for.
	 * @return bool True if the badge is retrying and its stamp is exactly $due_at.
	 */
	public static function is_retry_scheduled_for( int $attachment_id, int $due_at ): bool {
		return self::BADGE_RETRYING === self::current_badge( $attachment_id )
			&& self::own_stamp( $attachment_id ) === $due_at;
	}

	/**
	 * Whether a first-try event still has work to do: the file is queued for it, or was never marked
	 * (something scheduled the hook without marking the file). Anything else means the file moved on
	 * since the event was scheduled: it has a result, failed, or is waiting for a retry of its own.
	 *
	 * @param int $attachment_id The attachment.
	 * @return bool True if the badge is queued or empty.
	 */
	public static function is_awaiting_first_try( int $attachment_id ): bool {
		return in_array( self::current_badge( $attachment_id ), array( '', self::BADGE_QUEUED ), true );
	}

	/**
	 * Stamps and badges the file, unless the pipeline has a pending job for it.
	 *
	 * @param int    $attachment_id The attachment.
	 * @param string $badge         BADGE_QUEUED or BADGE_RETRYING.
	 * @param int    $stamp         The Unix time to record: now, or the time of the next try.
	 * @return bool True if the file was marked; false if a pending job blocked it.
	 */
	private static function mark_waiting( int $attachment_id, string $badge, int $stamp ): bool {
		if ( self::has_pending_job( $attachment_id ) ) {
			self::log_skip( $attachment_id, 'the pipeline has a pending job, so it was not marked ' . $badge );

			return false;
		}

		// Stamp before badge, for the reason record_submitted_at() gives.
		self::record_submitted_at( $attachment_id, $stamp );
		update_post_meta( $attachment_id, self::META_BADGE, $badge );

		return true;
	}

	/**
	 * Marks the file as accepted by the pipeline and awaiting its webhook.
	 *
	 * @param int $attachment_id The attachment.
	 * @return void
	 */
	public static function mark_pending( int $attachment_id ): void {
		self::record_submitted_at( $attachment_id );
		update_post_meta( $attachment_id, self::META_BADGE, self::BADGE_PENDING );
	}

	/**
	 * Marks the submission as failed. Not stamped: an error badge is never in flight, and a
	 * stamp here would only invite someone to treat error like queued and pending.
	 *
	 * @param int $attachment_id The attachment.
	 * @return void
	 */
	public static function mark_error( int $attachment_id ): void {
		self::mark_result( $attachment_id, self::BADGE_ERROR );
	}

	/**
	 * Records the badge a pipeline result produced (or error). Not limited to the BADGE_*
	 * constants: adapters may store their own badge keys.
	 *
	 * @param int    $attachment_id The attachment.
	 * @param string $badge         The badge to store.
	 * @return void
	 */
	public static function mark_result( int $attachment_id, string $badge ): void {
		update_post_meta( $attachment_id, self::META_BADGE, $badge );
	}

	/**
	 * Records an accepted submission: stamp and pending badge first, then the ids. In that order
	 * on purpose: once the id is stored a webhook can match it and write the final badge, and a
	 * pending written after that would overwrite the result.
	 *
	 * @param int         $attachment_id  The attachment.
	 * @param string      $remediation_id The pipeline's id for the job.
	 * @param string|null $content_hash   The pipeline's document_id, if it sent one.
	 * @param string|null $version        The pipeline version, if it sent one.
	 * @return void
	 */
	public static function mark_submitted( int $attachment_id, string $remediation_id, ?string $content_hash = null, ?string $version = null ): void {
		self::mark_pending( $attachment_id );
		self::record_submission( $attachment_id, $remediation_id, $content_hash, $version );
	}

	/**
	 * Stores what the pipeline told us about a job, so a webhook can be matched back to this
	 * attachment. Empty hash and version are skipped rather than stored as blanks. Does not touch
	 * the badge: use mark_submitted() for a fresh submit, and this alone when adopting a job
	 * whose result already arrived.
	 *
	 * @param int         $attachment_id  The attachment.
	 * @param string      $remediation_id The pipeline's id for the job.
	 * @param string|null $content_hash   The pipeline's document_id, if it sent one.
	 * @param string|null $version        The pipeline version, if it sent one.
	 * @return void
	 */
	public static function record_submission( int $attachment_id, string $remediation_id, ?string $content_hash = null, ?string $version = null ): void {
		update_post_meta( $attachment_id, self::META_REMEDIATION_ID, $remediation_id );

		if ( null !== $content_hash && '' !== $content_hash ) {
			update_post_meta( $attachment_id, self::META_CONTENT_HASH, $content_hash );
		}

		if ( null !== $version && '' !== $version ) {
			update_post_meta( $attachment_id, self::META_PIPELINE_VERSION, $version );
		}
	}

	/**
	 * The pipeline's id for the file's latest submitted job.
	 *
	 * @param int $attachment_id The attachment.
	 * @return string The remediation id, or '' if none was stored.
	 */
	public static function remediation_id( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::META_REMEDIATION_ID, true );
	}

	/**
	 * Finds the attachment carrying a given remediation id.
	 *
	 * @param string $remediation_id The remediation id to search for.
	 * @return int The attachment id, or 0 if none carries it.
	 */
	public static function find_attachment_id( string $remediation_id ): int {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- this postmeta has no dedicated table/index; get_posts() is the only lookup this plugin does against it.
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE_ATTACHMENT,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::META_REMEDIATION_ID,
				'meta_value'     => $remediation_id,
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		return isset( $posts[0] ) ? (int) $posts[0] : 0;
	}

	/**
	 * Whether a submit failed and left the file with no remediation id (the DIC-2184 case: the
	 * pipeline may hold a job whose id never arrived, so its webhook could be adopted).
	 *
	 * @param int $attachment_id The attachment.
	 * @return bool True if it is an attachment with the error badge and no stored remediation id.
	 */
	public static function is_failed_submission( int $attachment_id ): bool {
		return '' === self::remediation_id( $attachment_id )
			&& self::POST_TYPE_ATTACHMENT === get_post_type( $attachment_id )
			&& self::BADGE_ERROR === self::current_badge( $attachment_id );
	}

	/**
	 * The remediation id whose result was last applied to the file.
	 *
	 * @param int $attachment_id The attachment.
	 * @return string The applied remediation id, or '' if no result was applied yet.
	 */
	public static function applied_remediation_id( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::META_APPLIED_ID, true );
	}

	/**
	 * Records that a job's result was applied, so a repeated delivery is skipped.
	 *
	 * @param int    $attachment_id  The attachment.
	 * @param string $remediation_id The job whose result was applied.
	 * @return void
	 */
	public static function mark_applied( int $attachment_id, string $remediation_id ): void {
		update_post_meta( $attachment_id, self::META_APPLIED_ID, $remediation_id );
	}

	/**
	 * Records when a result was last received for the file.
	 *
	 * @param int    $attachment_id The attachment.
	 * @param string $checked_at    The time, as a MySQL datetime.
	 * @return void
	 */
	public static function mark_checked( int $attachment_id, string $checked_at ): void {
		update_post_meta( $attachment_id, self::META_CHECKED_AT, $checked_at );
	}

	/**
	 * The file's current badge ('' when it has none).
	 *
	 * @param int $attachment_id The attachment.
	 * @return string The stored badge key, or '' if none has been recorded.
	 */
	public static function current_badge( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::META_BADGE, true );
	}

	/**
	 * Runs one submit with the right to be the only one, or does nothing.
	 *
	 * Checks has_pending_job(), not is_in_flight(): the caller's own claim and its own queued
	 * badge (it is the scheduled job) must not block it, and a self-block here would stop every
	 * queued submit. Callers outside this class never see the claim, so they can't skip it.
	 *
	 * @param int           $attachment_id The attachment.
	 * @param callable      $send          Does the submit and returns its Submit_Result.
	 * @param callable|null $still_wanted  Optional. Called with no arguments once the claim is held;
	 *                                     returns false if the submit is no longer wanted (a retry
	 *                                     whose file has moved on). Checked here, not before the
	 *                                     claim, so two events can't both pass it and then both submit.
	 * @return Submit_Result Refused if another submit is running, the pipeline has a pending job for
	 *                       the file, or $still_wanted says no (nothing was changed); otherwise what
	 *                       $send returned.
	 * @throws \UnexpectedValueException If $send returns anything but a Submit_Result. The claim
	 *                                   is still released.
	 */
	public static function run_exclusive( int $attachment_id, callable $send, ?callable $still_wanted = null ): Submit_Result {
		$token = self::claim( $attachment_id );

		if ( null === $token ) {
			self::log_skip( $attachment_id, 'another submit holds the claim' );

			return Submit_Result::refused();
		}

		try {
			// A pending job's webhook would 404 if this submit overwrote its remediation id.
			if ( self::has_pending_job( $attachment_id ) ) {
				self::log_skip( $attachment_id, 'the pipeline has a pending job' );

				return Submit_Result::refused();
			}

			if ( null !== $still_wanted && ! $still_wanted() ) {
				self::log_skip( $attachment_id, 'the file is no longer waiting for this submit' );

				return Submit_Result::refused();
			}

			$result = $send();

			// Said outright, not left to a bare TypeError: whoever passed the callback needs to know.
			if ( ! $result instanceof Submit_Result ) {
				throw new \UnexpectedValueException( 'The submit callback must return a Submit_Result.' );
			}

			return $result;
		} finally {
			self::release( $attachment_id, $token );
		}
	}

	/**
	 * Records that a submit was refused. A refused cron job is not rescheduled, so without this
	 * a file left at queued would have no trace of why it was never sent.
	 *
	 * @param int    $attachment_id The attachment.
	 * @param string $reason        Why the submit was refused.
	 * @return void
	 */
	private static function log_skip( int $attachment_id, string $reason ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- no plugin logger; the host's PHP error log is where this is looked for.
		error_log( sprintf( 'ada-remediation: submit skipped for attachment %d: %s.', $attachment_id, $reason ) );
	}

	/**
	 * Takes the right to run one submit for this file.
	 *
	 * @param int $attachment_id The attachment.
	 * @return string|null A token to pass to release(), or null if another request holds a recent claim.
	 */
	private static function claim( int $attachment_id ): ?string {
		$now = time();

		$token = self::build_claim_token( $now );

		if ( self::insert_claim( $attachment_id, $token ) ) {
			return $token;
		}

		$held = self::read_claim( $attachment_id );

		// The holder released between our failed insert and this read: nothing is held, so try
		// once more rather than skip a submit that no one else is going to run.
		if ( '' === $held ) {
			return self::insert_claim( $attachment_id, $token ) ? $token : null;
		}

		if ( self::claim_is_live( $held, $now ) ) {
			return null;
		}

		return self::take_over( $attachment_id, $held, $token ) ? $token : null;
	}

	/**
	 * Inserts the claim row. INSERT IGNORE against option_name's unique key: of two simultaneous
	 * requests the database lets exactly one insert a row.
	 *
	 * @param int    $attachment_id The attachment.
	 * @param string $token         The claim value.
	 * @return bool Whether this call inserted the row.
	 */
	private static function insert_claim( int $attachment_id, string $token ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- no WordPress API inserts atomically without an object-cache pre-check.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::claim_option( $attachment_id ),
				$token
			)
		);

		return 1 === $inserted;
	}

	/**
	 * Gives the claim back, but only if it is still ours: a slow request must not delete the
	 * claim a newer request took over from it.
	 *
	 * @param int    $attachment_id The attachment.
	 * @param string $token         The token claim() returned.
	 * @return void
	 */
	private static function release( int $attachment_id, string $token ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- delete_option() can't be conditional on the value.
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => self::claim_option( $attachment_id ),
				'option_value' => $token,
			)
		);
	}

	/**
	 * Whether a submit is running right now: another request holds a recent claim.
	 *
	 * @param int $attachment_id The attachment.
	 * @return bool True if another request holds a claim young enough that its holder may still be running.
	 */
	private static function is_claimed( int $attachment_id ): bool {
		return self::claim_is_live( self::read_claim( $attachment_id ), time() );
	}

	/**
	 * Whether the pipeline is working on this file: badge pending and the stamp is recent.
	 * Resubmitting now would overwrite the running job's id.
	 *
	 * @param int $attachment_id The attachment.
	 * @return bool True if the badge is pending and its stamp is younger than STALE_AFTER_SECONDS.
	 */
	private static function has_pending_job( int $attachment_id ): bool {
		return self::in_progress_within( $attachment_id, self::BADGE_PENDING, self::STALE_AFTER_SECONDS );
	}

	/**
	 * Whether any submission is out for this file: a submit is running, the file is queued
	 * for cron or waiting to retry, or the pipeline is working on it. For adapters and the batch runner to check
	 * before queueing a job; Client::submit_with_result() itself uses run_exclusive().
	 *
	 * @param int $attachment_id The attachment.
	 * @return bool True if a new submit for this file would collide with one already out.
	 */
	public static function is_in_flight( int $attachment_id ): bool {
		return self::is_claimed( $attachment_id )
			|| self::has_pending_job( $attachment_id )
			|| self::in_progress_within( $attachment_id, self::BADGE_QUEUED, self::QUEUED_STALE_AFTER_SECONDS )
			|| self::in_progress_within( $attachment_id, self::BADGE_RETRYING, self::QUEUED_STALE_AFTER_SECONDS );
	}

	/**
	 * Records when the file was queued or submitted, or when its next retry is due. Callers write
	 * this before the badge: a badge with no recent stamp is never treated as in flight, so a reader
	 * between the two writes (or a crash) can't see a half-marked file as free. A stamp with the old
	 * badge is harmless when the stamp is now. A retry's stamp is a future time, so a crash between
	 * the two writes can leave a queued file counting as in flight, with no retry scheduled, until
	 * then. That narrow window is accepted for now (DIC-2312 decides the guarded entry point).
	 *
	 * @param int      $attachment_id The attachment.
	 * @param int|null $at            Unix time to record; now if omitted.
	 * @return void
	 */
	private static function record_submitted_at( int $attachment_id, ?int $at = null ): void {
		update_post_meta( $attachment_id, self::META_SUBMITTED_AT, $at ?? time() );
	}

	/**
	 * When the file was last queued or submitted, or when its next retry is due (0 if never), from
	 * this plugin's stamp or the adapter's legacy one, whichever is newer.
	 *
	 * @param int $attachment_id The attachment.
	 * @return int Unix time of the newer of the two stamps, or 0 if the file was never queued or submitted.
	 */
	private static function submitted_at( int $attachment_id ): int {
		return max(
			self::own_stamp( $attachment_id ),
			(int) get_post_meta( $attachment_id, self::LEGACY_META_SUBMITTED_AT, true )
		);
	}

	/**
	 * This plugin's own stamp, without the adapter's legacy one: what record_submitted_at() wrote.
	 *
	 * @param int $attachment_id The attachment.
	 * @return int Unix time, 0 if never written.
	 */
	private static function own_stamp( int $attachment_id ): int {
		return (int) get_post_meta( $attachment_id, self::META_SUBMITTED_AT, true );
	}

	/**
	 * Whether the badge is the given in-progress one and the stamp is younger than the limit.
	 *
	 * @param int    $attachment_id The attachment.
	 * @param string $badge         The badge that counts.
	 * @param int    $stale_after   Seconds after which the stamp no longer counts.
	 * @return bool True if the badge matches and the newest stamp is younger than $stale_after.
	 */
	private static function in_progress_within( int $attachment_id, string $badge, int $stale_after ): bool {
		if ( self::current_badge( $attachment_id ) !== $badge ) {
			return false;
		}

		$submitted_at = self::submitted_at( $attachment_id );

		return $submitted_at > 0 && ( time() - $submitted_at ) < $stale_after;
	}

	/**
	 * The claim's option name for an attachment.
	 *
	 * @param int $attachment_id The attachment.
	 * @return string The wp_options option_name that holds this file's claim.
	 */
	private static function claim_option( int $attachment_id ): string {
		return self::CLAIM_OPTION_PREFIX . $attachment_id;
	}

	/**
	 * The stored claim value, "<timestamp>|<uuid>", read from the database ('' when none).
	 *
	 * @param int $attachment_id The attachment.
	 * @return string The stored claim value, or '' if none is held.
	 */
	private static function read_claim( int $attachment_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- deliberately uncached, see the class docblock.
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				self::claim_option( $attachment_id )
			)
		);
	}

	/**
	 * Whether a stored claim is recent enough that its holder may still be running.
	 *
	 * @param string $held The stored claim value (empty when none).
	 * @param int    $now  The current time.
	 * @return bool True if the claim was taken less than CLAIM_TTL_SECONDS ago.
	 */
	private static function claim_is_live( string $held, int $now ): bool {
		$held_since = self::claim_started_at( $held );

		return $held_since > 0 && ( $now - $held_since ) < self::CLAIM_TTL_SECONDS;
	}

	/**
	 * A new claim value, "<timestamp><separator><uuid>". The timestamp must stay first:
	 * claim_started_at() reads it back to expire the claim.
	 *
	 * @param int $now The time the claim is taken.
	 * @return string The claim value.
	 */
	private static function build_claim_token( int $now ): string {
		return $now . self::CLAIM_SEPARATOR . wp_generate_uuid4();
	}

	/**
	 * When a stored claim was taken: the integer before the separator in a build_claim_token() value.
	 *
	 * @param string $held The stored claim value (empty when none).
	 * @return int Unix time the claim was taken, or 0 if there is no claim.
	 */
	private static function claim_started_at( string $held ): int {
		return (int) explode( self::CLAIM_SEPARATOR, $held, 2 )[0];
	}

	/**
	 * Replaces a stale claim in one conditional UPDATE that matches the value just read, so
	 * of two requests that both saw it stale only one changes a row.
	 *
	 * @param int    $attachment_id The attachment.
	 * @param string $stale         The stale claim value just read.
	 * @param string $token         The new claim value.
	 * @return bool True if this call replaced the stale claim; false if another request got there first.
	 */
	private static function take_over( int $attachment_id, string $stale, string $token ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WordPress has no compare-and-swap for options.
		$changed = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $token ),
			array(
				'option_name'  => self::claim_option( $attachment_id ),
				'option_value' => $stale,
			)
		);

		return 1 === $changed;
	}
}
