<?php
/**
 * Media Library remediation status column.
 *
 * @package AdaRemediationClient
 */

namespace AdaRemediationClient;

/**
 * Generic color-only remediation status column for the Media Library attachment
 * list. Reads only attachment-level postmeta (see Client::store_submission_result
 * and Webhook::handle) — with no other dependencies.
 */
class Media_Library_Badge_Column {

	public const COLUMN = 'ada_remediation_badge';

	/**
	 * Public so that adapters can read, extend, and re-use as needed
	 */
	public const BADGE_COLORS = array(
		Submission_State_Service::BADGE_QUEUED      => '#b0c4de',
		Submission_State_Service::BADGE_RETRYING    => '#9370db',
		Submission_State_Service::BADGE_PENDING     => '#4169e1',
		Submission_State_Service::BADGE_DARK_GREEN  => '#006400',
		Submission_State_Service::BADGE_LIGHT_GREEN => '#90ee90',
		Submission_State_Service::BADGE_YELLOW      => '#ffc107',
		Submission_State_Service::BADGE_RED         => '#dc3545',
		Submission_State_Service::BADGE_ERROR       => '#616161',
	);

	/**
	 * Public so that adapters can read, extend, and re-use as needed
	 */
	public const TOOLTIP_LABELS = array(
		Submission_State_Service::BADGE_QUEUED      => 'Queued for submission',
		Submission_State_Service::BADGE_RETRYING    => 'You\'ve hit your pipeline usage limit; will retry automatically',
		Submission_State_Service::BADGE_PENDING     => 'Remediation in progress',
		Submission_State_Service::BADGE_DARK_GREEN  => 'Compliant',
		Submission_State_Service::BADGE_LIGHT_GREEN => 'Remediation completed with minor issues',
		Submission_State_Service::BADGE_YELLOW      => 'Remediation completed with major issues',
		Submission_State_Service::BADGE_RED         => 'Remediation completed with critical or unclassified issues',
		Submission_State_Service::BADGE_ERROR       => 'No remediation result available',
	);

	/**
	 * Appends the badge column to the Media Library's column list.
	 *
	 * @param array $columns The existing list table columns.
	 * @return array
	 */
	public static function register_column( array $columns ): array {
		$columns[ self::COLUMN ] = 'ADA Remediation Status';

		return $columns;
	}

	/**
	 * Renders the badge column's content for one attachment row.
	 *
	 * @param string $column_name   The column being rendered.
	 * @param int    $attachment_id The row's attachment ID.
	 * @return void
	 */
	public static function render_column( string $column_name, int $attachment_id ): void {
		if ( self::COLUMN !== $column_name ) {
			return;
		}

		$badge = Submission_State_Service::current_badge( $attachment_id );

		if ( ! $badge ) {
			return;
		}

		printf(
			'<span class="ada-remediation-badge" style="display:inline-block;width:12px;height:12px;border-radius:50%%;background:%s;" title="%s"></span>',
			esc_attr( self::BADGE_COLORS[ $badge ] ?? self::BADGE_COLORS[ Submission_State_Service::BADGE_ERROR ] ),
			esc_attr( self::TOOLTIP_LABELS[ $badge ] ?? self::TOOLTIP_LABELS[ Submission_State_Service::BADGE_ERROR ] )
		);
	}
}
