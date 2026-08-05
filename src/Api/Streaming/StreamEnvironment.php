<?php
/**
 * What the host will do to a streamed response.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Api\Streaming;

/**
 * Inspects the request environment for things that break streaming.
 *
 * SseStream switches off everything it can reach. This reports on what it
 * cannot: a reverse proxy's buffer, an execution limit the host has
 * locked, a compression module configured above PHP. None of that is
 * visible from inside a normal response, and all of it turns a streamed
 * answer into a slow one without producing a single error.
 *
 * The findings are advisory, with one exception. A buffer PHP cannot
 * remove will hold the whole response no matter what else is done, so
 * that one is reported as a blocker and the caller is expected to fall
 * back to a buffered reply rather than promise a stream it cannot give.
 *
 * Nothing here is read from user input, and every value comes from ini
 * settings or server variables, so the class is safe to expose to an
 * operator with the settings capability — which is the point, because
 * the answer to "why is my chat slow on this host" lives here.
 */
final class StreamEnvironment {

	public const OK    = 'ok';
	public const WARN  = 'warn';
	public const BLOCK = 'block';

	/**
	 * Execution time below which a long answer is at risk, in seconds.
	 *
	 * A verbose answer from a slow provider runs to about ninety seconds.
	 * Anything under two minutes will cut some of them off mid-sentence.
	 */
	private const SAFE_EXECUTION_TIME = 120;

	/**
	 * Construct.
	 *
	 * @param array<string, mixed> $server Server variables. Injected so the
	 *                                     checks can be exercised against
	 *                                     hosts this machine is not.
	 */
	public function __construct(
		private readonly array $server = array()
	) {
	}

	/**
	 * Every finding, worst first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findings(): array {
		$findings = array(
			$this->checkRemovableBuffers(),
			$this->checkCompression(),
			$this->checkExecutionTime(),
			$this->checkProxy(),
			$this->checkTimeLimit(),
		);

		$rank = array(
			self::BLOCK => 0,
			self::WARN  => 1,
			self::OK    => 2,
		);

		usort(
			$findings,
			static fn ( array $a, array $b ): int =>
				$rank[ $a['severity'] ] <=> $rank[ $b['severity'] ]
		);

		return $findings;
	}

	/**
	 * Whether a real stream is possible.
	 *
	 * @return bool
	 */
	public function canStream(): bool {
		foreach ( $this->findings() as $finding ) {
			if ( self::BLOCK === $finding['severity'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A short description of the stack, for the health screen and support.
	 *
	 * @return array<string, mixed>
	 */
	public function summary(): array {
		return array(
			'can_stream'         => $this->canStream(),
			'sapi'               => PHP_SAPI,
			'server_software'    => $this->serverSoftware(),
			'proxy'              => $this->proxyName(),
			'output_buffering'   => (string) ini_get( 'output_buffering' ),
			'zlib_compression'   => $this->zlibOn(),
			'max_execution_time' => (int) ini_get( 'max_execution_time' ),
			'buffer_levels'      => ob_get_level(),
			'findings'           => $this->findings(),
		);
	}

	/**
	 * Can every active output buffer be removed?
	 *
	 * Read from the flags bitmask, not from the `del` key. PHP 8 dropped
	 * `del` from ob_get_status() and replaced it with `flags`, so the
	 * obvious `empty( $buffer['del'] )` test is true for every buffer on
	 * every supported version — it reported a blocker on a host that was
	 * demonstrably streaming, which is how it was found.
	 *
	 * @return array<string, mixed>
	 */
	private function checkRemovableBuffers(): array {
		$stuck = array();

		foreach ( ob_get_status( true ) as $buffer ) {
			if ( ! is_array( $buffer ) ) {
				continue;
			}

			$flags = isset( $buffer['flags'] ) ? (int) $buffer['flags'] : 0;

			if ( 0 === ( $flags & PHP_OUTPUT_HANDLER_REMOVABLE ) ) {
				$stuck[] = isset( $buffer['name'] ) ? (string) $buffer['name'] : 'unnamed';
			}
		}

		if ( array() === $stuck ) {
			return $this->finding(
				'buffers',
				'Output buffers',
				self::OK,
				'Every buffer can be closed before the first frame.'
			);
		}

		return $this->finding(
			'buffers',
			'Output buffers',
			self::BLOCK,
			sprintf(
				'%s cannot be closed, so the whole response is held until the request ends. Replies will arrive complete, but all at once.',
				implode( ', ', $stuck )
			)
		);
	}

	/**
	 * Is anything compressing the response?
	 *
	 * @return array<string, mixed>
	 */
	private function checkCompression(): array {
		$modules = function_exists( 'apache_get_modules' ) ? apache_get_modules() : array();
		$deflate = in_array( 'mod_deflate', $modules, true )
			|| in_array( 'mod_gzip', $modules, true );

		if ( ! $this->zlibOn() && ! $deflate ) {
			return $this->finding( 'compression', 'Compression', self::OK, 'No compressor is in the way.' );
		}

		// Both are handled — zlib by ini_set, mod_deflate by the no-gzip
		// environment variable and the Content-Encoding header. Reported
		// anyway, because when a host overrides one of those the symptom
		// is a slow chat and nothing else, and this is where to look.
		return $this->finding(
			'compression',
			'Compression',
			self::WARN,
			$this->zlibOn()
				? 'PHP compression is enabled. It is switched off per request for streams; if the host locks the setting, streaming degrades to a single delivery at the end.'
				: 'Apache compression is loaded. It is skipped per request via no-gzip; a server-level override would reinstate it.'
		);
	}

	/**
	 * Is there time to finish a long answer?
	 *
	 * @return array<string, mixed>
	 */
	private function checkExecutionTime(): array {
		$limit = (int) ini_get( 'max_execution_time' );

		if ( 0 === $limit || $limit >= self::SAFE_EXECUTION_TIME ) {
			return $this->finding(
				'execution_time',
				'Execution time',
				self::OK,
				0 === $limit ? 'No limit.' : sprintf( '%d seconds.', $limit )
			);
		}

		return $this->finding(
			'execution_time',
			'Execution time',
			self::WARN,
			sprintf(
				'%d seconds. A long answer can be cut off mid-sentence. Streams raise the limit per request where the host permits it.',
				$limit
			)
		);
	}

	/**
	 * Is a reverse proxy likely to buffer?
	 *
	 * @return array<string, mixed>
	 */
	private function checkProxy(): array {
		$proxy = $this->proxyName();

		if ( null === $proxy ) {
			return $this->finding( 'proxy', 'Reverse proxy', self::OK, 'None detected.' );
		}

		return $this->finding(
			'proxy',
			'Reverse proxy',
			self::WARN,
			sprintf(
				'%s is in front of PHP. Streams ask it not to buffer via X-Accel-Buffering; a proxy configured to ignore that header will hold the reply until it completes.',
				$proxy
			)
		);
	}

	/**
	 * Can the time limit be raised at all?
	 *
	 * @return array<string, mixed>
	 */
	private function checkTimeLimit(): array {
		if ( function_exists( 'set_time_limit' ) ) {
			return $this->finding( 'set_time_limit', 'Time limit control', self::OK, 'Available.' );
		}

		return $this->finding(
			'set_time_limit',
			'Time limit control',
			self::WARN,
			'set_time_limit() is disabled, so the execution limit above is fixed for the request.'
		);
	}

	/**
	 * Whether PHP-level compression is on.
	 *
	 * @return bool
	 */
	private function zlibOn(): bool {
		$value = ini_get( 'zlib.output_compression' );

		return '' !== $value && '0' !== $value && false !== $value && 'Off' !== $value;
	}

	/**
	 * The proxy or edge in front of us, if one announced itself.
	 *
	 * @return string|null
	 */
	private function proxyName(): ?string {
		$vars = array() === $this->server ? $_SERVER : $this->server; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( isset( $vars['HTTP_CF_RAY'] ) ) {
			return 'Cloudflare';
		}

		$software = $this->serverSoftware();

		if ( str_contains( strtolower( $software ), 'nginx' ) ) {
			return 'nginx';
		}

		if ( str_contains( strtolower( $software ), 'litespeed' ) ) {
			return 'LiteSpeed';
		}

		// PHP-FPM behind something that did not name itself. The presence
		// of this function is the reliable tell that PHP is not the front
		// door, and whatever is has a buffer.
		if ( 'fpm-fcgi' === PHP_SAPI && ! isset( $vars['HTTP_X_FORWARDED_FOR'] ) ) {
			return null;
		}

		return isset( $vars['HTTP_X_FORWARDED_FOR'] ) ? 'an upstream proxy' : null;
	}

	/**
	 * The server's own description of itself.
	 *
	 * @return string
	 */
	private function serverSoftware(): string {
		$vars = array() === $this->server ? $_SERVER : $this->server; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return isset( $vars['SERVER_SOFTWARE'] ) && is_string( $vars['SERVER_SOFTWARE'] )
			? $vars['SERVER_SOFTWARE']
			: 'unknown';
	}

	/**
	 * Build one finding.
	 *
	 * @param string $id       Stable identifier.
	 * @param string $label    Human label.
	 * @param string $severity One of OK, WARN, BLOCK.
	 * @param string $detail   What it means and what happens next.
	 * @return array<string, mixed>
	 */
	private function finding( string $id, string $label, string $severity, string $detail ): array {
		return array(
			'id'       => $id,
			'label'    => $label,
			'severity' => $severity,
			'detail'   => $detail,
		);
	}
}
