<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Tests\Unit\Store;

use MediaWiki\Extension\DiscordLinkedRoles\Store\LinkedAccountRecord;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\DiscordLinkedRoles\Store\LinkedAccountRecord
 */
class LinkedAccountRecordTest extends TestCase {

	private function makeRecord( array $overrides = [] ): LinkedAccountRecord {
		$defaults = [
			'userId'                 => 42,
			'discordUserId'          => '123456789012345678',
			'discordUsername'        => 'testuser#0001',
			'encryptedRefreshToken'  => 'encrypted-blob',
			'scopes'                 => 'identify role_connections.write',
			'connectedAt'            => '20240101000000',
			'updatedAt'              => '20240101000000',
			'lastSyncAt'             => null,
			'lastSyncError'          => null,
		];
		$params = array_merge( $defaults, $overrides );

		return new LinkedAccountRecord(
			$params['userId'],
			$params['discordUserId'],
			$params['discordUsername'],
			$params['encryptedRefreshToken'],
			$params['scopes'],
			$params['connectedAt'],
			$params['updatedAt'],
			$params['lastSyncAt'],
			$params['lastSyncError']
		);
	}

	public function testGetters(): void {
		$record = $this->makeRecord();

		$this->assertSame( 42, $record->getUserId() );
		$this->assertSame( '123456789012345678', $record->getDiscordUserId() );
		$this->assertSame( 'testuser#0001', $record->getDiscordUsername() );
		$this->assertSame( 'encrypted-blob', $record->getEncryptedRefreshToken() );
		$this->assertSame( 'identify role_connections.write', $record->getScopes() );
		$this->assertSame( '20240101000000', $record->getConnectedAt() );
		$this->assertSame( '20240101000000', $record->getUpdatedAt() );
		$this->assertNull( $record->getLastSyncAt() );
		$this->assertNull( $record->getLastSyncError() );
	}

	public function testWithSyncError(): void {
		$record = $this->makeRecord( [
			'lastSyncAt'    => '20240102000000',
			'lastSyncError' => 'Discord returned 500',
		] );

		$this->assertSame( '20240102000000', $record->getLastSyncAt() );
		$this->assertSame( 'Discord returned 500', $record->getLastSyncError() );
	}
}
