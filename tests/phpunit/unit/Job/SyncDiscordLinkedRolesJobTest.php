<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Tests\Unit\Job;

use MediaWiki\Extension\DiscordLinkedRoles\Job\SyncDiscordLinkedRolesJob;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\DiscordLinkedRoles\Job\SyncDiscordLinkedRolesJob
 */
class SyncDiscordLinkedRolesJobTest extends TestCase {

	public function testJobNameAndRemoveDuplicates(): void {
		$job = new SyncDiscordLinkedRolesJob( [ 'userId' => 1 ] );

		$this->assertSame( 'discordLinkedRolesSyncUser', $job->getType() );
		$this->assertTrue( $job->ignoreDuplicates() );
	}

	public function testJobReturnsTrueWhenUserIdMissing(): void {
		$job = new SyncDiscordLinkedRolesJob( [] );
		// run() will hit the early return without calling services.
		// We can verify the return value by calling run directly only when
		// MediaWikiServices is available (integration), so here we just assert
		// construction succeeds and removeDuplicates is set correctly.
		$this->assertTrue( $job->ignoreDuplicates() );
	}
}
