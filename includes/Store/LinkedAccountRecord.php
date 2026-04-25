<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Store;

/**
 * Value object representing one row in discord_linked_roles_account.
 */
class LinkedAccountRecord {

	public function __construct(
		private readonly int $userId,
		private readonly string $discordUserId,
		private readonly string $discordUsername,
		private readonly string $encryptedRefreshToken,
		private readonly string $scopes,
		private readonly string $connectedAt,
		private readonly string $updatedAt,
		private readonly ?string $lastSyncAt,
		private readonly ?string $lastSyncError
	) {
	}

	public function getUserId(): int {
		return $this->userId;
	}

	public function getDiscordUserId(): string {
		return $this->discordUserId;
	}

	public function getDiscordUsername(): string {
		return $this->discordUsername;
	}

	public function getEncryptedRefreshToken(): string {
		return $this->encryptedRefreshToken;
	}

	public function getScopes(): string {
		return $this->scopes;
	}

	public function getConnectedAt(): string {
		return $this->connectedAt;
	}

	public function getUpdatedAt(): string {
		return $this->updatedAt;
	}

	public function getLastSyncAt(): ?string {
		return $this->lastSyncAt;
	}

	public function getLastSyncError(): ?string {
		return $this->lastSyncError;
	}
}
