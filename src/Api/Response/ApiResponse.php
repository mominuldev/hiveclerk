<?php
/**
 * Response envelope.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Api\Response;

use Hiveclerk\Domain\Shared\Pagination;
use WP_Error;
use WP_REST_Response;

/**
 * Builds the response shape documented in the API specification.
 */
final class ApiResponse {

	/**
	 * A successful response.
	 *
	 * @param mixed                $data    Payload.
	 * @param array<string, mixed> $meta    Optional metadata.
	 * @param int                  $status  HTTP status.
	 * @param array<string, string> $headers Extra headers.
	 * @return WP_REST_Response
	 */
	public static function ok(
		mixed $data,
		array $meta = array(),
		int $status = 200,
		array $headers = array()
	): WP_REST_Response {
		$body = array( 'data' => $data );

		if ( array() !== $meta ) {
			$body['meta'] = $meta;
		}

		$response = new WP_REST_Response( $body, $status );

		foreach ( $headers as $name => $value ) {
			$response->header( $name, $value );
		}

		return $response;
	}

	/**
	 * A paginated collection.
	 *
	 * @param array<int, mixed>     $items      Items on this page.
	 * @param Pagination            $pagination Page request.
	 * @param int                   $total      Total matching rows.
	 * @param array<string, string> $headers    Extra headers.
	 * @return WP_REST_Response
	 */
	public static function collection(
		array $items,
		Pagination $pagination,
		int $total,
		array $headers = array()
	): WP_REST_Response {
		return self::ok(
			$items,
			array(
				'pagination' => array(
					'page'        => $pagination->page,
					'per_page'    => $pagination->perPage,
					'total'       => $total,
					'total_pages' => $pagination->totalPages( $total ),
				),
			),
			200,
			$headers
		);
	}

	/**
	 * An empty success.
	 *
	 * @return WP_REST_Response
	 */
	public static function noContent(): WP_REST_Response {
		return new WP_REST_Response( null, 204 );
	}

	/**
	 * An error carrying a stable machine code.
	 *
	 * The code matters more than the message: a client should react to
	 * hvc_licence_required without pattern-matching English prose.
	 *
	 * @param string                        $code    Stable error code.
	 * @param string                        $message Human-readable message.
	 * @param int                           $status  HTTP status.
	 * @param array<string, array<int, string>> $errors Field errors.
	 * @return WP_Error
	 */
	public static function error(
		string $code,
		string $message,
		int $status = 400,
		array $errors = array()
	): WP_Error {
		$data = array( 'status' => $status );

		if ( array() !== $errors ) {
			$data['errors'] = $errors;
		}

		return new WP_Error( $code, $message, $data );
	}
}
