<?php
/**
 * The site's scoring configuration.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Leads\Services;

use Hiveclerk\Core\Settings\SettingsRepository;
use Hiveclerk\Domain\Lead\Scoring\RuleSet;
use Hiveclerk\Domain\Lead\ScoreBand;

/**
 * Reads and writes the rule set, the band thresholds and the alert
 * settings, and applies the extension filter over the top.
 *
 * One class rather than three because all three are edited on one screen
 * and read together on every scoring pass. Splitting them would mean
 * three option reads per visitor message for a settings row that is
 * already one option.
 */
final class ScoringPolicy {

	/**
	 * Settings keys.
	 */
	private const RULES      = 'leads.scoring_rules';
	private const THRESHOLDS = 'leads.bands';
	private const ALERTS     = 'leads.alerts';

	/**
	 * Default score at which staff are told about a lead.
	 *
	 * The qualified boundary, deliberately. An alert threshold that
	 * differs from the band shown on the card produces the question
	 * "why did nobody tell me about this hot lead", and the answer is a
	 * number on a settings screen nobody remembers setting.
	 */
	public const DEFAULT_ALERT_SCORE = 75;

	/**
	 * Construct.
	 *
	 * @param SettingsRepository $settings Settings storage.
	 */
	public function __construct(
		private readonly SettingsRepository $settings
	) {
	}

	/**
	 * The rule set in force.
	 *
	 * @return RuleSet
	 */
	public function rules(): RuleSet {
		$stored = $this->settings->get( self::RULES );

		if ( ! is_array( $stored ) ) {
			$stored = RuleSet::defaults();
		}

		$rules = RuleSet::fromArray( $stored );

		/**
		 * Filter the scoring rules before they are evaluated.
		 *
		 * @param array<int, array<string, mixed>> $rules Rules in storage form.
		 */
		$filtered = apply_filters( 'hiveclerk/lead/scoring_rules', $rules->toArray() );

		return is_array( $filtered ) ? RuleSet::fromArray( $filtered ) : $rules;
	}

	/**
	 * Whether the customer has ever saved a rule set.
	 *
	 * The editor says "these are the defaults" until they have, which is
	 * the difference between a screen showing suggestions and one showing
	 * a policy somebody chose.
	 *
	 * @return bool
	 */
	public function isCustomised(): bool {
		return is_array( $this->settings->get( self::RULES ) );
	}

	/**
	 * Replace the rule set.
	 *
	 * @param RuleSet $rules New rules.
	 * @return void
	 */
	public function saveRules( RuleSet $rules ): void {
		$this->settings->set( self::RULES, $rules->toArray() );
	}

	/**
	 * The band boundaries.
	 *
	 * @return array<string, int>
	 */
	public function thresholds(): array {
		$stored = $this->settings->get( self::THRESHOLDS );
		$bounds = ScoreBand::DEFAULTS;

		if ( ! is_array( $stored ) ) {
			return $bounds;
		}

		foreach ( array_keys( $bounds ) as $band ) {
			if ( isset( $stored[ $band ] ) && is_numeric( $stored[ $band ] ) ) {
				$bounds[ $band ] = max( 0, min( 1000, (int) $stored[ $band ] ) );
			}
		}

		return $bounds;
	}

	/**
	 * Save the band boundaries.
	 *
	 * @param array<string, int> $thresholds Lower bounds keyed by band.
	 * @return void
	 */
	public function saveThresholds( array $thresholds ): void {
		$this->settings->set( self::THRESHOLDS, $thresholds );
	}

	/**
	 * The band a score falls into under this site's boundaries.
	 *
	 * @param int $score Materialised total.
	 * @return ScoreBand
	 */
	public function band( int $score ): ScoreBand {
		return ScoreBand::forScore( $score, $this->thresholds() );
	}

	/**
	 * The highest score this rule set can produce.
	 *
	 * @return int
	 */
	public function ceiling(): int {
		return $this->rules()->ceiling();
	}

	/**
	 * Threshold notification settings (FR-LED-09).
	 *
	 * @return array{enabled: bool, score: int, emails: array<int, string>, slack_webhook: string|null}
	 */
	public function alerts(): array {
		$stored = $this->settings->get( self::ALERTS );
		$stored = is_array( $stored ) ? $stored : array();

		$score = isset( $stored['score'] ) && is_numeric( $stored['score'] )
			? (int) $stored['score']
			: self::DEFAULT_ALERT_SCORE;

		$webhook = $stored['slack_webhook'] ?? null;

		return array(
			'enabled'       => (bool) ( $stored['enabled'] ?? false ),
			'score'         => max( 1, min( 1000, $score ) ),
			'emails'        => $this->emails( $stored['emails'] ?? null ),
			'slack_webhook' => is_string( $webhook ) && '' !== trim( $webhook ) ? trim( $webhook ) : null,
		);
	}

	/**
	 * Save the notification settings.
	 *
	 * @param array<string, mixed> $alerts Settings.
	 * @return void
	 */
	public function saveAlerts( array $alerts ): void {
		$this->settings->set( self::ALERTS, $alerts );
	}

	/**
	 * Clean a recipient list.
	 *
	 * @param mixed $value Raw stored value.
	 * @return array<int, string>
	 */
	private function emails( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$emails = array();

		foreach ( $value as $email ) {
			if ( is_string( $email ) && is_email( trim( $email ) ) ) {
				$emails[] = trim( $email );
			}

			if ( count( $emails ) >= 10 ) {
				break;
			}
		}

		return array_values( array_unique( $emails ) );
	}
}
