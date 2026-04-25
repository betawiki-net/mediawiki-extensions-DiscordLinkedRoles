<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Tests\Unit;

use JobQueueGroup;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\DiscordLinkedRoles\HookHandlers;
use MediaWiki\Extension\DiscordLinkedRoles\Store\LinkedAccountRecord;
use MediaWiki\Extension\DiscordLinkedRoles\Store\LinkedAccountStore;
use MediaWiki\User\UserIdentity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\DiscordLinkedRoles\HookHandlers
 */
class HookHandlersTest extends TestCase {

	private function makeUser( int $id = 42 ): UserIdentity {
		$user = $this->createMock( UserIdentity::class );
		$user->method( 'getId' )->willReturn( $id );
		return $user;
	}

	private function makeRecord(): LinkedAccountRecord {
		return new LinkedAccountRecord(
			42, 'discord123', 'TestUser#1234', 'enc', 'identify role_connections.write',
			'20250101000000', '20250101000000', null, null
		);
	}

	public function testTrackedGroupAddedWithLinkedAccountQueuesJob(): void {
		$user  = $this->makeUser();
		$store = $this->createMock( LinkedAccountStore::class );
		$store->method( 'getByUserId' )->with( 42 )->willReturn( $this->makeRecord() );

		$jobQueue = $this->createMock( JobQueueGroup::class );
		$jobQueue->expects( $this->once() )->method( 'lazyPush' );

		$config   = new HashConfig( [ 'DiscordLinkedRolesReportedGroups' => [ 'sysop' ] ] );
		$handler  = new HookHandlers( $store, $jobQueue, $config );

		$handler->onUserGroupsChanged( $user, [ 'sysop' ], [], null, '', [], [] );
	}

	public function testTrackedGroupRemovedWithLinkedAccountQueuesJob(): void {
		$user  = $this->makeUser();
		$store = $this->createMock( LinkedAccountStore::class );
		$store->method( 'getByUserId' )->with( 42 )->willReturn( $this->makeRecord() );

		$jobQueue = $this->createMock( JobQueueGroup::class );
		$jobQueue->expects( $this->once() )->method( 'lazyPush' );

		$config  = new HashConfig( [ 'DiscordLinkedRolesReportedGroups' => [ 'sysop' ] ] );
		$handler = new HookHandlers( $store, $jobQueue, $config );

		$handler->onUserGroupsChanged( $user, [], [ 'sysop' ], null, '', [], [] );
	}

	public function testUntrackedGroupChangeDoesNotQueueJob(): void {
		$user  = $this->makeUser();
		$store = $this->createMock( LinkedAccountStore::class );
		$store->expects( $this->never() )->method( 'getByUserId' );

		$jobQueue = $this->createMock( JobQueueGroup::class );
		$jobQueue->expects( $this->never() )->method( 'lazyPush' );

		$config  = new HashConfig( [ 'DiscordLinkedRolesReportedGroups' => [ 'sysop' ] ] );
		$handler = new HookHandlers( $store, $jobQueue, $config );

		$handler->onUserGroupsChanged( $user, [ 'autoconfirmed' ], [], null, '', [], [] );
	}

	public function testNoLinkedAccountDoesNotQueueJob(): void {
		$user  = $this->makeUser();
		$store = $this->createMock( LinkedAccountStore::class );
		$store->method( 'getByUserId' )->with( 42 )->willReturn( null );

		$jobQueue = $this->createMock( JobQueueGroup::class );
		$jobQueue->expects( $this->never() )->method( 'lazyPush' );

		$config  = new HashConfig( [ 'DiscordLinkedRolesReportedGroups' => [ 'sysop' ] ] );
		$handler = new HookHandlers( $store, $jobQueue, $config );

		$handler->onUserGroupsChanged( $user, [ 'sysop' ], [], null, '', [], [] );
	}

	public function testEmptyTrackedGroupsConfigDoesNotQueueJob(): void {
		$user  = $this->makeUser();
		$store = $this->createMock( LinkedAccountStore::class );
		$store->expects( $this->never() )->method( 'getByUserId' );

		$jobQueue = $this->createMock( JobQueueGroup::class );
		$jobQueue->expects( $this->never() )->method( 'lazyPush' );

		$config  = new HashConfig( [ 'DiscordLinkedRolesReportedGroups' => [] ] );
		$handler = new HookHandlers( $store, $jobQueue, $config );

		$handler->onUserGroupsChanged( $user, [ 'sysop' ], [], null, '', [], [] );
	}
}
