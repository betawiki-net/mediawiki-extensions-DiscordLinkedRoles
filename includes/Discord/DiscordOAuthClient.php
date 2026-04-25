<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Discord;

use MediaWiki\Http\HttpRequestFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Thin client for the Discord OAuth2 token and user-info endpoints.
 *
 * All token and revoke requests use application/x-www-form-urlencoded as
 * required by the Discord API.
 */
class DiscordOAuthClient {

	private const TOKEN_URL  = 'https://discord.com/api/v10/oauth2/token';
	private const REVOKE_URL = 'https://discord.com/api/v10/oauth2/token/revoke';
	private const ME_URL     = 'https://discord.com/api/v10/users/@me';

	public function __construct(
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly LoggerInterface $logger,
		private readonly string $clientId,
		private readonly string $clientSecret
	) {
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @throws RuntimeException on HTTP or Discord error.
	 */
	public function exchangeCode( string $code, string $redirectUri ): DiscordTokenResponse {
		return $this->requestToken( [
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => $redirectUri,
		] );
	}

	/**
	 * Use a refresh token to obtain a new access token.
	 *
	 * @throws RuntimeException on HTTP or Discord error.
	 */
	public function refreshToken( string $refreshToken ): DiscordTokenResponse {
		return $this->requestToken( [
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refreshToken,
		] );
	}

	/**
	 * Revoke a token (access or refresh).
	 *
	 * Failures are logged but not re-thrown so that a revocation failure
	 * during disconnect does not block local cleanup.
	 */
	public function revokeToken( string $token ): void {
		$body = http_build_query( [
			'client_id'     => $this->clientId,
			'client_secret' => $this->clientSecret,
			'token'         => $token,
		] );
		$req = $this->httpRequestFactory->create( self::REVOKE_URL, [
			'method'  => 'POST',
			'postData' => $body,
		], __METHOD__ );
		$req->setHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		$status = $req->execute();
		if ( !$status->isOK() ) {
			$this->logger->warning( 'Discord token revocation failed', [
				'status' => $status->getMessage(),
			] );
		}
	}

	/**
	 * Fetch the authenticated Discord user identity using an access token.
	 *
	 * Returns an associative array with at least 'id' and 'username' keys.
	 *
	 * @throws RuntimeException on HTTP or Discord error.
	 */
	public function fetchCurrentUser( string $accessToken ): array {
		$req = $this->httpRequestFactory->create( self::ME_URL, [
			'method' => 'GET',
		], __METHOD__ );
		$req->setHeader( 'Authorization', 'Bearer ' . $accessToken );
		$status = $req->execute();
		if ( !$status->isOK() ) {
			throw new RuntimeException( 'Discord /users/@me request failed: ' . $status->getMessage() );
		}
		$data = json_decode( $req->getContent(), true );
		if ( !is_array( $data ) || !isset( $data['id'] ) ) {
			throw new RuntimeException( 'Discord /users/@me returned unexpected payload.' );
		}
		return $data;
	}

	// ------------------------------------------------------------------
	// Private helpers
	// ------------------------------------------------------------------

	private function requestToken( array $params ): DiscordTokenResponse {
		$body = http_build_query( array_merge( $params, [
			'client_id'     => $this->clientId,
			'client_secret' => $this->clientSecret,
		] ) );
		$req = $this->httpRequestFactory->create( self::TOKEN_URL, [
			'method'   => 'POST',
			'postData' => $body,
		], __METHOD__ );
		$req->setHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		$status = $req->execute();
		if ( !$status->isOK() ) {
			throw new RuntimeException( 'Discord token request failed: ' . $status->getMessage() );
		}
		$data = json_decode( $req->getContent(), true );
		if ( !is_array( $data ) || !isset( $data['access_token'], $data['refresh_token'] ) ) {
			throw new RuntimeException( 'Discord token endpoint returned unexpected payload.' );
		}
		return new DiscordTokenResponse(
			$data['access_token'],
			$data['refresh_token'],
			(int)( $data['expires_in'] ?? 0 ),
			$data['scope'] ?? '',
			$data['token_type'] ?? 'Bearer'
		);
	}
}
