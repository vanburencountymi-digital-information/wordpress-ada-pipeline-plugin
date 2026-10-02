<?php
/**
 * Plugin bootstrap.
 *
 * @package AdaRemediationClient
 */

namespace AdaRemediationClient;

/**
 * Gates all plugin behavior on the three wp-config.php constants being present.
 * Later tickets hang their hook registration off boot()'s configured branch.
 */
class Plugin {

	public const REQUIRED_CONSTANTS = array(
		'ADA_REMEDIATION_API_BASE_URL',
		'ADA_REMEDIATION_API_TOKEN',
		'ADA_REMEDIATION_WEBHOOK_SECRET',
	);

	public const SUBMIT_ATTACHMENT_HOOK = 'ada_remediation_submit_attachment';

	/**
	 * Whether every required wp-config.php constant is defined.
	 */
	public static function is_configured(): bool {
		foreach ( self::REQUIRED_CONSTANTS as $constant ) {
			if ( ! defined( $constant ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Registers every hook this plugin needs, once configured.
	 */
	public static function boot(): void {
		if ( ! self::is_configured() ) {
			return;
		}

		Remediation_Log::maybe_install();

		add_action( 'add_attachment', array( self::class, 'maybe_submit_on_upload' ) );
		add_action( self::SUBMIT_ATTACHMENT_HOOK, array( Client::class, 'submit_attachment' ) );
		add_action( 'rest_api_init', array( Webhook::class, 'register_routes' ) );
		add_filter( 'manage_media_columns', array( Media_Library_Badge_Column::class, 'register_column' ) );
		add_action( 'manage_media_custom_column', array( Media_Library_Badge_Column::class, 'render_column' ), 10, 2 );
		add_action( 'ada_remediation_result', array( File_Replacer::class, 'maybe_replace' ), File_Replacer::HOOK_PRIORITY, 2 );
	}

	/**
	 * Schedules the actual submission rather than calling Client::submit_attachment()
	 * directly — that's an HTTP round-trip to the pipeline (including a possible cold
	 * start if it's scaled to zero), and add_attachment fires synchronously inside the
	 * person's own upload request. Deferring it means nobody's upload waits on it.
	 *
	 * @param int $attachment_id The newly uploaded attachment's post ID.
	 */
	public static function maybe_submit_on_upload( int $attachment_id ): void {
		if ( self::should_auto_submit( $attachment_id ) ) {
			update_post_meta( $attachment_id, '_ada_remediation_badge', 'queued' );
			wp_schedule_single_event( time(), self::SUBMIT_ATTACHMENT_HOOK, array( $attachment_id ) );
		}
	}

	/**
	 * PDF attachments only, and only if no adapter has suppressed the default
	 * trigger via the ada_remediation_auto_trigger_on_upload filter (see Layer 2,
	 * which drives submission itself from a richer hook instead).
	 *
	 * @param int $attachment_id The attachment to check.
	 */
	public static function should_auto_submit( int $attachment_id ): bool {
		if ( get_post_mime_type( $attachment_id ) !== 'application/pdf' ) {
			return false;
		}

		return (bool) apply_filters( 'ada_remediation_auto_trigger_on_upload', true, $attachment_id );
	}
}
