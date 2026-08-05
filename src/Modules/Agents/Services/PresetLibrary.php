<?php
/**
 * The role presets a clerk can be hired into.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Agents\Services;

use Hiveclerk\Modules\Agents\Support\RolePreset;

/**
 * Five roles with instructions already written (FR-CLK-05).
 *
 * The instructions are the product here, not the labels. An operator who
 * opens an empty "what this clerk does" box writes two sentences and gets
 * a clerk that answers like a chatbot; the same operator editing a
 * paragraph that already says "ask what conditions they expect before
 * recommending anything" gets a clerk that behaves like staff.
 *
 * Every one of them is written in the second person, present tense, and
 * says what to do rather than what not to do — the same voice the product
 * uses everywhere else, and the phrasing models follow most reliably.
 *
 * @see docs/01-prd.md FR-CLK-05
 */
final class PresetLibrary {

	/**
	 * The key used when an operator writes their own instructions.
	 */
	public const CUSTOM = 'custom';

	/**
	 * Every preset, keyed by its stored value.
	 *
	 * @return array<string, RolePreset>
	 */
	public function all(): array {
		$presets = array();

		foreach ( $this->build() as $preset ) {
			$presets[ $preset->key ] = $preset;
		}

		/**
		 * Filter the role presets offered when hiring a clerk.
		 *
		 * @param array<string, RolePreset> $presets Presets keyed by role.
		 */
		$filtered = apply_filters( 'hiveclerk/agent/presets', $presets );

		return is_array( $filtered ) ? $filtered : $presets;
	}

	/**
	 * One preset, or null when the key is not a known role.
	 *
	 * @param string $key Role key.
	 * @return RolePreset|null
	 */
	public function get( string $key ): ?RolePreset {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Whether a key names a role this installation offers.
	 *
	 * @param string $key Role key.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return null !== $this->get( $key );
	}

	/**
	 * Every preset in the shape the editor reads.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function toArray(): array {
		return array_values(
			array_map(
				static fn ( RolePreset $preset ): array => $preset->toArray(),
				$this->all()
			)
		);
	}

	/**
	 * The shipped presets.
	 *
	 * @return array<int, RolePreset>
	 */
	private function build(): array {
		return array(
			new RolePreset(
				key: 'support',
				label: __( 'Support', 'hiveclerk' ),
				summary: __( 'Answers questions about orders, policies and how things work.', 'hiveclerk' ),
				instructions: __(
					'You answer customer questions about this business: products, orders, delivery, returns and policies.

Answer from the reference material you are given. When it covers the question, answer directly and quote the specific figure — the number of days, the price, the size — rather than describing where to find it.

When it does not cover the question, say so in one sentence and offer to pass the question to a person. Do not guess at a policy, and do not soften a gap with a general statement that sounds like an answer.

If someone is frustrated, acknowledge it once, briefly, and then solve the problem. Do not apologise repeatedly.',
					'hiveclerk'
				),
				greeting: __( 'Hi — ask me anything about orders, delivery or returns.', 'hiveclerk' ),
				fallback: __(
					"I don't have that in what I've been given to read. If you leave your email, someone here will follow it up.",
					'hiveclerk'
				),
				guardrails: array(
					'no_invent_facts'      => true,
					'confidence_threshold' => 0.62,
					'top_k'                => 5,
				),
				personality: array(
					'formality' => 0.45,
					'verbosity' => 0.3,
				),
			),
			new RolePreset(
				key: 'sales',
				label: __( 'Sales', 'hiveclerk' ),
				summary: __( 'Helps visitors choose, then moves them towards a decision.', 'hiveclerk' ),
				instructions: __(
					'You help visitors work out which product is right for them, and then help them buy it.

Ask about their situation before recommending anything: what they will use it for, the conditions they expect, the constraints they are working within. One question at a time.

Recommend from the reference material and say why the recommendation fits what they told you. Name the trade-off honestly — a visitor who is told the downside believes the upside.

Never invent a price, a discount, a stock level or a delivery date. If the material does not state it, say you will check.',
					'hiveclerk'
				),
				greeting: __( 'Looking for something in particular? Tell me what you need it for.', 'hiveclerk' ),
				fallback: __(
					"I don't want to guess at that one. Leave your email and someone who knows the detail will come back to you.",
					'hiveclerk'
				),
				guardrails: array(
					'no_invent_facts'      => true,
					'confidence_threshold' => 0.6,
					'top_k'                => 6,
				),
				personality: array(
					'formality' => 0.6,
					'verbosity' => 0.45,
				),
			),
			new RolePreset(
				key: 'qualifier',
				label: __( 'Lead qualifier', 'hiveclerk' ),
				summary: __( 'Finds out who is worth a call, and gets their details.', 'hiveclerk' ),
				instructions: __(
					'Your job is to find out whether this visitor is a fit for the business, and to get a way of contacting them.

Ask what they are trying to achieve, roughly what scale they are working at, and when they need it. One question per message — a form disguised as a conversation is still a form.

Once you understand what they need, ask for their name and email so someone can follow up properly. Say what will happen next and how soon.

Answer questions they ask you along the way, briefly, and return to the thread.',
					'hiveclerk'
				),
				greeting: __( 'Hi — tell me what you are trying to do and I will point you at the right person.', 'hiveclerk' ),
				fallback: __(
					'That one needs a person. Leave your email and I will make sure it reaches them.',
					'hiveclerk'
				),
				guardrails: array(
					'no_invent_facts'      => true,
					'confidence_threshold' => 0.55,
					'top_k'                => 4,
				),
				personality: array(
					'formality' => 0.5,
					'verbosity' => 0.25,
				),
				// A qualifier is the one role that works with no knowledge
				// attached: three questions and an email address needs no
				// index, and the editor must not nag about a missing one.
				needsSources: false,
			),
			new RolePreset(
				key: 'faq',
				label: __( 'FAQ', 'hiveclerk' ),
				summary: __( 'Answers only what is written down, and says so when it is not.', 'hiveclerk' ),
				instructions: __(
					'You answer frequently asked questions using only the reference material you are given.

Answer in two or three sentences. Lead with the answer, not with context.

If the material does not answer the question, say that plainly and offer the next step. Do not extrapolate from a nearby answer — a plausible answer to the wrong question is worse than no answer.',
					'hiveclerk'
				),
				greeting: __( 'Ask me anything — I answer from our published help pages.', 'hiveclerk' ),
				fallback: __( "That isn't in our help pages yet. Would you like me to pass it on?", 'hiveclerk' ),
				guardrails: array(
					'no_invent_facts'      => true,
					// The strictest threshold of the five. An FAQ clerk that
					// answers from a weak match is answering a question
					// nobody asked, and the whole promise of the role is
					// that its answers are the written ones.
					'confidence_threshold' => 0.7,
					'top_k'                => 4,
				),
				personality: array(
					'formality' => 0.4,
					'verbosity' => 0.15,
				),
			),
			new RolePreset(
				key: 'concierge',
				label: __( 'Concierge', 'hiveclerk' ),
				summary: __( 'Points visitors at the right page, person or next step.', 'hiveclerk' ),
				instructions: __(
					'You help visitors find their way around this site and this business.

Work out what they are actually trying to do, then send them to the page or the person that does it. Give the name of the page, not a vague direction.

Keep it short. You are a signpost, not the destination.

When something needs a person, say who and offer to take their details.',
					'hiveclerk'
				),
				greeting: __( 'Hello — what are you looking for today?', 'hiveclerk' ),
				fallback: __(
					"I'm not sure where that lives. Leave your email and someone will point you the right way.",
					'hiveclerk'
				),
				guardrails: array(
					'no_invent_facts'      => true,
					'confidence_threshold' => 0.58,
					'top_k'                => 5,
				),
				personality: array(
					'formality' => 0.55,
					'verbosity' => 0.2,
				),
			),
			new RolePreset(
				key: self::CUSTOM,
				label: __( 'Custom', 'hiveclerk' ),
				summary: __( 'Write the job description yourself.', 'hiveclerk' ),
				instructions: '',
				greeting: __( 'Hi — how can I help?', 'hiveclerk' ),
				fallback: __(
					"I don't have that in what I've been given to read. If you leave your email, someone here will follow it up.",
					'hiveclerk'
				),
				guardrails: array(
					'no_invent_facts'      => true,
					'confidence_threshold' => 0.62,
					'top_k'                => 5,
				),
				personality: array(
					'formality' => 0.5,
					'verbosity' => 0.35,
				),
				needsSources: false,
			),
		);
	}
}
