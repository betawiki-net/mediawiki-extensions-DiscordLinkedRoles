<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Tests\Unit;

use JobQueueGroup;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\DiscordLinkedRoles\HookHandlers;
use MediaWiki\Extension\DiscordLinkedRoles\Store\LinkedAccountRecord;
use MediaWiki\Extension\DiscordLinkedRoles\Store\LinkedAccountStore;
use MediaWiki\Extension\DiscordLinkedRoles\Sync\RoleConnectionSyncService;
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

	private function makeHandler(
		LinkedAccountStore $store,
		JobQueueGroup $jobQueue,
		HashConfig $config,
		?RoleConnectionSyncService $syncService = null
	): HookHandlers {
		return new HookHandlers(
			$store,
			$jobQueue,
			$config,
			$syncService ?? $this->createMock( RoleConnectionSyncService::class )
		);
	}

	public function testTrackedGroupAddedWithLinkedAccountQueuesJob(): void {
		$user  = $this->makeUser();
		$store = $this->createMock( LinkedAccountStore::class );
		$store->method( 'getByUserId' )->with( 42 )->willReturn( $this->makeRecord() );

		$jobQueue = $this->createMock( JobQueueGroup::class );
		$jobQueue->expects( $this->once() )->method( 'lazyPush' );

		$config   = new HashConfig( [ 'DiscordLinkedRolesReportedGroups' => [ 'sysop' ] ] );
		$handler  = $this->makeHandler( $store, $jobQueue, $config );

		$handler->onUserGroupsChanged( $user, [ 'sysop' ], [], null, '', [], [] );
	}

	public function testTrackedGroupRemovedWithLinkedAccountQueuesJob(): void {
		$user  = $this->makeUser();
		$store = $this->createMock( LinkedAccountStore::class );
		$store->method( 'getByUserId' )->with( 42 )->willReturn( $this->makeRecord() );

		$jobQueue = $this->createMock( JobQueueGroup::class );
		$jobQueue->expects( $this->once() )->method( 'lazyPush' );

		$config  = new HashConfig( [ 'DiscordLinkedRolesReportedGroups' => [ 'sysop' ] ] );
		$handler = $this->makeHandler( $store, $jobQueue, $config );

		$handler->onUserGroupsChanged( $user, [], [ 'sysop' ], null, '', [], [] );
	}

	public function testUntrackedGroupChangeDoesNotQueueJob(): void {
		$user  = $this->makeUser();
		$store = $this->createMock( LinkedAccountStore::class );
		$store->expects( $this->never() )->method( 'getByUserId' );

		$jobQueue = $this->createMock( JobQueueGroup::class );
		$jobQueue->expects( $this->never() )->method( 'lazyPush' );

		$config  = new HashConfig( [ 'DiscordLinkedRolesReportedGroups' => [ 'sysop' ] ] );
		$handler = $this->makeHandler( $store, $jobQueue, $config );

		$handler->onUserGroupsChanged( $user, [ 'autoconfirmed' ], [], null, '', [], [] );
	}

	public function testNoLinkedAccountDoesNotQueueJob(): void {
		$user  = $this->makeUser();
		$store = $this->createMock( LinkedAccountStore::class );
		$store->method( 'getByUserId' )->with( 42 )->willReturn( null );

		$jobQueue = $this->createMock( JobQueueGroup::class );
		$jobQueue->expects( $this->never() )->method( 'lazyPush' );

		$config  = new HashConfig( [ 'DiscordLinkedRolesReportedGroups' => [ 'sysop' ] ] );
		$handler = $this->makeHandler( $store, $jobQueue, $config );

		$handler->onUserGroupsChanged( $user, [ 'sysop' ], [], null, '', [], [] );
	}

	public function testEmptyTrackedGroupsConfigDoesNotQueueJob(): void {
		$user  = $this->makeUser();
		$store = $this->createMock( LinkedAccountStore::class );
		$store->expects( $this->never() )->method( 'getByUserId' );

		$jobQueue = $this->createMock( JobQueueGroup::class );
		$jobQueue->expects( $this->never() )->method( 'lazyPush' );

		$config  = new HashConfig( [ 'DiscordLinkedRolesReportedGroups' => [] ] );
		$handler = $this->makeHandler( $store, $jobQueue, $config );

		$handler->onUserGroupsChanged( $user, [ 'sysop' ], [], null, '', [], [] );
	}

	public function testRenameWithLinkedAccountQueuesJob(): void {
		$store = $this->createMock( LinkedAccountStore::class );
		$store->method( 'getByUserId' )->with( 42 )->willReturn( $this->makeRecord() );

		$jobQueue = $this->createMock( JobQueueGroup::class );
		$jobQueue->expects( $this->once() )->method( 'lazyPush' );

		$config  = new HashConfig( [ 'DiscordLinkedRolesReportedGroups' => [] ] );
		$handler = $this->makeHandler( $store, $jobQueue, $config );

		$handler->onRenameUserComplete( 42, 'OldName', 'NewName' );
	}

	public function testRenameWithoutLinkedAccountDoesNotQueueJob(): void {
		$store = $this->createMock( LinkedAccountStore::class );
		$store->method( 'getByUserId' )->with( 42 )->willReturn( null );

		$jobQueue = $this->createMock( JobQueueGroup::class );
		$jobQueue->expects( $this->never() )->method( 'lazyPush' );

		$config  = new HashConfig( [ 'DiscordLinkedRolesReportedGroups' => [] ] );
		$handler = $this->makeHandler( $store, $jobQueue, $config );

		$handler->onRenameUserComplete( 42, 'OldName', 'NewName' );
	}

	public function testMergeQueuesJobForTargetWhenLinked(): void {
		$fromUser = $this->makeUser( 11 );
		$toUser   = $this->makeUser( 42 );

		$store = $this->createMock( LinkedAccountStore::class );
		$store->method( 'getByUserId' )->with( 42 )->willReturn( $this->makeRecord() );

		$jobQueue = $this->createMock( JobQueueGroup::class );
		$jobQueue->expects( $this->once() )->method( 'lazyPush' );

		$config  = new HashConfig( [ 'DiscordLinkedRolesReportedGroups' => [] ] );
		$handler = $this->makeHandler( $store, $jobQueue, $config );

		$handler->onMergeAccountFromTo( $fromUser, $toUser );
	}

	public function testDeleteAccountClearsRemoteRoleConnectionWhenLinked(): void {
		$deletedUser = $this->makeUser( 42 );

		$store = $this->createMock( LinkedAccountStore::class );
		$store->method( 'getByUserId' )->with( 42 )->willReturn( $this->makeRecord() );

		$jobQueue = $this->createMock( JobQueueGroup::class );
		$jobQueue->expects( $this->never() )->method( 'lazyPush' );

		$syncService = $this->createMock( RoleConnectionSyncService::class );
		$syncService->expects( $this->once() )
			->method( 'clearUserRoleConnection' )
			->with( $deletedUser );

		$config  = new HashConfig( [ 'DiscordLinkedRolesReportedGroups' => [] ] );
		$handler = $this->makeHandler( $store, $jobQueue, $config, $syncService );

		$handler->onDeleteAccount( $deletedUser );
	}

	public function testDeleteAccountWithoutLinkedAccountDoesNotClearRemoteRoleConnection(): void {
		$deletedUser = $this->makeUser( 42 );

		$store = $this->createMock( LinkedAccountStore::class );
		$store->method( 'getByUserId' )->with( 42 )->willReturn( null );

		$jobQueue = $this->createMock( JobQueueGroup::class );
		$jobQueue->expects( $this->never() )->method( 'lazyPush' );

		$syncService = $this->createMock( RoleConnectionSyncService::class );
		$syncService->expects( $this->never() )->method( 'clearUserRoleConnection' );

		$config  = new HashConfig( [ 'DiscordLinkedRolesReportedGroups' => [] ] );
		$handler = $this->makeHandler( $store, $jobQueue, $config, $syncService );

		$handler->onDeleteAccount( $deletedUser );
	}
}
