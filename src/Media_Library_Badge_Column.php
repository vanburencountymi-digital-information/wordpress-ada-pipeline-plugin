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
		'queued'      => '#b0c4de',
		'pending'     => '#4169e1',
		'dark-green'  => '#006400',
		'light-green' => '#90ee90',
		'yellow'      => '#ffc107',
		'red'         => '#dc3545',
		'error'       => '#616161',
	);

	/**
	 * Public so adapters can read, extend, and re-use as needed
	 */
	public const TOOLTIP_LABELS = array(
		'queued'      => 'Queued for submission',
		'pending'     => 'Remediation in progress',
		'dark-green'  => 'Compliant',
		'light-green' => 'Remediation completed with minor issues',
		'yellow'      => 'Remediation completed with major issues',
		'red'         => 'Remediation completed with critical or unclassified issues',
		'error'       => 'No remediation result available',
	);

	/**
	 * Appends the badge column to the Media Library's column list.
	 *
	 * @param array $columns The existing list table columns.
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
	 */
	public static function render_column( string $column_name, int $attachment_id ): void {
		if ( self::COLUMN !== $column_name ) {
			return;
		}

		$badge = get_post_meta( $attachment_id, '_ada_remediation_badge', true );

		if ( ! $badge ) {
			return;
		}

		printf(
			'<span class="ada-remediation-badge" style="display:inline-block;width:12px;height:12px;border-radius:50%%;background:%s;" title="%s"></span>',
			esc_attr( self::BADGE_COLORS[ $badge ] ?? self::BADGE_COLORS['error'] ),
			esc_attr( self::TOOLTIP_LABELS[ $badge ] ?? self::TOOLTIP_LABELS['error'] )
		);
	}
}
