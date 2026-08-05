<?php
/**
 * Connector metadata.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Integration;

/**
 * Everything the integrations grid needs to draw a card before anything
 * is connected.
 *
 * Served rather than hard-coded in TypeScript. A third-party connector
 * registered through `hiveclerk/crm/connectors` gets a card on the grid
 * without shipping a line of front-end code, which is the whole point of
 * the filter being there.
 */
final readonly class ConnectorDescriptor {

	public const KIND_CRM          = 'crm';
	public const KIND_NOTIFICATION = 'notification';

	public const AUTH_NONE  = 'none';
	public const AUTH_LOCAL = 'local';
	public const AUTH_TOKEN = 'token';
	public const AUTH_URL   = 'url';
	public const AUTH_OAUTH = 'oauth';

	/**
	 * Construct.
	 *
	 * @param string                        $id       Machine identifier, stored in the provider column.
	 * @param string                        $name     Product name, not translated — it is a trademark.
	 * @param string                        $kind     KIND_* constant.
	 * @param string                        $auth     AUTH_* constant.
	 * @param string                        $summary  One sentence on what connecting achieves.
	 * @param bool                          $isPro    Whether a paid licence is required (FR-CRM-10).
	 * @param array<int, ConnectorSetting>  $settings Fields the connect form asks for.
	 * @param string                        $docsUrl  Where the operator goes when stuck.
	 */
	public function __construct(
		public string $id,
		public string $name,
		public string $kind = self::KIND_CRM,
		public string $auth = self::AUTH_TOKEN,
		public string $summary = '',
		public bool $isPro = true,
		public array $settings = array(),
		public string $docsUrl = ''
	) {
	}

	/**
	 * Wire form.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'       => $this->id,
			'name'     => $this->name,
			'kind'     => $this->kind,
			'auth'     => $this->auth,
			'summary'  => $this->summary,
			'is_pro'   => $this->isPro,
			'settings' => array_map(
				static fn ( ConnectorSetting $setting ): array => $setting->toArray(),
				$this->settings
			),
			'docs_url' => $this->docsUrl,
		);
	}
}
