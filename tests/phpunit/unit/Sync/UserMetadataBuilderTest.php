<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Tests\Unit\Sync;

use MediaWiki\Extension\DiscordLinkedRoles\Sync\MetadataDefinitionBuilder;
use MediaWiki\Extension\DiscordLinkedRoles\Sync\UserMetadataBuilder;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\DiscordLinkedRoles\Sync\UserMetadataBuilder
 */
class UserMetadataBuilderTest extends TestCase {

	private function makeUser( string $name = 'TestUser' ): UserIdentity {
		$user = $this->createMock( UserIdentity::class );
		$user->method( 'getName' )->willReturn( $name );
		return $user;
	}

	private function makeUgm( UserIdentity $user, array $groups ): UserGroupManager {
		$ugm = $this->createMock( UserGroupManager::class );
		$ugm->method( 'getUserEffectiveGroups' )
			->with( $user )
			->willReturn( $groups );
		return $ugm;
	}

	public function testUserInGroupReturnsOne(): void {
		$user    = $this->makeUser();
		$ugm     = $this->makeUgm( $user, [ 'sysop', '*', 'user' ] );
		$builder = new UserMetadataBuilder( $ugm, [ 'sysop' ] );

		$metadata = $builder->buildMetadata( $user );

		$this->assertSame( [ 'group_sysop' => '1' ], $metadata );
	}

	public function testUserNotInGroupReturnsZero(): void {
		$user    = $this->makeUser();
		$ugm     = $this->makeUgm( $user, [ '*', 'user' ] );
		$builder = new UserMetadataBuilder( $ugm, [ 'sysop' ] );

		$metadata = $builder->buildMetadata( $user );

		$this->assertSame( [ 'group_sysop' => '0' ], $metadata );
	}

	public function testMultipleGroupsMixedMembership(): void {
		$user    = $this->makeUser();
		$ugm     = $this->makeUgm( $user, [ 'sysop', 'autopatrolled', '*' ] );
		$builder = new UserMetadataBuilder( $ugm, [ 'sysop', 'autopatrolled', 'bureaucrat' ] );

		$metadata = $builder->buildMetadata( $user );

		$this->assertSame( [
			'group_sysop'         => '1',
			'group_autopatrolled' => '1',
			'group_bureaucrat'    => '0',
		], $metadata );
	}

	public function testKeysMatchGroupToKey(): void {
		$user    = $this->makeUser();
		$ugm     = $this->makeUgm( $user, [] );
		$builder = new UserMetadataBuilder( $ugm, [ 'content-moderator' ] );

		$metadata = $builder->buildMetadata( $user );

		$expectedKey = MetadataDefinitionBuilder::groupToKey( 'content-moderator' );
		$this->assertArrayHasKey( $expectedKey, $metadata );
	}

	public function testEmptyGroupListReturnsEmptyArray(): void {
		$user    = $this->makeUser();
		$ugm     = $this->makeUgm( $user, [ 'sysop' ] );
		$builder = new UserMetadataBuilder( $ugm, [] );

		$this->assertSame( [], $builder->buildMetadata( $user ) );
	}
}
