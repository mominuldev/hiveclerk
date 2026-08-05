<?php
/**
 * Wizard progress.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Onboarding\Services;

use DateTimeZone;
use Hiveclerk\Core\Support\ClockInterface;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Knowledge\KnowledgeSourceRepositoryInterface;

/**
 * Where the operator got to, and what they chose (FR-ONB-05).
 *
 * ## The wizard does not create anything
 *
 * Every step in the flow calls the endpoint that already exists for the
 * thing it is doing: `PUT /admin/settings/providers` verifies the key,
 * `POST /admin/agents` hires the clerk, `POST /admin/knowledge/sources`
 * creates a source. This class records which of those succeeded and what
 * they produced.
 *
 * The alternative — a wizard with its own create-everything endpoint —
 * means a second implementation of every validation rule in the product,
 * diverging from the first the moment either changes, on the one path
 * where a customer decides whether to keep the plugin.
 *
 * ## Progress is per site, not per user
 *
 * An option rather than user meta. Setting up a site is one job with one
 * outcome, and a second administrator opening the plugin should see the
 * wizard their colleague already finished — not start it again and hire a
 * second clerk onto the same pages.
 */
final class OnboardingState {

	/**
	 * Where progress lives.
	 */
	private const OPTION = 'hiveclerk_onboarding';

	/**
	 * How many steps the wizard has (FR-ONB-01).
	 */
	public const STEPS = 5;

	/**
	 * What each step is called.
	 */
	public const LABELS = array(
		1 => 'Model',
		2 => 'Role',
		3 => 'Knowledge',
		4 => 'Look',
		5 => 'Publish',
	);

	/**
	 * Construct.
	 *
	 * @param AgentRepositoryInterface           $agents  Clerk storage.
	 * @param KnowledgeSourceRepositoryInterface $sources Knowledge sources.
	 * @param ClockInterface                     $clock   Clock.
	 */
	public function __construct(
		private readonly AgentRepositoryInterface $agents,
		private readonly KnowledgeSourceRepositoryInterface $sources,
		private readonly ClockInterface $clock
	) {
	}

	/**
	 * Everything the wizard needs to resume.
	 *
	 * @return array<string, mixed>
	 */
	public function current(): array {
		$stored = $this->stored();

		return array(
			'status'       => $this->status( $stored ),
			'current_step' => $this->currentStep( $stored ),
			'steps'        => $stored['steps'],
			'agent'        => $stored['agent'],
			'sources'      => $stored['sources'],
			'started_at'   => $stored['started_at'],
			'completed_at' => $stored['completed_at'],
			'skipped_at'   => $stored['skipped_at'],
			'labels'       => self::LABELS,
			// Read from the world rather than from the record. An operator
			// who set a key or hired a clerk outside the wizard has done
			// the step, and a flow that asked them to do it again would
			// look broken to the person who knows most about their own
			// site.
			'site'         => array(
				'has_clerk'  => $this->agents->count() > 0,
				'has_source' => $this->sources->count() > 0,
			),
		);
	}

	/**
	 * Record that a step was finished.
	 *
	 * @param int                  $step    Step number.
	 * @param array<string, mixed> $payload What the operator chose.
	 * @return array<string, mixed> The state as stored.
	 */
	public function completeStep( int $step, array $payload ): array {
		$step   = max( 1, min( self::STEPS, $step ) );
		$stored = $this->stored();

		$stored['steps'][ (string) $step ] = array(
			'done_at' => $this->now(),
			'data'    => $payload,
		);

		if ( isset( $payload['agent'] ) && is_string( $payload['agent'] ) ) {
			$stored['agent'] = $payload['agent'];
		}

		if ( isset( $payload['sources'] ) && is_array( $payload['sources'] ) ) {
			$stored['sources'] = array_values(
				array_unique(
					array_merge(
						$stored['sources'],
						array_filter( $payload['sources'], 'is_string' )
					)
				)
			);
		}

		$stored['started_at'] ??= $this->now();

		return $this->save( $stored );
	}

	/**
	 * Mark the wizard finished.
	 *
	 * @return array<string, mixed>
	 */
	public function complete(): array {
		$stored = $this->stored();

		$stored['started_at'] ??= $this->now();
		$stored['completed_at'] = $this->now();
		$stored['skipped_at']   = null;

		return $this->save( $stored );
	}

	/**
	 * Mark the wizard skipped.
	 *
	 * Skipping is not completing, and the two are stored separately. A
	 * customer who skipped setup and later asks why nothing works is
	 * answerable; one whose skip was recorded as a completion is not.
	 *
	 * @return array<string, mixed>
	 */
	public function skip(): array {
		$stored = $this->stored();

		$stored['started_at'] ??= $this->now();
		$stored['skipped_at']   = $this->now();

		return $this->save( $stored );
	}

	/**
	 * Start again from step one, keeping nothing.
	 *
	 * The wizard is re-runnable because setup is not always right first
	 * time. Nothing it created is touched: a second run that deleted the
	 * first run's clerk would be a destructive action behind a button
	 * labelled "Run setup again".
	 *
	 * @return array<string, mixed>
	 */
	public function restart(): array {
		return $this->save( $this->blank() );
	}

	/**
	 * Whether the wizard should be shown on this site.
	 *
	 * @return bool
	 */
	public function isPending(): bool {
		$stored = $this->stored();

		return null === $stored['completed_at'] && null === $stored['skipped_at'];
	}

	/**
	 * The step the operator should land on.
	 *
	 * The first unfinished step rather than the highest finished one plus
	 * one, so somebody who jumped ahead and came back is returned to the
	 * gap rather than past it.
	 *
	 * @param array<string, mixed> $stored Stored state.
	 * @return int
	 */
	private function currentStep( array $stored ): int {
		for ( $step = 1; $step <= self::STEPS; $step++ ) {
			if ( ! isset( $stored['steps'][ (string) $step ] ) ) {
				return $step;
			}
		}

		return self::STEPS;
	}

	/**
	 * One word for where the wizard stands.
	 *
	 * @param array<string, mixed> $stored Stored state.
	 * @return string
	 */
	private function status( array $stored ): string {
		if ( null !== $stored['completed_at'] ) {
			return 'completed';
		}

		if ( null !== $stored['skipped_at'] ) {
			return 'skipped';
		}

		return array() === $stored['steps'] ? 'not_started' : 'in_progress';
	}

	/**
	 * Stored state, merged over the blank shape.
	 *
	 * @return array{steps: array<string, mixed>, agent: string|null, sources: array<int, string>, started_at: string|null, completed_at: string|null, skipped_at: string|null}
	 */
	private function stored(): array {
		$raw = get_option( self::OPTION, array() );

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$blank = $this->blank();

		return array(
			'steps'        => is_array( $raw['steps'] ?? null ) ? $raw['steps'] : $blank['steps'],
			'agent'        => is_string( $raw['agent'] ?? null ) ? $raw['agent'] : null,
			'sources'      => is_array( $raw['sources'] ?? null )
				? array_values( array_filter( $raw['sources'], 'is_string' ) )
				: array(),
			'started_at'   => is_string( $raw['started_at'] ?? null ) ? $raw['started_at'] : null,
			'completed_at' => is_string( $raw['completed_at'] ?? null ) ? $raw['completed_at'] : null,
			'skipped_at'   => is_string( $raw['skipped_at'] ?? null ) ? $raw['skipped_at'] : null,
		);
	}

	/**
	 * The empty state.
	 *
	 * @return array{steps: array<string, mixed>, agent: null, sources: array<int, string>, started_at: null, completed_at: null, skipped_at: null}
	 */
	private function blank(): array {
		return array(
			'steps'        => array(),
			'agent'        => null,
			'sources'      => array(),
			'started_at'   => null,
			'completed_at' => null,
			'skipped_at'   => null,
		);
	}

	/**
	 * Write the state back and return what the wizard should read.
	 *
	 * @param array<string, mixed> $stored State.
	 * @return array<string, mixed>
	 */
	private function save( array $stored ): array {
		update_option( self::OPTION, $stored, false );

		return $this->current();
	}

	/**
	 * Now, as an ISO string.
	 *
	 * @return string
	 */
	private function now(): string {
		return $this->clock->now()->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'c' );
	}
}
