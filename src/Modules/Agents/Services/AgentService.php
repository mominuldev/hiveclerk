<?php
/**
 * Hiring, configuring and retiring clerks.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Agents\Services;

use Hiveclerk\Api\ErrorCode;
use Hiveclerk\Core\Audit\AuditLogger;
use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Agent\AgentStatus;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Modules\Agents\Support\AgentException;

/**
 * The lifecycle of a clerk (FR-CLK-01, 03, 09).
 *
 * Everything that changes a clerk goes through here, and everything that
 * goes through here is audited. That is not ceremony: a clerk's
 * instructions decide what it tells customers and its budget decides what
 * it costs, so "who changed this and when" is the first question asked
 * when either one surprises somebody.
 *
 * Input arriving here has already been sanitised at the HTTP boundary.
 * What this class owns is the rules that are not about the shape of the
 * data — a unique slug, a publishable licence, a duplicate that starts as
 * a draft — because those are the ones that would otherwise be
 * reimplemented differently by every caller.
 */
final class AgentService {

	/**
	 * Longest name accepted. The column holds 191.
	 */
	private const MAX_NAME = 120;

	/**
	 * Construct.
	 *
	 * @param AgentRepositoryInterface $agents  Clerk storage.
	 * @param PresetLibrary            $presets Role presets.
	 * @param PublishPolicy            $policy  Publishing limits.
	 * @param BudgetGuard              $budget  Budget roll-over.
	 * @param AuditLogger              $audit   Audit log.
	 */
	public function __construct(
		private readonly AgentRepositoryInterface $agents,
		private readonly PresetLibrary $presets,
		private readonly PublishPolicy $policy,
		private readonly BudgetGuard $budget,
		private readonly AuditLogger $audit
	) {
	}

	/**
	 * Hire a clerk.
	 *
	 * A new clerk is always a draft, whatever was asked for. Publishing is
	 * a separate, audited act, and a clerk that goes on duty as a side
	 * effect of being created is one nobody reviewed.
	 *
	 * @param array<string, mixed> $input Sanitised fields.
	 * @return Agent
	 *
	 * @throws AgentException When the role is unknown.
	 */
	public function create( array $input ): Agent {
		$role   = is_string( $input['role_preset'] ?? null ) ? (string) $input['role_preset'] : PresetLibrary::CUSTOM;
		$preset = $this->presets->get( $role );

		if ( null === $preset ) {
			throw new AgentException(
				ErrorCode::VALIDATION_FAILED,
				__( 'That is not a role this installation offers.', 'hiveclerk' )
			);
		}

		$name = $this->name( $input, $preset->label );

		$agent = new Agent(
			id: null,
			uuid: Uuid::generate(),
			name: $name,
			slug: $this->uniqueSlug( $name ),
			rolePreset: $role,
			status: AgentStatus::Draft,
			greeting: $preset->greeting,
			fallbackMessage: $preset->fallback,
			instructions: $preset->instructions,
			guardrails: $preset->guardrails,
			personality: $preset->personality,
		);

		$agent = $this->apply( $agent, $input );
		$agent = $this->agents->save( $agent );

		if ( null !== $agent->id && isset( $input['source_ids'] ) && is_array( $input['source_ids'] ) ) {
			$this->agents->syncSources( $agent->id, array_map( 'intval', $input['source_ids'] ) );
		}

		$this->audit->record(
			'agent.created',
			array(
				'name' => $agent->name,
				'role' => $agent->rolePreset,
			),
			'agent',
			$agent->id
		);

		/**
		 * Fires after a clerk is hired.
		 *
		 * @param Agent $agent The new clerk.
		 */
		do_action( 'hiveclerk/agent/created', $agent );

		return $agent;
	}

	/**
	 * Change a clerk's configuration.
	 *
	 * @param Agent                $agent The clerk.
	 * @param array<string, mixed> $input Sanitised fields.
	 * @return Agent
	 *
	 * @throws AgentException When a field is unusable.
	 */
	public function update( Agent $agent, array $input ): Agent {
		$before = $this->fingerprint( $agent );
		$agent  = $this->apply( $agent, $input );

		if ( isset( $input['name'] ) ) {
			$name = $this->name( $input, $agent->name );

			// The slug follows the name only while the clerk is a draft.
			// After publication it is in the widget's cached configuration
			// and in whatever the operator embedded on their site, and
			// silently changing it breaks both.
			if ( $name !== $agent->name && AgentStatus::Draft === $agent->status ) {
				$agent->slug = $this->uniqueSlug( $name, $agent->id );
			}

			$agent->name = $name;
		}

		$agent = $this->agents->save( $agent );

		if ( null !== $agent->id && isset( $input['source_ids'] ) && is_array( $input['source_ids'] ) ) {
			$this->agents->syncSources( $agent->id, array_map( 'intval', $input['source_ids'] ) );
		}

		$changed = array_keys( array_diff_assoc( $this->fingerprint( $agent ), $before ) );

		// The field names are logged, never the values. Instructions are a
		// page of prose and a budget is a number; what an auditor needs is
		// that the guardrails moved on Tuesday, not a diff of the copy.
		$this->audit->record(
			'agent.updated',
			array(
				'name'    => $agent->name,
				'changed' => $changed,
			),
			'agent',
			$agent->id
		);

		return $agent;
	}

	/**
	 * Put a clerk on duty.
	 *
	 * @param Agent $agent The clerk.
	 * @return Agent
	 *
	 * @throws AgentException When the licence or the configuration refuses.
	 */
	public function publish( Agent $agent ): Agent {
		$missing = $this->blockers( $agent );

		if ( array() !== $missing ) {
			throw new AgentException(
				ErrorCode::VALIDATION_FAILED,
				$missing[0]
			);
		}

		if ( ! $this->policy->allowsPublishing( $agent ) ) {
			throw new AgentException(
				ErrorCode::LICENCE_REQUIRED,
				$this->policy->refusalMessage(),
				402
			);
		}

		$agent->status = AgentStatus::Published;
		$agent         = $this->agents->save( $agent );

		$this->audit->record( 'agent.published', array( 'name' => $agent->name ), 'agent', $agent->id );

		/**
		 * Fires when a clerk goes on duty.
		 *
		 * @param Agent $agent The clerk.
		 */
		do_action( 'hiveclerk/agent/published', $agent );

		return $agent;
	}

	/**
	 * Take a clerk off duty.
	 *
	 * @param Agent $agent The clerk.
	 * @return Agent
	 */
	public function pause( Agent $agent ): Agent {
		$agent->status = AgentStatus::Paused;
		$agent         = $this->agents->save( $agent );

		$this->audit->record( 'agent.paused', array( 'name' => $agent->name ), 'agent', $agent->id );

		/**
		 * Fires when a clerk is taken off duty.
		 *
		 * @param Agent $agent The clerk.
		 */
		do_action( 'hiveclerk/agent/paused', $agent );

		return $agent;
	}

	/**
	 * Copy a clerk, including its knowledge and its rules.
	 *
	 * The copy is a draft with a fresh budget counter. Duplicating a clerk
	 * that is 90% through its month and having the copy inherit that is a
	 * clerk which stops answering on its first day for reasons its owner
	 * cannot see.
	 *
	 * @param Agent $agent The clerk to copy.
	 * @return Agent
	 */
	public function duplicate( Agent $agent ): Agent {
		/* translators: %s: name of the clerk being copied. */
		$name = sprintf( __( '%s (copy)', 'hiveclerk' ), $agent->name );
		$name = mb_substr( $name, 0, self::MAX_NAME );

		$copy = new Agent(
			id: null,
			uuid: Uuid::generate(),
			name: $name,
			slug: $this->uniqueSlug( $name ),
			rolePreset: $agent->rolePreset,
			status: AgentStatus::Draft,
			greeting: $agent->greeting,
			fallbackMessage: $agent->fallbackMessage,
			instructions: $agent->instructions,
			modelConfig: $agent->modelConfig,
			guardrails: $agent->guardrails,
			tokenBudget: $agent->tokenBudget,
			tokensUsedMonth: 0,
			avatarUrl: $agent->avatarUrl,
			widgetConfig: $agent->widgetConfig,
			personality: $agent->personality,
			displayRulesRaw: $agent->displayRulesRaw,
			leadConfig: $agent->leadConfig,
		);

		$copy = $this->agents->save( $copy );

		if ( null !== $copy->id && null !== $agent->id ) {
			$this->agents->syncSources( $copy->id, $this->agents->sourceIds( $agent->id ) );
		}

		$this->audit->record(
			'agent.duplicated',
			array(
				'name' => $copy->name,
				'from' => $agent->name,
			),
			'agent',
			$copy->id
		);

		return $copy;
	}

	/**
	 * Retire a clerk.
	 *
	 * A soft delete. Conversations reference the clerk that handled them,
	 * and a hard delete would leave months of transcripts attributed to
	 * nobody — which is the state an operator discovers while trying to
	 * work out what a customer was told.
	 *
	 * @param Agent $agent The clerk.
	 * @return void
	 */
	public function delete( Agent $agent ): void {
		if ( null === $agent->id ) {
			return;
		}

		$this->agents->delete( $agent->id );

		$this->audit->record( 'agent.deleted', array( 'name' => $agent->name ), 'agent', $agent->id );

		/**
		 * Fires when a clerk is retired.
		 *
		 * @param Agent $agent The clerk.
		 */
		do_action( 'hiveclerk/agent/deleted', $agent );
	}

	/**
	 * Read a clerk with its budget period brought up to date.
	 *
	 * @param int $id Storage id.
	 * @return Agent|null
	 */
	public function find( int $id ): ?Agent {
		$agent = $this->agents->find( $id );

		return null === $agent ? null : $this->budget->rollOver( $agent );
	}

	/**
	 * Read a clerk by public identifier, budget brought up to date.
	 *
	 * @param Uuid $uuid Public identifier.
	 * @return Agent|null
	 */
	public function findByUuid( Uuid $uuid ): ?Agent {
		$agent = $this->agents->findByUuid( $uuid );

		return null === $agent ? null : $this->budget->rollOver( $agent );
	}

	/**
	 * What stands between this clerk and going on duty.
	 *
	 * Returned as a list rather than a boolean because the editor shows
	 * them: "not ready" with no reason is the least useful message a
	 * publish button can give.
	 *
	 * @param Agent $agent The clerk.
	 * @return array<int, string>
	 */
	public function blockers( Agent $agent ): array {
		$blockers = array();

		if ( '' === trim( $agent->name ) ) {
			$blockers[] = __( 'Give the clerk a name before putting it on duty.', 'hiveclerk' );
		}

		if ( null === $agent->provider() || null === $agent->model() ) {
			$blockers[] = __( 'Choose the model this clerk answers with. Nothing can be published without one.', 'hiveclerk' );
		}

		if ( '' === trim( (string) $agent->instructions ) ) {
			$blockers[] = __( 'Write what this clerk does. A clerk with no job description answers like a search box.', 'hiveclerk' );
		}

		return $blockers;
	}

	/**
	 * A slug nothing else holds.
	 *
	 * @param string   $name     Clerk name.
	 * @param int|null $exceptId Clerk allowed to keep the slug.
	 * @return string
	 */
	public function uniqueSlug( string $name, ?int $exceptId = null ): string {
		$base = sanitize_title( $name );

		if ( '' === $base ) {
			$base = 'clerk';
		}

		$slug   = $base;
		$suffix = 1;

		// Bounded. An unbounded loop against a unique key is a hang under
		// exactly the condition it exists to survive.
		while ( $this->agents->slugTaken( $slug, $exceptId ) && $suffix < 100 ) {
			++$suffix;
			$slug = $base . '-' . $suffix;
		}

		return $slug;
	}

	/**
	 * Copy the writable fields from a request onto a clerk.
	 *
	 * Every field is optional and absent means unchanged, so the editor
	 * can save one tab without sending the other five — and, more
	 * importantly, cannot blank a field it never rendered.
	 *
	 * @param Agent                $agent The clerk.
	 * @param array<string, mixed> $input Sanitised fields.
	 * @return Agent
	 */
	private function apply( Agent $agent, array $input ): Agent {
		$strings = array(
			'greeting'         => 'greeting',
			'fallback_message' => 'fallbackMessage',
			'instructions'     => 'instructions',
			'avatar_url'       => 'avatarUrl',
		);

		foreach ( $strings as $key => $property ) {
			if ( array_key_exists( $key, $input ) ) {
				$value              = is_string( $input[ $key ] ) ? trim( $input[ $key ] ) : '';
				$agent->{$property} = '' === $value ? null : $value;
			}
		}

		$arrays = array(
			'model_config'  => 'modelConfig',
			'guardrails'    => 'guardrails',
			'widget_config' => 'widgetConfig',
			'personality'   => 'personality',
			'display_rules' => 'displayRulesRaw',
			'lead_config'   => 'leadConfig',
		);

		foreach ( $arrays as $key => $property ) {
			if ( isset( $input[ $key ] ) && is_array( $input[ $key ] ) ) {
				$agent->{$property} = $input[ $key ];
			}
		}

		if ( array_key_exists( 'token_budget', $input ) ) {
			$budget = $input['token_budget'];

			// Zero is not a budget of zero, it is the absence of one. A
			// clerk saved with an empty field must answer, not refuse
			// every message it ever receives.
			$agent->tokenBudget = is_numeric( $budget ) && (int) $budget > 0 ? (int) $budget : null;
		}

		if ( isset( $input['role_preset'] ) && is_string( $input['role_preset'] ) && $this->presets->has( $input['role_preset'] ) ) {
			$agent->rolePreset = $input['role_preset'];
		}

		return $agent;
	}

	/**
	 * A clerk's name from the request, or a fallback.
	 *
	 * @param array<string, mixed> $input    Sanitised fields.
	 * @param string               $fallback Used when the field is blank.
	 * @return string
	 */
	private function name( array $input, string $fallback ): string {
		$name = is_string( $input['name'] ?? null ) ? trim( (string) $input['name'] ) : '';

		return '' === $name ? $fallback : mb_substr( $name, 0, self::MAX_NAME );
	}

	/**
	 * A comparable summary of the configuration, for the audit record.
	 *
	 * Hashed rather than stored: the audit log is written on every save of
	 * every clerk, and holding two copies of a page of instructions in it
	 * to answer "did this change" is a table that grows faster than the
	 * conversations it exists to explain.
	 *
	 * @param Agent $agent The clerk.
	 * @return array<string, string>
	 */
	private function fingerprint( Agent $agent ): array {
		return array(
			'name'          => md5( $agent->name ),
			'instructions'  => md5( (string) $agent->instructions ),
			'greeting'      => md5( (string) $agent->greeting ),
			'fallback'      => md5( (string) $agent->fallbackMessage ),
			'model_config'  => md5( (string) wp_json_encode( $agent->modelConfig ) ),
			'guardrails'    => md5( (string) wp_json_encode( $agent->guardrails ) ),
			'display_rules' => md5( (string) wp_json_encode( $agent->displayRulesRaw ) ),
			'widget_config' => md5( (string) wp_json_encode( $agent->widgetConfig ) ),
			'personality'   => md5( (string) wp_json_encode( $agent->personality ) ),
			'token_budget'  => md5( (string) $agent->tokenBudget ),
		);
	}
}
