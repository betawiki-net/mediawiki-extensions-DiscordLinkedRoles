<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Sync;

use MediaWiki\Extension\DiscordLinkedRoles\Crypto\RefreshTokenEncryptor;
use MediaWiki\Extension\DiscordLinkedRoles\Discord\DiscordOAuthClient;
use MediaWiki\Extension\DiscordLinkedRoles\Discord\DiscordRoleConnectionClient;
use MediaWiki\Extension\DiscordLinkedRoles\Store\LinkedAccountStore;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Coordinates the full role-connection metadata sync for a single user.
 *
 * Refreshes the stored OAuth token if necessary, computes current group-
 * membership metadata via UserMetadataBuilder, pushes the payload to Discord,
 * and persists the sync result (success timestamp or error string) to the DB.
 *
 * The method never throws: all errors are caught, logged, and written to
 * dlra_last_sync_error so the special page can surface them.
 */
class RoleConnectionSyncService {

	public function __construct(
		private readonly LinkedAccountStore $linkedAccountStore,
		private readonly RefreshTokenEncryptor $encryptor,
		private readonly DiscordOAuthClient $oauthClient,
		private readonly DiscordRoleConnectionClient $roleConnectionClient,
		private readonly UserMetadataBuilder $userMetadataBuilder,
		private readonly LoggerInterface $logger
	) {
	}

	/**
	 * Sync current group-membership metadata to Discord for the given user.
	 *
	 * Always persists the outcome to the DB; never throws.
	 */
	public function syncUser( UserIdentity $user ): void {
		$userId = $user->getId();
		$record = $this->linkedAccountStore->getByUserId( $userId );

		if ( $record === null ) {
			$this->logger->warning( 'RoleConnectionSyncService: no linked account for user', [
				'user' => $user->getName(),
			] );
			return;
		}

		try {
			$refreshToken  = $this->encryptor->decrypt( $record->getEncryptedRefreshToken() );
			$tokenResponse = $this->oauthClient->refreshToken( $refreshToken );

			// Persist the possibly-rotated refresh token immediately.
			$this->linkedAccountStore->updateToken(
				$userId,
				$this->encryptor->encrypt( $tokenResponse->getRefreshToken() ),
				$record->getDiscordUsername()
			);

			$metadata = $this->userMetadataBuilder->buildMetadata( $user );
			$this->roleConnectionClient->putUserRoleConnection( $tokenResponse->getAccessToken(), $metadata );

			$this->linkedAccountStore->updateSyncResult( $userId, null );

			$this->logger->info( 'Discord role-connection metadata synced', [
				'user' => $user->getName(),
				'keys' => array_keys( $metadata ),
			] );

		} catch ( RuntimeException $e ) {
			$error = $e->getMessage();
			$this->linkedAccountStore->updateSyncResult( $userId, $error );
			$this->logger->error( 'Discord role-connection sync failed', [
				'user'      => $user->getName(),
				'exception' => $error,
			] );
		}
	}
}
