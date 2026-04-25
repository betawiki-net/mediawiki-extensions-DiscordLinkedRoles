<?php

namespace MediaWiki\Extension\DiscordLinkedRoles\Crypto;

use RuntimeException;

/**
 * Encrypts and decrypts Discord refresh tokens at rest using AES-256-GCM.
 *
 * The key must be a base64-encoded 32-byte value set in
 * $wgDiscordLinkedRolesEncryptionKey.
 */
class RefreshTokenEncryptor {

	private const ALGO = 'aes-256-gcm';
	private const IV_BYTES = 12;
	private const TAG_BYTES = 16;

	private string $key;

	public function __construct( string $base64Key ) {
		$decoded = base64_decode( $base64Key, true );
		if ( $decoded === false || strlen( $decoded ) !== 32 ) {
			throw new RuntimeException(
				'$wgDiscordLinkedRolesEncryptionKey must be a base64-encoded 32-byte value.'
			);
		}
		$this->key = $decoded;
	}

	/**
	 * Encrypt a plaintext refresh token and return the ciphertext as a
	 * base64-encoded string in the format: base64(iv || tag || ciphertext).
	 */
	public function encrypt( string $plaintext ): string {
		$iv = random_bytes( self::IV_BYTES );
		$tag = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			self::ALGO,
			$this->key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_BYTES
		);
		if ( $ciphertext === false ) {
			throw new RuntimeException( 'Encryption failed.' );
		}
		return base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a base64-encoded ciphertext produced by encrypt().
	 *
	 * @throws RuntimeException on invalid input or authentication failure.
	 */
	public function decrypt( string $encoded ): string {
		$raw = base64_decode( $encoded, true );
		if ( $raw === false || strlen( $raw ) < self::IV_BYTES + self::TAG_BYTES ) {
			throw new RuntimeException( 'Invalid ciphertext format.' );
		}
		$iv         = substr( $raw, 0, self::IV_BYTES );
		$tag        = substr( $raw, self::IV_BYTES, self::TAG_BYTES );
		$ciphertext = substr( $raw, self::IV_BYTES + self::TAG_BYTES );

		$plaintext = openssl_decrypt(
			$ciphertext,
			self::ALGO,
			$this->key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);
		if ( $plaintext === false ) {
			throw new RuntimeException( 'Decryption failed: authentication tag mismatch.' );
		}
		return $plaintext;
	}
}
