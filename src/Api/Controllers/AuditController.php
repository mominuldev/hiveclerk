<?php
/**
 * Audit log endpoints.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Api\Controllers;

use Hiveclerk\Api\AbstractController;
use Hiveclerk\Api\Response\ApiResponse;
use Hiveclerk\Core\Capabilities\Capabilities;
use Hiveclerk\Domain\Audit\AuditEntry;
use Hiveclerk\Domain\Audit\AuditRepositoryInterface;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Reads the audit log.
 *
 * Read-only by design. There is no endpoint that edits or deletes a
 * single entry, because a log that can be selectively rewritten proves
 * nothing — the whole value of the record is that the person who made a
 * change could not have removed the evidence of it afterwards.
 */
final class AuditController extends AbstractController {

	/**
	 * Construct.
	 *
	 * @param AuditRepositoryInterface $audit Audit storage.
	 */
	public function __construct(
		private readonly AuditRepositoryInterface $audit
	) {
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/settings/audit-log',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => $this->requires( Capabilities::MANAGE_SETTINGS ),
				'args'                => array_merge(
					$this->collectionArgs(),
					array(
						'action'  => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'user_id' => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/settings/audit-log/actions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'actions' ),
				'permission_callback' => $this->requires( Capabilities::MANAGE_SETTINGS ),
			)
		);
	}

	/**
	 * A page of the log.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$pagination = $this->pagination( $request );
		$action     = $this->stringParam( $request, 'action' );
		$userParam  = $request->get_param( 'user_id' );
		$userId     = is_numeric( $userParam ) && (int) $userParam > 0 ? (int) $userParam : null;

		$entries = $this->audit->paginate( $pagination, $action, $userId );

		return ApiResponse::collection(
			array_map( self::present( ... ), $entries ),
			$pagination,
			$this->audit->total( $action, $userId )
		);
	}

	/**
	 * Action names present in the log, for the filter control.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function actions( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return ApiResponse::ok( $this->audit->actions() );
	}

	/**
	 * Wire form for one entry.
	 *
	 * The display name is resolved here rather than stored on the record.
	 * Storing it would freeze a name that later changes; resolving it
	 * means a renamed account reads correctly throughout its history, and
	 * a deleted one degrades to its id rather than to a blank.
	 *
	 * @param AuditEntry $entry Entry.
	 * @return array<string, mixed>
	 */
	private static function present( AuditEntry $entry ): array {
		return array(
			'action'      => $entry->action,
			'user_id'     => $entry->userId,
			'user'        => self::userLabel( $entry->userId ),
			'object_type' => $entry->objectType,
			'object_id'   => $entry->objectId,
			'changes'     => $entry->changes,
			'sensitive'   => $entry->isSensitive(),
			'created_at'  => $entry->createdAt,
		);
	}

	/**
	 * A readable name for a user id.
	 *
	 * @param int|null $userId User id.
	 * @return string
	 */
	private static function userLabel( ?int $userId ): string {
		if ( null === $userId ) {
			return 'System';
		}

		$user = get_userdata( $userId );

		if ( false === $user ) {
			return sprintf( 'Deleted user #%d', $userId );
		}

		return '' !== $user->display_name ? $user->display_name : $user->user_login;
	}
}
