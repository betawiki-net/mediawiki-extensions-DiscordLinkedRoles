<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Discord;

/**
 * Value object returned by the Discord token endpoint.
 */
class DiscordTokenResponse {

	public function __construct(
		private readonly string $accessToken,
		private readonly string $refreshToken,
		private readonly int $expiresIn,
		private readonly string $scope,
		private readonly string $tokenType
	) {
	}

	public function getAccessToken(): string {
		return $this->accessToken;
	}

	public function getRefreshToken(): string {
		return $this->refreshToken;
	}

	public function getExpiresIn(): int {
		return $this->expiresIn;
	}

	public function getScope(): string {
		return $this->scope;
	}

	public function getTokenType(): string {
		return $this->tokenType;
	}
}
