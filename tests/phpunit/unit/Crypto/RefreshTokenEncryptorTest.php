<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Tests\Unit\Crypto;

use MediaWiki\Extension\DiscordLinkedRoles\Crypto\RefreshTokenEncryptor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \MediaWiki\Extension\DiscordLinkedRoles\Crypto\RefreshTokenEncryptor
 */
class RefreshTokenEncryptorTest extends TestCase {

	private function makeKey(): string {
		return base64_encode( random_bytes( 32 ) );
	}

	public function testEncryptDecryptRoundTrip(): void {
		$encryptor = new RefreshTokenEncryptor( $this->makeKey() );
		$plaintext = 'my-secret-refresh-token-value';

		$ciphertext = $encryptor->encrypt( $plaintext );
		$this->assertNotSame( $plaintext, $ciphertext );

		$decrypted = $encryptor->decrypt( $ciphertext );
		$this->assertSame( $plaintext, $decrypted );
	}

	public function testEncryptProducesDifferentCiphertextsForSamePlaintext(): void {
		$encryptor = new RefreshTokenEncryptor( $this->makeKey() );
		$plaintext = 'same-token';

		$ct1 = $encryptor->encrypt( $plaintext );
		$ct2 = $encryptor->encrypt( $plaintext );

		// Different IVs must produce different ciphertexts.
		$this->assertNotSame( $ct1, $ct2 );
	}

	public function testDecryptFailsOnTamperedCiphertext(): void {
		$encryptor = new RefreshTokenEncryptor( $this->makeKey() );
		$ciphertext = $encryptor->encrypt( 'token' );

		// Flip a byte in the middle of the base64-decoded payload.
		$raw = base64_decode( $ciphertext );
		$raw[15] = chr( ord( $raw[15] ) ^ 0xFF );
		$tampered = base64_encode( $raw );

		$this->expectException( RuntimeException::class );
		$encryptor->decrypt( $tampered );
	}

	public function testConstructorRejectsShortKey(): void {
		$this->expectException( RuntimeException::class );
		new RefreshTokenEncryptor( base64_encode( 'too-short' ) );
	}

	public function testConstructorRejectsInvalidBase64(): void {
		$this->expectException( RuntimeException::class );
		new RefreshTokenEncryptor( '!!!not-base64!!!' );
	}
}
