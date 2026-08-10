<?php

use PHPUnit\Framework\TestCase;
use ServiceStatusManager\Encryption;

/**
 * @covers \ServiceStatusManager\Encryption
 */
final class EncryptionTest extends TestCase {

	public function test_encrypt_then_decrypt_round_trips() {
		$plain = 'https://example.webhook.office.com/webhookb2/super-secret-path';

		$cipher = Encryption::encrypt( $plain );

		$this->assertNotSame( $plain, $cipher );
		$this->assertSame( $plain, Encryption::decrypt( $cipher ) );
	}

	public function test_encrypting_empty_string_returns_empty_string() {
		$this->assertSame( '', Encryption::encrypt( '' ) );
		$this->assertSame( '', Encryption::decrypt( '' ) );
	}

	public function test_decrypting_garbage_returns_empty_string_not_an_error() {
		$this->assertSame( '', Encryption::decrypt( 'not-a-valid-payload' ) );
	}

	public function test_mask_keeps_only_last_four_characters() {
		$secret = 'AC1234567890123456';
		$masked = Encryption::mask( $secret );

		$this->assertSame( strlen( $secret ), strlen( $masked ) );
		$this->assertSame( substr( $secret, -4 ), substr( $masked, -4 ) );
		$this->assertSame( str_repeat( '*', strlen( $secret ) - 4 ), substr( $masked, 0, -4 ) );

		$this->assertSame( '****', Encryption::mask( 'abcd' ) );
		$this->assertSame( '*bxyz', Encryption::mask( 'abxyz' ) );
		$this->assertSame( '', Encryption::mask( '' ) );
	}

	public function test_each_encryption_uses_a_fresh_random_iv() {
		$plain = 'same-plaintext-twice';

		$first  = Encryption::encrypt( $plain );
		$second = Encryption::encrypt( $plain );

		$this->assertNotSame( $first, $second, 'Ciphertext should differ between calls due to a random IV, even for identical plaintext.' );
		$this->assertSame( $plain, Encryption::decrypt( $first ) );
		$this->assertSame( $plain, Encryption::decrypt( $second ) );
	}
}
