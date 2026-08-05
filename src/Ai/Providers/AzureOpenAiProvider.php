<?php
/**
 * Azure OpenAI adapter.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Ai\Providers;

use Hiveclerk\Ai\CompletionRequest;
use Hiveclerk\Ai\Credentials;
use Hiveclerk\Ai\Model;
use Hiveclerk\Ai\ProviderCapabilities;
use Hiveclerk\Ai\ProviderException;
use Hiveclerk\Ai\ProviderId;

/**
 * Talks to a customer's own Azure OpenAI resource.
 *
 * Azure is the awkward one, and none of it is optional. There is no shared
 * hostname — every customer has their own resource domain. Models are not
 * addressed by name but by *deployment*, which the customer names
 * themselves, so "gpt-4.1" in our picker may be deployed as "prod-chat" in
 * theirs. And the API version goes in the query string, where getting it
 * wrong produces a 404 that looks exactly like a wrong endpoint.
 *
 * The consequence is that the model list has to come from the resource
 * rather than from us: only the customer's Azure account knows what they
 * called their deployments.
 */
final class AzureOpenAiProvider extends OpenAiCompatibleProvider {

	/**
	 * API version used when the operator has not pinned one.
	 *
	 * Azure requires this on every request and validates it strictly.
	 */
	public const DEFAULT_API_VERSION = '2024-10-21';

	/**
	 * Identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return ProviderId::Azure->value;
	}

	/**
	 * Display name.
	 *
	 * @return string
	 */
	public function label(): string {
		return ProviderId::Azure->label();
	}

	/**
	 * Capabilities.
	 *
	 * @return ProviderCapabilities
	 */
	public function capabilities(): ProviderCapabilities {
		return new ProviderCapabilities( needsEndpoint: true );
	}

	/**
	 * Default model.
	 *
	 * Empty on purpose: a deployment name is customer-chosen and cannot
	 * be guessed. The UI requires an explicit selection for this provider.
	 *
	 * @return string
	 */
	public function defaultModel(): string {
		return '';
	}

	/**
	 * Deployments on this resource.
	 *
	 * @param Credentials $credentials Credentials.
	 * @return array<int, Model>
	 *
	 * @throws ProviderException When the resource cannot be reached.
	 */
	public function models( Credentials $credentials ): array {
		$json = $this->send(
			$credentials,
			'GET',
			$this->url( $credentials, '/openai/deployments' )
		);

		$data = $json['data'] ?? array();

		if ( ! is_array( $data ) ) {
			return array();
		}

		$models = array();

		foreach ( $data as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$deployment = self::stringAt( $entry, 'id' );

			if ( '' === $deployment ) {
				continue;
			}

			// The underlying model is what determines the price; the
			// deployment name is what determines the URL. Both are shown
			// so an operator can tell "prod-chat" apart from "cheap-chat".
			$underlying = self::stringAt( $entry, 'model', $deployment );

			$models[] = new Model(
				id: $deployment,
				label: $deployment === $underlying
					? $deployment
					: sprintf( '%s (%s)', $deployment, $underlying ),
				pricing: $this->pricing( $underlying )
			);
		}

		return $models;
	}

	/**
	 * Completion endpoint for a deployment.
	 *
	 * @param Credentials       $credentials Credentials.
	 * @param CompletionRequest $request     Request.
	 * @param bool              $stream      Whether the reply will stream.
	 * @return string
	 */
	protected function completionUrl(
		Credentials $credentials,
		CompletionRequest $request,
		bool $stream
	): string {
		unset( $stream );

		return $this->url(
			$credentials,
			sprintf( '/openai/deployments/%s/chat/completions', rawurlencode( $request->model ) )
		);
	}

	/**
	 * Headers.
	 *
	 * @param Credentials $credentials Credentials.
	 * @return array<string, string>
	 */
	protected function headers( Credentials $credentials ): array {
		return array(
			'api-key' => $credentials->apiKey,
			'accept'  => 'application/json',
		);
	}

	/**
	 * Fail early when the endpoint is missing as well as the key.
	 *
	 * @param Credentials $credentials Credentials.
	 * @return void
	 *
	 * @throws ProviderException When unconfigured.
	 */
	protected function assertConfigured( Credentials $credentials ): void {
		parent::assertConfigured( $credentials );

		if ( '' === trim( $credentials->endpoint ) ) {
			throw new ProviderException(
				'Azure needs the resource endpoint as well as a key, for example https://my-resource.openai.azure.com.',
				$this->id(),
				409
			);
		}
	}

	/**
	 * Build a resource URL with the API version attached.
	 *
	 * @param Credentials $credentials Credentials.
	 * @param string      $path        Path beginning with a slash.
	 * @return string
	 */
	private function url( Credentials $credentials, string $path ): string {
		$base    = rtrim( trim( $credentials->endpoint ), '/' );
		$version = '' !== $credentials->apiVersion
			? $credentials->apiVersion
			: self::DEFAULT_API_VERSION;

		return $base . $path . '?api-version=' . rawurlencode( $version );
	}
}
