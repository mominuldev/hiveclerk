<?php
/**
 * Which clerk serves a page, and what the widget is told about it.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Modules\Chat\Services;

use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Agent\PageContext;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Infrastructure\WordPress\PageContextFactory;

/**
 * The one place that decides what a visitor's browser is allowed to know.
 *
 * Two callers need this and they must not disagree: the REST bootstrap
 * route, and the page-level enqueue that inlines the same payload to save
 * a round trip. Two copies of "which fields are public" is how a private
 * field eventually becomes a public one.
 *
 * Everything here is deliberately dull — a name, a colour, a greeting.
 * Instructions, guardrails, model choice, budget and source list all stay
 * on the server. The widget cannot leak what it was never given.
 */
final class WidgetConfig {

	/**
	 * Construct.
	 *
	 * @param AgentRepositoryInterface $agents   Clerk storage.
	 * @param PageContextFactory       $pages    Describes the current page.
	 */
	public function __construct(
		private readonly AgentRepositoryInterface $agents,
		private readonly PageContextFactory $pages
	) {
	}

	/**
	 * The clerk that should serve this request, if any.
	 *
	 * Display rules decide (FR-CLK-07). Where several published clerks all
	 * accept the page, the oldest wins — a deterministic answer rather
	 * than a clever one, so that a visitor who reloads gets the same clerk
	 * and a support conversation about "which clerk answered" has an
	 * answer. Narrowing a clerk's rules is how an operator changes it.
	 *
	 * A clerk past its token budget is still selected. It answers with its
	 * owner's fallback message and can still take an email address, and a
	 * widget that vanishes mid-month tells the visitor nothing and the
	 * operator less.
	 *
	 * @param Uuid|null        $requested Specific clerk asked for.
	 * @param PageContext|null $context   The page being served; built from the request when absent.
	 * @return Agent|null
	 */
	public function select( ?Uuid $requested = null, ?PageContext $context = null ): ?Agent {
		$context ??= $this->pages->current();

		if ( null !== $requested ) {
			$agent = $this->agents->findByUuid( $requested );

			// An explicitly requested clerk still has to be on duty, but
			// its display rules are not applied: asking for a named clerk
			// is what a shortcode does, and the page it was placed on is
			// the operator's answer to "where should this appear".
			return ( null !== $agent && $agent->status->isServing() ) ? $agent : null;
		}

		foreach ( $this->agents->published() as $agent ) {
			if ( $agent->appearsOn( $context ) ) {
				return $agent;
			}
		}

		return null;
	}

	/**
	 * The payload the widget boots from.
	 *
	 * @param Agent $agent The clerk.
	 * @return array<string, mixed>
	 */
	public function payload( Agent $agent ): array {
		$widget = $agent->widgetConfig;

		$config = array(
			'agent'        => array(
				'uuid'          => $agent->uuid->value,
				'name'          => $agent->name,
				'avatar_url'    => $agent->avatarUrl,
				'greeting'      => $agent->greeting,
				'widget_config' => array(
					'position' => $this->position( $widget ),
					'accent'   => $this->accent( $widget ),
					'radius'   => $this->radius( $widget ),
					'theme'    => $this->theme( $widget ),
					'launcher' => $this->text( $widget, 'launcher_label', 40 ) ?? '',
					'subtitle' => $this->text( $widget, 'subtitle', 60 ) ?? '',
				),
				'locale'        => get_locale(),
				'branding'      => array(
					'show_badge' => (bool) ( $widget['show_badge'] ?? true ),
					'label'      => 'Powered by Hiveclerk',
				),
			),
			'capabilities' => array(
				// Streaming is advertised as available, not as working. The
				// widget still probes: this flag says the endpoint exists,
				// and only the visitor's own connection can say whether
				// anything between here and there buffers it.
				'streaming' => true,
				'handoff'   => true,
				'feedback'  => true,
			),
			'consent'      => array(
				'required' => false,
				'text'     => null,
			),
		);

		/**
		 * Filter the configuration handed to the widget.
		 *
		 * @param array<string, mixed> $config Public configuration.
		 * @param Agent                $agent  The clerk.
		 */
		$filtered = apply_filters( 'hiveclerk/widget/config', $config, $agent );

		return is_array( $filtered ) ? $filtered : $config;
	}

	/**
	 * Corner the launcher sits in.
	 *
	 * @param array<string, mixed> $widget Stored widget config.
	 * @return string
	 */
	private function position( array $widget ): string {
		$value = $widget['position'] ?? 'bottom-right';
		$known = array( 'bottom-right', 'bottom-left' );

		return is_string( $value ) && in_array( $value, $known, true ) ? $value : 'bottom-right';
	}

	/**
	 * Accent colour, validated as a hex triple or sextet.
	 *
	 * Validated rather than escaped because it is interpolated into a CSS
	 * custom property. An unchecked value there is a style-injection hole
	 * that survives every HTML escape in the widget.
	 *
	 * @param array<string, mixed> $widget Stored widget config.
	 * @return string
	 */
	private function accent( array $widget ): string {
		$value = $widget['accent'] ?? null;

		if ( is_string( $value ) && 1 === preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ) {
			return $value;
		}

		return '#4F46E5';
	}

	/**
	 * Corner radius in pixels.
	 *
	 * @param array<string, mixed> $widget Stored widget config.
	 * @return int
	 */
	private function radius( array $widget ): int {
		$value = $widget['radius'] ?? 16;

		return is_numeric( $value ) ? max( 0, min( 32, (int) $value ) ) : 16;
	}

	/**
	 * Colour scheme.
	 *
	 * @param array<string, mixed> $widget Stored widget config.
	 * @return string
	 */
	private function theme( array $widget ): string {
		$value = $widget['theme'] ?? 'auto';
		$known = array( 'auto', 'light', 'dark' );

		return is_string( $value ) && in_array( $value, $known, true ) ? $value : 'auto';
	}

	/**
	 * A short configured string, or null.
	 *
	 * @param array<string, mixed> $widget Stored widget config.
	 * @param string               $key    Field name.
	 * @param int                  $limit  Maximum characters.
	 * @return string|null
	 */
	private function text( array $widget, string $key, int $limit ): ?string {
		$value = $widget[ $key ] ?? null;

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		return mb_substr( trim( $value ), 0, $limit );
	}
}
