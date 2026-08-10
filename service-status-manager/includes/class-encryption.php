<?php
/**
 * Symmetric encryption helper for secrets at rest (SMS credentials, Teams
 * webhook URLs, monitor auth headers).
 *
 * Uses AES-256-GCM keyed from WordPress' own secret salts, so no additional
 * configuration is required. This protects against casual database dumps
 * being reused, but the key is derivable by anyone with access to
 * wp-config.php - administrators with server access must still be trusted.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Encryption {

	const CIPHER = 'aes-256-gcm';

	/**
	 * Derives a stable 32-byte encryption key from WordPress salts.
	 *
	 * @return string
	 */
	private static function get_key() {
		$secret = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return hash( 'sha256', $secret, true );
	}

	/**
	 * Encrypts a plain text string.
	 *
	 * @param string $plain_text Value to encrypt.
	 * @return string Base64 encoded payload (iv + tag + ciphertext), or '' on empty input.
	 */
	public static function encrypt( $plain_text ) {
		if ( '' === (string) $plain_text ) {
			return '';
		}

		$iv  = random_bytes( 12 );
		$tag = '';

		$cipher_text = openssl_encrypt( (string) $plain_text, self::CIPHER, self::get_key(), OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $cipher_text ) {
			return '';
		}

		return base64_encode( $iv . $tag . $cipher_text ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypts a value previously produced by encrypt().
	 *
	 * @param string $payload Base64 encoded payload.
	 * @return string Decrypted plain text, or '' if decryption fails.
	 */
	public static function decrypt( $payload ) {
		if ( '' === (string) $payload ) {
			return '';
		}

		$raw = base64_decode( (string) $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}

		$iv          = substr( $raw, 0, 12 );
		$tag         = substr( $raw, 12, 16 );
		$cipher_text = substr( $raw, 28 );

		$plain_text = openssl_decrypt( $cipher_text, self::CIPHER, self::get_key(), OPENSSL_RAW_DATA, $iv, $tag );

		return false === $plain_text ? '' : $plain_text;
	}

	/**
	 * Masks a secret for display purposes (e.g. after saving credentials),
	 * showing only the last four characters.
	 *
	 * @param string $value Secret value.
	 * @return string
	 */
	public static function mask( $value ) {
		$value = (string) $value;
		$len   = strlen( $value );

		if ( 0 === $len ) {
			return '';
		}

		if ( $len <= 4 ) {
			return str_repeat( '*', $len );
		}

		return str_repeat( '*', $len - 4 ) . substr( $value, -4 );
	}
}
