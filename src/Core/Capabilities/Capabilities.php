<?php
/**
 * Capability constants.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Capabilities;

/**
 * Custom capabilities.
 *
 * Deliberately granular so a shop manager can supervise conversations
 * without ever reaching the screen holding the model API key.
 */
final class Capabilities {

	public const MANAGE_AGENTS        = 'hiveclerk_manage_agents';
	public const VIEW_CONVERSATIONS   = 'hiveclerk_view_conversations';
	public const MANAGE_CONVERSATIONS = 'hiveclerk_manage_conversations';
	public const MANAGE_LEADS         = 'hiveclerk_manage_leads';
	public const MANAGE_KNOWLEDGE     = 'hiveclerk_manage_knowledge';
	public const MANAGE_INTEGRATIONS  = 'hiveclerk_manage_integrations';
	public const MANAGE_WORKFLOWS     = 'hiveclerk_manage_workflows';
	public const MANAGE_SETTINGS      = 'hiveclerk_manage_settings';

	/**
	 * Every capability.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array(
			self::MANAGE_AGENTS,
			self::VIEW_CONVERSATIONS,
			self::MANAGE_CONVERSATIONS,
			self::MANAGE_LEADS,
			self::MANAGE_KNOWLEDGE,
			self::MANAGE_INTEGRATIONS,
			self::MANAGE_WORKFLOWS,
			self::MANAGE_SETTINGS,
		);
	}

	/**
	 * Default role assignments.
	 *
	 * Shop managers get operational access but never settings, because
	 * settings holds the API key that spends the owner's money.
	 *
	 * Workflows are administrator-only for a related reason. A workflow
	 * can post to a webhook, push a lead to a CRM and start an email
	 * sequence — every outbound capability the role map deliberately
	 * withholds from a shop manager, reachable by building a graph. A
	 * builder gated on `manage_leads` would have been a way around the
	 * `manage_integrations` gate rather than a separate feature.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function roleMap(): array {
		return array(
			'administrator' => self::all(),
			'editor'        => array(
				self::VIEW_CONVERSATIONS,
				self::MANAGE_KNOWLEDGE,
			),
			'shop_manager'  => array(
				self::MANAGE_AGENTS,
				self::VIEW_CONVERSATIONS,
				self::MANAGE_CONVERSATIONS,
				self::MANAGE_LEADS,
				self::MANAGE_KNOWLEDGE,
			),
		);
	}
}
