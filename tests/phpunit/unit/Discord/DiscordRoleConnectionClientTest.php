<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Tests\Unit\Discord;

use MediaWiki\Extension\DiscordLinkedRoles\Discord\DiscordRoleConnectionClient;
use MediaWiki\Http\HttpRequestFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Wikimedia\Http\MultiHttpClient;

/**
 * @covers \MediaWiki\Extension\DiscordLinkedRoles\Discord\DiscordRoleConnectionClient
 */
class DiscordRoleConnectionClientTest extends TestCase {

	public function testPutUserRoleConnectionIncludesPlatformNameAndUsername(): void {
		$capturedRequest = null;

		$multiClient = $this->getMockBuilder( MultiHttpClient::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'run' ] )
			->getMock();
		$multiClient->expects( $this->once() )
			->method( 'run' )
			->with( $this->callback( static function ( array $request ) use ( &$capturedRequest ): bool {
				$capturedRequest = $request;
				return true;
			} ) )
			->willReturn( [
				'code' => 200,
				'reason' => 'OK',
				'body' => '',
			] );

		$requestFactory = $this->createMock( HttpRequestFactory::class );
		$requestFactory->expects( $this->once() )
			->method( 'createMultiClient' )
			->willReturn( $multiClient );

		$client = new DiscordRoleConnectionClient(
			$requestFactory,
			new NullLogger(),
			'1234567890',
			'bot-token',
			'My Wiki'
		);

		$client->putUserRoleConnection( 'access-token', [ 'group_sysop' => '1' ], 'WikiUser' );

		$this->assertIsArray( $capturedRequest );
		$this->assertSame( 'PUT', $capturedRequest['method'] );
		$this->assertArrayHasKey( 'body', $capturedRequest );

		$payload = json_decode( $capturedRequest['body'], true );
		$this->assertSame( 'My Wiki', $payload['platform_name'] );
		$this->assertSame( 'WikiUser', $payload['platform_username'] );
		$this->assertSame( [ 'group_sysop' => '1' ], $payload['metadata'] );
	}
}
