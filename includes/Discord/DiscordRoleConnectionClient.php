<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Discord;

use MediaWiki\Http\HttpRequestFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Client for the Discord role-connection endpoints (application metadata
 * definitions and per-user role connection payloads).
 *
 * This is a stub in Phase 1; substantive logic is added in Phase 3.
 */
class DiscordRoleConnectionClient {

	private const BASE_URL = 'https://discord.com/api/v10';

	public function __construct(
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly LoggerInterface $logger,
		private readonly string $applicationId,
		private readonly string $botToken,
		private readonly string $siteName
	) {
	}

	/**
	 * Retrieve the current application-level metadata definitions.
	 *
	 * @throws RuntimeException on HTTP or Discord error.
	 */
	public function getMetadataDefinitions(): array {
		$url = self::BASE_URL . "/applications/{$this->applicationId}/role-connections/metadata";
		$req = $this->httpRequestFactory->create( $url, [ 'method' => 'GET' ], __METHOD__ );
		$req->setHeader( 'Authorization', 'Bot ' . $this->botToken );
		$status = $req->execute();
		if ( !$status->isOK() ) {
			throw new RuntimeException(
				'Discord GET metadata definitions failed: ' . $status->getMessage()
			);
		}
		return json_decode( $req->getContent(), true ) ?? [];
	}

	/**
	 * Overwrite the application-level metadata definitions.
	 *
	 * Uses MultiHttpClient because GuzzleHttpRequest only sends a request body
	 * for POST requests; PUT with a body requires the MultiHttpClient path.
	 *
	 * @param array[] $definitions Each element is a Discord metadata record array.
	 * @throws RuntimeException on HTTP or Discord error.
	 */
	public function putMetadataDefinitions( array $definitions ): void {
		$url  = self::BASE_URL . "/applications/{$this->applicationId}/role-connections/metadata";
		$body = json_encode( $definitions );

		$response = $this->putJson( $url, [ 'authorization' => 'Bot ' . $this->botToken ], $body );

		if ( $response['code'] < 200 || $response['code'] >= 300 ) {
			throw new RuntimeException(
				'Discord PUT metadata definitions failed: HTTP ' . $response['code']
				. ' ' . $response['reason']
				. ' — ' . $response['body']
			);
		}
	}

	/**
	 * Push a user role-connection payload (platform name + metadata values).
	 *
	 * Uses MultiHttpClient for the same reason as putMetadataDefinitions().
	 *
	 * @param string $accessToken A valid access token with role_connections.write scope.
	 * @param array  $metadata    Associative array of metadata key => string value.
	 * @param string|null $platformUsername Optional wiki username shown in Discord.
	 * @throws RuntimeException on HTTP or Discord error.
	 */
	public function putUserRoleConnection(
		string $accessToken,
		array $metadata,
		?string $platformUsername = null
	): void {
		$url     = self::BASE_URL . "/users/@me/applications/{$this->applicationId}/role-connection";
		$payload = [
			'platform_name' => $this->siteName,
			'metadata'      => $metadata,
		];
		if ( $platformUsername !== null && $platformUsername !== '' ) {
			$payload['platform_username'] = $platformUsername;
		}
		$body = json_encode( $payload );

		$response = $this->putJson( $url, [ 'authorization' => 'Bearer ' . $accessToken ], $body );

		if ( $response['code'] < 200 || $response['code'] >= 300 ) {
			throw new RuntimeException(
				'Discord PUT user role-connection failed: HTTP ' . $response['code']
				. ' ' . $response['reason']
				. ' — ' . $response['body']
			);
		}
	}

	/**
	 * Clear a user's role-connection payload (used during disconnect).
	 *
	 * @param string $accessToken A valid access token with role_connections.write scope.
	 */
	public function clearUserRoleConnection( string $accessToken ): void {
		try {
			$this->putUserRoleConnection( $accessToken, [] );
		} catch ( RuntimeException $e ) {
			$this->logger->warning( 'Failed to clear Discord role connection', [
				'exception' => $e->getMessage(),
			] );
		}
	}

	// ------------------------------------------------------------------
	// Private helpers
	// ------------------------------------------------------------------

	/**
	 * Send a PUT request with a JSON body using MultiHttpClient.
	 *
	 * GuzzleHttpRequest (used by HttpRequestFactory::create()) only attaches
	 * the request body for POST; PUT with a body must go through
	 * MultiHttpClient which uses CURLOPT_PUT + CURLOPT_INFILE.
	 *
	 * @param string   $url      Absolute URL.
	 * @param string[] $headers  Additional request headers (lowercase names).
	 * @param string   $body     Raw request body (JSON string).
	 * @return array  The fully-populated MultiHttpClient request array
	 *                (response is in $result['response']).
	 */
	private function putJson( string $url, array $headers, string $body ): array {
		$client = $this->httpRequestFactory->createMultiClient();
		$req    = [
			'method'  => 'PUT',
			'url'     => $url,
			'headers' => array_merge( $headers, [ 'content-type' => 'application/json' ] ),
			'body'    => $body,
		];
		return $client->run( $req );
	}
}
