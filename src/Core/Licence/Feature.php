<?php
/**
 * Gated features.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Licence;

/**
 * Everything a licence can switch on, as a closed set.
 *
 * An enum rather than free strings so that a gate can never be checked
 * against a feature name that does not exist. `can( 'crm_sync' )` against
 * a feature registered as `crm` returns false and looks exactly like a
 * customer who has not paid — a bug that presents as a billing dispute.
 *
 * The numeric limits — clerks, chunks, sites — are deliberately not here.
 * They are not on/off questions, they live on {@see Tier}, and folding
 * them in would give this enum two kinds of member with two kinds of
 * answer.
 */
enum Feature: string {

	case Crm            = 'crm';
	case EmailSequences = 'email_sequences';
	case Workflows      = 'workflows';
	case RemoveBadge    = 'remove_badge';
	case WhiteLabel     = 'white_label';
	case Multisite      = 'multisite';

	/**
	 * Human label, for the upgrade prompt.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Crm            => 'CRM sync',
			self::EmailSequences => 'Email sequences',
			self::Workflows      => 'Workflows',
			self::RemoveBadge    => 'Removing the badge',
			self::WhiteLabel     => 'White-label mode',
			self::Multisite      => 'Multisite',
		};
	}

	/**
	 * The cheapest tier that includes this feature.
	 *
	 * Carried here so the 402 can name a specific plan rather than saying
	 * "upgrade". "This needs Pro" is an instruction; "this requires a
	 * paid licence" is a shrug.
	 *
	 * @return Tier
	 */
	public function requires(): Tier {
		return match ( $this ) {
			self::Crm, self::EmailSequences, self::Workflows, self::RemoveBadge => Tier::Pro,
			self::Multisite  => Tier::Business,
			self::WhiteLabel => Tier::Agency,
		};
	}
}
