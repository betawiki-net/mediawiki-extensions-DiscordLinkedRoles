<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Tests\Unit\Sync;

use MediaWiki\Extension\DiscordLinkedRoles\Crypto\RefreshTokenEncryptor;
use MediaWiki\Extension\DiscordLinkedRoles\Discord\DiscordOAuthClient;
use MediaWiki\Extension\DiscordLinkedRoles\Discord\DiscordRoleConnectionClient;
use MediaWiki\Extension\DiscordLinkedRoles\Discord\DiscordTokenResponse;
use MediaWiki\Extension\DiscordLinkedRoles\Store\LinkedAccountRecord;
use MediaWiki\Extension\DiscordLinkedRoles\Store\LinkedAccountStore;
use MediaWiki\Extension\DiscordLinkedRoles\Sync\RoleConnectionSyncService;
use MediaWiki\Extension\DiscordLinkedRoles\Sync\UserMetadataBuilder;
use MediaWiki\User\UserIdentity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \MediaWiki\Extension\DiscordLinkedRoles\Sync\RoleConnectionSyncService
 */
class RoleConnectionSyncServiceTest extends TestCase {

	public function testSyncSendsWikiUsernameToDiscordPayload(): void {
		$user = $this->createMock( UserIdentity::class );
		$user->method( 'getId' )->willReturn( 42 );
		$user->method( 'getName' )->willReturn( 'WikiUser' );

		$record = new LinkedAccountRecord(
			42,
			'discord-123',
			'DiscordUser',
			'encrypted-refresh-token',
			'identify role_connections.write',
			'20250101000000',
			'20250101000000',
			null,
			null
		);

		$store = $this->createMock( LinkedAccountStore::class );
		$store->expects( $this->once() )
			->method( 'getByUserId' )
			->with( 42 )
			->willReturn( $record );
		$store->expects( $this->once() )
			->method( 'updateToken' )
			->with( 42, 're-encrypted-refresh-token', 'DiscordUser' );
		$store->expects( $this->once() )
			->method( 'updateSyncResult' )
			->with( 42, null );

		$encryptor = $this->createMock( RefreshTokenEncryptor::class );
		$encryptor->expects( $this->once() )
			->method( 'decrypt' )
			->with( 'encrypted-refresh-token' )
			->willReturn( 'decrypted-refresh-token' );
		$encryptor->expects( $this->once() )
			->method( 'encrypt' )
			->with( 'new-refresh-token' )
			->willReturn( 're-encrypted-refresh-token' );

		$oauthClient = $this->createMock( DiscordOAuthClient::class );
		$oauthClient->expects( $this->once() )
			->method( 'refreshToken' )
			->with( 'decrypted-refresh-token' )
			->willReturn( new DiscordTokenResponse(
				'access-token',
				'new-refresh-token',
				3600,
				'identify role_connections.write',
				'Bearer'
			) );

		$userMetadataBuilder = $this->createMock( UserMetadataBuilder::class );
		$userMetadataBuilder->expects( $this->once() )
			->method( 'buildMetadata' )
			->with( $user )
			->willReturn( [ 'group_sysop' => '1' ] );

		$roleConnectionClient = $this->createMock( DiscordRoleConnectionClient::class );
		$roleConnectionClient->expects( $this->once() )
			->method( 'putUserRoleConnection' )
			->with( 'access-token', [ 'group_sysop' => '1' ], 'WikiUser' );

		$service = new RoleConnectionSyncService(
			$store,
			$encryptor,
			$oauthClient,
			$roleConnectionClient,
			$userMetadataBuilder,
			new NullLogger()
		);

		$service->syncUser( $user );
	}

	public function testClearUserRoleConnectionClearsPayloadAndUpdatesResult(): void {
		$user = $this->createMock( UserIdentity::class );
		$user->method( 'getId' )->willReturn( 42 );
		$user->method( 'getName' )->willReturn( 'WikiUser' );

		$record = new LinkedAccountRecord(
			42,
			'discord-123',
			'DiscordUser',
			'encrypted-refresh-token',
			'identify role_connections.write',
			'20250101000000',
			'20250101000000',
			null,
			null
		);

		$store = $this->createMock( LinkedAccountStore::class );
		$store->expects( $this->once() )
			->method( 'getByUserId' )
			->with( 42 )
			->willReturn( $record );
		$store->expects( $this->once() )
			->method( 'updateToken' )
			->with( 42, 're-encrypted-refresh-token', 'DiscordUser' );
		$store->expects( $this->once() )
			->method( 'updateSyncResult' )
			->with( 42, null );

		$encryptor = $this->createMock( RefreshTokenEncryptor::class );
		$encryptor->expects( $this->once() )
			->method( 'decrypt' )
			->with( 'encrypted-refresh-token' )
			->willReturn( 'decrypted-refresh-token' );
		$encryptor->expects( $this->once() )
			->method( 'encrypt' )
			->with( 'new-refresh-token' )
			->willReturn( 're-encrypted-refresh-token' );

		$oauthClient = $this->createMock( DiscordOAuthClient::class );
		$oauthClient->expects( $this->once() )
			->method( 'refreshToken' )
			->with( 'decrypted-refresh-token' )
			->willReturn( new DiscordTokenResponse(
				'access-token',
				'new-refresh-token',
				3600,
				'identify role_connections.write',
				'Bearer'
			) );

		$userMetadataBuilder = $this->createMock( UserMetadataBuilder::class );
		$userMetadataBuilder->expects( $this->never() )->method( 'buildMetadata' );

		$roleConnectionClient = $this->createMock( DiscordRoleConnectionClient::class );
		$roleConnectionClient->expects( $this->once() )
			->method( 'clearUserRoleConnection' )
			->with( 'access-token' );

		$service = new RoleConnectionSyncService(
			$store,
			$encryptor,
			$oauthClient,
			$roleConnectionClient,
			$userMetadataBuilder,
			new NullLogger()
		);

		$service->clearUserRoleConnection( $user );
	}

	public function testClearUserRoleConnectionPersistsErrorOnFailure(): void {
		$user = $this->createMock( UserIdentity::class );
		$user->method( 'getId' )->willReturn( 42 );
		$user->method( 'getName' )->willReturn( 'WikiUser' );

		$record = new LinkedAccountRecord(
			42,
			'discord-123',
			'DiscordUser',
			'encrypted-refresh-token',
			'identify role_connections.write',
			'20250101000000',
			'20250101000000',
			null,
			null
		);

		$store = $this->createMock( LinkedAccountStore::class );
		$store->expects( $this->once() )
			->method( 'getByUserId' )
			->with( 42 )
			->willReturn( $record );
		$store->expects( $this->never() )->method( 'updateToken' );
		$store->expects( $this->once() )
			->method( 'updateSyncResult' )
			->with( 42, 'refresh failed' );

		$encryptor = $this->createMock( RefreshTokenEncryptor::class );
		$encryptor->expects( $this->once() )
			->method( 'decrypt' )
			->with( 'encrypted-refresh-token' )
			->willReturn( 'decrypted-refresh-token' );
		$encryptor->expects( $this->never() )->method( 'encrypt' );

		$oauthClient = $this->createMock( DiscordOAuthClient::class );
		$oauthClient->expects( $this->once() )
			->method( 'refreshToken' )
			->with( 'decrypted-refresh-token' )
			->willThrowException( new RuntimeException( 'refresh failed' ) );

		$userMetadataBuilder = $this->createMock( UserMetadataBuilder::class );
		$userMetadataBuilder->expects( $this->never() )->method( 'buildMetadata' );

		$roleConnectionClient = $this->createMock( DiscordRoleConnectionClient::class );
		$roleConnectionClient->expects( $this->never() )->method( 'clearUserRoleConnection' );

		$service = new RoleConnectionSyncService(
			$store,
			$encryptor,
			$oauthClient,
			$roleConnectionClient,
			$userMetadataBuilder,
			new NullLogger()
		);

		$service->clearUserRoleConnection( $user );
	}

	public function testDisconnectUserClearsPayloadRevokesAndPersistsSuccess(): void {
		$user = $this->createMock( UserIdentity::class );
		$user->method( 'getId' )->willReturn( 42 );
		$user->method( 'getName' )->willReturn( 'WikiUser' );

		$record = new LinkedAccountRecord(
			42,
			'discord-123',
			'DiscordUser',
			'encrypted-refresh-token',
			'identify role_connections.write',
			'20250101000000',
			'20250101000000',
			null,
			null
		);

		$store = $this->createMock( LinkedAccountStore::class );
		$store->expects( $this->once() )
			->method( 'getByUserId' )
			->with( 42 )
			->willReturn( $record );
		$store->expects( $this->once() )
			->method( 'updateToken' )
			->with( 42, 're-encrypted-refresh-token', 'DiscordUser' );
		$store->expects( $this->once() )
			->method( 'updateSyncResult' )
			->with( 42, null );

		$encryptor = $this->createMock( RefreshTokenEncryptor::class );
		$encryptor->expects( $this->once() )
			->method( 'decrypt' )
			->with( 'encrypted-refresh-token' )
			->willReturn( 'decrypted-refresh-token' );
		$encryptor->expects( $this->once() )
			->method( 'encrypt' )
			->with( 'new-refresh-token' )
			->willReturn( 're-encrypted-refresh-token' );

		$oauthClient = $this->createMock( DiscordOAuthClient::class );
		$oauthClient->expects( $this->once() )
			->method( 'refreshToken' )
			->with( 'decrypted-refresh-token' )
			->willReturn( new DiscordTokenResponse(
				'access-token',
				'new-refresh-token',
				3600,
				'identify role_connections.write',
				'Bearer'
			) );
		$oauthClient->expects( $this->once() )
			->method( 'revokeToken' )
			->with( 'new-refresh-token' );

		$userMetadataBuilder = $this->createMock( UserMetadataBuilder::class );
		$userMetadataBuilder->expects( $this->never() )->method( 'buildMetadata' );

		$roleConnectionClient = $this->createMock( DiscordRoleConnectionClient::class );
		$roleConnectionClient->expects( $this->once() )
			->method( 'clearUserRoleConnection' )
			->with( 'access-token' );

		$service = new RoleConnectionSyncService(
			$store,
			$encryptor,
			$oauthClient,
			$roleConnectionClient,
			$userMetadataBuilder,
			new NullLogger()
		);

		$service->disconnectUser( $user );
	}

	public function testDisconnectUserPersistsErrorWhenRevokeFails(): void {
		$user = $this->createMock( UserIdentity::class );
		$user->method( 'getId' )->willReturn( 42 );
		$user->method( 'getName' )->willReturn( 'WikiUser' );

		$record = new LinkedAccountRecord(
			42,
			'discord-123',
			'DiscordUser',
			'encrypted-refresh-token',
			'identify role_connections.write',
			'20250101000000',
			'20250101000000',
			null,
			null
		);

		$store = $this->createMock( LinkedAccountStore::class );
		$store->expects( $this->once() )
			->method( 'getByUserId' )
			->with( 42 )
			->willReturn( $record );
		$store->expects( $this->once() )
			->method( 'updateToken' )
			->with( 42, 're-encrypted-refresh-token', 'DiscordUser' );
		$store->expects( $this->once() )
			->method( 'updateSyncResult' )
			->with( 42, 'revoke failed' );

		$encryptor = $this->createMock( RefreshTokenEncryptor::class );
		$encryptor->expects( $this->once() )
			->method( 'decrypt' )
			->with( 'encrypted-refresh-token' )
			->willReturn( 'decrypted-refresh-token' );
		$encryptor->expects( $this->once() )
			->method( 'encrypt' )
			->with( 'new-refresh-token' )
			->willReturn( 're-encrypted-refresh-token' );

		$oauthClient = $this->createMock( DiscordOAuthClient::class );
		$oauthClient->expects( $this->once() )
			->method( 'refreshToken' )
			->with( 'decrypted-refresh-token' )
			->willReturn( new DiscordTokenResponse(
				'access-token',
				'new-refresh-token',
				3600,
				'identify role_connections.write',
				'Bearer'
			) );
		$oauthClient->expects( $this->once() )
			->method( 'revokeToken' )
			->with( 'new-refresh-token' )
			->willThrowException( new RuntimeException( 'revoke failed' ) );

		$userMetadataBuilder = $this->createMock( UserMetadataBuilder::class );
		$userMetadataBuilder->expects( $this->never() )->method( 'buildMetadata' );

		$roleConnectionClient = $this->createMock( DiscordRoleConnectionClient::class );
		$roleConnectionClient->expects( $this->once() )
			->method( 'clearUserRoleConnection' )
			->with( 'access-token' );

		$service = new RoleConnectionSyncService(
			$store,
			$encryptor,
			$oauthClient,
			$roleConnectionClient,
			$userMetadataBuilder,
			new NullLogger()
		);

		$service->disconnectUser( $user );
	}
}
