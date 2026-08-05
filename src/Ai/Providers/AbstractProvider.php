<?php
/**
 * Shared provider behaviour.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai\Providers;

use Hiveclerk\Ai\Completion;
use Hiveclerk\Ai\CompletionRequest;
use Hiveclerk\Ai\Credentials;
use Hiveclerk\Ai\Http\HttpClientInterface;
use Hiveclerk\Ai\Http\HttpResponse;
use Hiveclerk\Ai\LlmProviderInterface;
use Hiveclerk\Ai\Pricing;
use Hiveclerk\Ai\PricingTable;
use Hiveclerk\Ai\ProviderException;
use Hiveclerk\Ai\StreamEvent;
use Hiveclerk\Ai\Streaming\SseFrame;
use Hiveclerk\Ai\Streaming\SseParser;
use Hiveclerk\Ai\Streaming\StreamState;
use Hiveclerk\Ai\Verification;

/**
 * Everything the five adapters do identically.
 *
 * What is genuinely provider-specific turns out to be small: the URL, the
 * auth header, the request shape, and how a frame maps to an event.
 * Everything else — error classification, stream driving, timing, cost
 * lookup — is the same work five times over, and duplicating it would
 * mean five places to fix the next boundary-condition bug in.
 */
abstract class AbstractProvider implements LlmProviderInterface {

	/**
	 * Bytes of an error body worth keeping.
	 *
	 * A provider returning an HTML error page from a misconfigured proxy
	 * can send a lot of it. The first kilobyte always contains whatever
	 * is diagnostic.
	 */
	private const MAX_ERROR_BODY = 1024;

	/**
	 * Construct.
	 *
	 * @param HttpClientInterface $http    HTTP transport.
	 * @param PricingTable        $pricing Price lookup.
	 */
	public function __construct(
		protected readonly HttpClientInterface $http,
		protected readonly PricingTable $pricing
	) {
	}

	/**
	 * Endpoint for a chat completion.
	 *
	 * @param Credentials       $credentials Credentials.
	 * @param CompletionRequest $request     Request.
	 * @param bool              $stream      Whether the reply will stream.
	 * @return string
	 */
	abstract protected function completionUrl(
		Credentials $credentials,
		CompletionRequest $request,
		bool $stream
	): string;

	/**
	 * Authentication and protocol headers.
	 *
	 * @param Credentials $credentials Credentials.
	 * @return array<string, string>
	 */
	abstract protected function headers( Credentials $credentials ): array;

	/**
	 * Request body in this provider's shape.
	 *
	 * @param CompletionRequest $request Request.
	 * @param bool              $stream  Whether to ask for a stream.
	 * @return array<string, mixed>
	 */
	abstract protected function payload( CompletionRequest $request, bool $stream ): array;

	/**
	 * Read a non-streamed response.
	 *
	 * @param array<string, mixed> $json    Decoded body.
	 * @param CompletionRequest    $request Original request.
	 * @return Completion
	 */
	abstract protected function parseCompletion( array $json, CompletionRequest $request ): Completion;

	/**
	 * Translate one stream frame into zero or more events.
	 *
	 * @param SseFrame    $frame Parsed frame.
	 * @param StreamState $state Running state, mutated in place.
	 * @return array<int, StreamEvent>
	 */
	abstract protected function translate( SseFrame $frame, StreamState $state ): array;

	/**
	 * Produce a complete reply.
	 *
	 * @param Credentials       $credentials Credentials.
	 * @param CompletionRequest $request     Request.
	 * @return Completion
	 *
	 * @throws ProviderException When the call fails.
	 */
	public function complete( Credentials $credentials, CompletionRequest $request ): Completion {
		$this->assertConfigured( $credentials );

		$started = microtime( true );

		$response = $this->http->request(
			'POST',
			$this->completionUrl( $credentials, $request, false ),
			$this->headers( $credentials ),
			$this->payload( $request, false ),
			$request->timeout
		);

		$this->guard( $response );

		return $this->parseCompletion( $response->json(), $request )
			->withLatency( self::elapsedMs( $started ) );
	}

	/**
	 * Produce a reply, emitting events as tokens arrive.
	 *
	 * @param Credentials                 $credentials Credentials.
	 * @param CompletionRequest           $request     Request.
	 * @param callable(StreamEvent): bool $onEvent     Event sink.
	 * @return void
	 */
	public function stream(
		Credentials $credentials,
		CompletionRequest $request,
		callable $onEvent
	): void {
		if ( ! $credentials->isPresent() ) {
			$onEvent( StreamEvent::error( 'No API key is configured for ' . $this->label() . '.' ) );

			return;
		}

		$parser  = new SseParser();
		$state   = new StreamState( $this->id(), $request->model );
		$started = microtime( true );

		// Kept only so a non-2xx response, whose body is an error object
		// rather than an event stream, can still be reported usefully.
		$raw = '';

		$status = $this->http->stream(
			'POST',
			$this->completionUrl( $credentials, $request, true ),
			$this->headers( $credentials ),
			$this->payload( $request, true ),
			$request->timeout,
			function ( string $chunk ) use ( $parser, $state, $onEvent, &$raw ): bool {
				if ( strlen( $raw ) < self::MAX_ERROR_BODY ) {
					$raw .= $chunk;
				}

				foreach ( $parser->feed( $chunk ) as $frame ) {
					foreach ( $this->translate( $frame, $state ) as $event ) {
						if ( false === $onEvent( $event ) ) {
							return false;
						}
					}
				}

				return true;
			}
		);

		foreach ( $parser->flush() as $frame ) {
			foreach ( $this->translate( $frame, $state ) as $event ) {
				$onEvent( $event );
			}
		}

		if ( $status < 200 || $status >= 300 ) {
			$onEvent(
				StreamEvent::error(
					$this->errorMessage( $status, $raw ),
					ProviderException::isRetryableStatus( $status )
				)
			);

			return;
		}

		// A provider that closed cleanly without a terminal frame still
		// produced text, and the caller needs the usage figures either
		// way. Synthesising the done event here means no caller has to
		// handle "the stream just stopped".
		if ( ! $state->finished ) {
			$onEvent( StreamEvent::done( $state->toCompletion( self::elapsedMs( $started ) ) ) );
		}
	}

	/**
	 * Check credentials by listing models.
	 *
	 * Listing rather than completing: it is free, it is fast, and it
	 * proves the same thing a completion would — that the key is valid
	 * and the account is reachable — without spending the customer's
	 * money to find out whether their key works.
	 *
	 * @param Credentials $credentials Credentials.
	 * @return Verification
	 */
	public function verify( Credentials $credentials ): Verification {
		if ( ! $credentials->isPresent() ) {
			return Verification::fail( 'Enter an API key first.' );
		}

		$started = microtime( true );

		try {
			$models = $this->models( $credentials );
		} catch ( ProviderException $e ) {
			return Verification::fail( $e->getMessage() );
		}

		if ( array() === $models ) {
			return Verification::fail(
				'The key was accepted but no models are available to it. Check the account has model access enabled.'
			);
		}

		return Verification::pass( count( $models ), self::elapsedMs( $started ) );
	}

	/**
	 * Published price for a model.
	 *
	 * @param string $model Model identifier.
	 * @return Pricing|null
	 */
	public function pricing( string $model ): ?Pricing {
		return $this->pricing->for( $this->id(), $model );
	}

	/**
	 * Approximate token count.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public function countTokens( string $text ): int {
		return (int) ceil( strlen( $text ) / 4 );
	}

	/**
	 * Send a request and return the decoded body, or throw.
	 *
	 * @param Credentials           $credentials Credentials.
	 * @param string                $method      HTTP method.
	 * @param string                $url         Absolute URL.
	 * @param array<string, mixed>|null $body    JSON body.
	 * @param int                   $timeout     Seconds.
	 * @return array<string, mixed>
	 *
	 * @throws ProviderException When the call fails.
	 */
	protected function send(
		Credentials $credentials,
		string $method,
		string $url,
		?array $body = null,
		int $timeout = 30
	): array {
		$response = $this->http->request(
			$method,
			$url,
			$this->headers( $credentials ),
			$body,
			$timeout
		);

		$this->guard( $response );

		return $response->json();
	}

	/**
	 * Turn a failed response into the right exception.
	 *
	 * The distinction that matters is retryable versus not: a 429 belongs
	 * back on the queue with backoff, a 401 belongs on the operator's
	 * screen. Getting this wrong in either direction is expensive — one
	 * way burns the queue against a key that will never work, the other
	 * drops a request that would have succeeded a second later.
	 *
	 * @param HttpResponse $response Response.
	 * @return void
	 *
	 * @throws ProviderException When the response is not a success.
	 */
	protected function guard( HttpResponse $response ): void {
		if ( $response->isOk() ) {
			return;
		}

		if ( 0 === $response->status ) {
			throw ProviderException::unreachable(
				$this->id(),
				sprintf( 'Could not reach %s: %s', $this->label(), $response->body )
			);
		}

		$message = $this->errorMessage( $response->status, $response->body );

		if ( 401 === $response->status || 403 === $response->status ) {
			throw ProviderException::unauthorised( $this->id(), $message );
		}

		if ( ProviderException::isRetryableStatus( $response->status ) ) {
			throw ProviderException::transient( $this->id(), $response->status, $message );
		}

		throw new ProviderException( $message, $this->id(), $response->status );
	}

	/**
	 * Extract something useful from an error body.
	 *
	 * Every provider nests its message differently and all of them
	 * occasionally return an HTML page from an intermediary instead, so
	 * this checks the known shapes and falls back to saying plainly what
	 * status came back rather than printing markup at the operator.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Raw body.
	 * @return string
	 */
	protected function errorMessage( int $status, string $body ): string {
		$decoded = json_decode( $body, true );

		if ( is_array( $decoded ) ) {
			$candidates = array(
				$decoded['error']['message'] ?? null,
				$decoded['error']['code'] ?? null,
				$decoded['message'] ?? null,
				$decoded['detail'] ?? null,
			);

			foreach ( $candidates as $candidate ) {
				if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
					return $this->prefix( $status, $candidate );
				}
			}
		}

		return $this->prefix(
			$status,
			sprintf( '%s returned HTTP %d.', $this->label(), $status )
		);
	}

	/**
	 * Add the status to a message when it is not already obvious.
	 *
	 * 401 and 429 mean something specific to an operator and are worth
	 * naming; a generic 500 is not made clearer by repeating the number.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Provider message.
	 * @return string
	 */
	private function prefix( int $status, string $message ): string {
		return match ( $status ) {
			401, 403 => 'Rejected: ' . $message,
			429      => 'Rate limited by the provider: ' . $message,
			default  => $message,
		};
	}

	/**
	 * Fail early when no key is set.
	 *
	 * @param Credentials $credentials Credentials.
	 * @return void
	 *
	 * @throws ProviderException When unconfigured.
	 */
	protected function assertConfigured( Credentials $credentials ): void {
		if ( ! $credentials->isPresent() ) {
			throw new ProviderException(
				sprintf( 'No API key is configured for %s.', $this->label() ),
				$this->id(),
				409
			);
		}
	}

	/**
	 * Milliseconds since a microtime mark.
	 *
	 * @param float $started Start mark.
	 * @return int
	 */
	protected static function elapsedMs( float $started ): int {
		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}

	/**
	 * Read an integer from a nested array path.
	 *
	 * @param array<string, mixed> $data Source.
	 * @param string               $key  Top-level key.
	 * @param string               $sub  Nested key.
	 * @return int
	 */
	protected static function intAt( array $data, string $key, string $sub ): int {
		$outer = $data[ $key ] ?? null;

		if ( ! is_array( $outer ) ) {
			return 0;
		}

		$value = $outer[ $sub ] ?? 0;

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Read a string from an array, with a fallback.
	 *
	 * @param array<string, mixed> $data     Source.
	 * @param string               $key      Key.
	 * @param string               $fallback Value when absent.
	 * @return string
	 */
	protected static function stringAt( array $data, string $key, string $fallback = '' ): string {
		$value = $data[ $key ] ?? null;

		return is_string( $value ) && '' !== $value ? $value : $fallback;
	}
}
