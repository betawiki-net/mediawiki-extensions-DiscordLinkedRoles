<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Tests\Unit\Sync;

use InvalidArgumentException;
use MediaWiki\Extension\DiscordLinkedRoles\Sync\MetadataDefinitionBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\DiscordLinkedRoles\Sync\MetadataDefinitionBuilder
 */
class MetadataDefinitionBuilderTest extends TestCase {

	// ------------------------------------------------------------------
	// groupToKey()
	// ------------------------------------------------------------------

	public function testGroupToKeySimpleName(): void {
		$this->assertSame( 'group_sysop', MetadataDefinitionBuilder::groupToKey( 'sysop' ) );
	}

	public function testGroupToKeyHyphenatedName(): void {
		$this->assertSame(
			'group_content_moderator',
			MetadataDefinitionBuilder::groupToKey( 'content-moderator' )
		);
	}

	public function testGroupToKeySpacesAndMixedCase(): void {
		$this->assertSame(
			'group_wiki_editors',
			MetadataDefinitionBuilder::groupToKey( 'Wiki Editors' )
		);
	}

	public function testGroupToKeyTruncatesAtFiftyChars(): void {
		// 'group_' (6 chars) + 44 'a' chars = exactly 50 chars; a 45th 'a' is truncated.
		$longGroup = str_repeat( 'a', 50 );
		$key = MetadataDefinitionBuilder::groupToKey( $longGroup );
		$this->assertSame( 50, strlen( $key ) );
		$this->assertStringStartsWith( 'group_', $key );
	}

	public function testGroupToKeyConsecutiveSpecialCharsCollapsed(): void {
		// Multiple special chars should become a single underscore.
		$this->assertSame(
			'group_foo_bar',
			MetadataDefinitionBuilder::groupToKey( 'foo---bar' )
		);
	}

	// ------------------------------------------------------------------
	// Constructor validation
	// ------------------------------------------------------------------

	public function testConstructorAcceptsFiveGroups(): void {
		$builder = new MetadataDefinitionBuilder( [ 'a', 'b', 'c', 'd', 'e' ] );
		$this->assertCount( 5, $builder->build() );
	}

	public function testConstructorRejectsSixGroups(): void {
		$this->expectException( InvalidArgumentException::class );
		new MetadataDefinitionBuilder( [ 'a', 'b', 'c', 'd', 'e', 'f' ] );
	}

	// ------------------------------------------------------------------
	// build()
	// ------------------------------------------------------------------

	public function testBuildReturnsOneEntryPerGroup(): void {
		$builder = new MetadataDefinitionBuilder( [ 'sysop', 'autopatrolled' ] );
		$defs    = $builder->build();
		$this->assertCount( 2, $defs );
	}

	public function testBuildEntryHasRequiredKeys(): void {
		$builder = new MetadataDefinitionBuilder( [ 'sysop' ] );
		$def     = $builder->build()[0];
		$this->assertArrayHasKey( 'key', $def );
		$this->assertArrayHasKey( 'name', $def );
		$this->assertArrayHasKey( 'description', $def );
		$this->assertArrayHasKey( 'type', $def );
	}

	public function testBuildEntryKeyMatchesGroupToKey(): void {
		$builder = new MetadataDefinitionBuilder( [ 'content-moderator' ] );
		$def     = $builder->build()[0];
		$this->assertSame(
			MetadataDefinitionBuilder::groupToKey( 'content-moderator' ),
			$def['key']
		);
	}

	public function testBuildEntryTypeIsBoolean(): void {
		$builder = new MetadataDefinitionBuilder( [ 'sysop' ] );
		$def     = $builder->build()[0];
		// Discord BOOLEAN_EQUAL type = 7
		$this->assertSame( 7, $def['type'] );
	}

	public function testBuildEmptyGroupsReturnsEmptyArray(): void {
		$builder = new MetadataDefinitionBuilder( [] );
		$this->assertSame( [], $builder->build() );
	}
}
