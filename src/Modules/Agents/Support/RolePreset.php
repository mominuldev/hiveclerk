<?php
/**
 * A job description a clerk can be hired into.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Agents\Support;

/**
 * One role, with everything a new clerk needs to start work.
 *
 * A preset is a starting point, never a constraint: it fills the fields
 * once, at hire time, and the operator owns them afterwards. Presets that
 * keep reasserting themselves are the reason nobody trusts a template.
 */
final class RolePreset {

	/**
	 * Construct.
	 *
	 * @param string               $key          Stored value of role_preset.
	 * @param string               $label        Name shown in the editor.
	 * @param string               $summary      One line describing the job.
	 * @param string               $instructions The written job description.
	 * @param string               $greeting     Opening line.
	 * @param string               $fallback     Shown when the clerk cannot answer.
	 * @param array<string, mixed> $guardrails   Suggested limits.
	 * @param array<string, mixed> $personality  Suggested tone dials.
	 * @param bool                 $needsSources Whether the role is useless without knowledge.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly string $summary,
		public readonly string $instructions,
		public readonly string $greeting,
		public readonly string $fallback,
		public readonly array $guardrails = array(),
		public readonly array $personality = array(),
		public readonly bool $needsSources = true,
	) {
	}

	/**
	 * The shape the editor reads.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'key'             => $this->key,
			'label'           => $this->label,
			'summary'         => $this->summary,
			'instructions'    => $this->instructions,
			'greeting'        => $this->greeting,
			'fallback'        => $this->fallback,
			'guardrails'      => $this->guardrails,
			'personality'     => $this->personality,
			'needs_knowledge' => $this->needsSources,
		);
	}
}
