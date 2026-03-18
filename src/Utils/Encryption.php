<?php

namespace App\Utils;

use InvalidArgumentException;

class Encryption
{
    private readonly string $key;
    private readonly string $cipher;

    public function __construct(string $encryptionKey)
    {
        if ($encryptionKey === '') {
            throw new InvalidArgumentException('Encryption key is required.');
        }

        // Ensure key is exactly 32 bytes for AES-256-CBC
        $this->key = hash('sha256', $encryptionKey, true);
        $this->cipher = 'aes-256-cbc';
    }

    /**
     * Encrypt data using AES-256-CBC
     */
    public function encrypt(string $data): string
    {
        if ($data === '') {
            throw new InvalidArgumentException('Data to encrypt cannot be empty.');
        }

        $ivLength = openssl_cipher_iv_length($this->cipher);
        if ($ivLength === false) {
            throw new InvalidArgumentException('Invalid cipher algorithm.');
        }

        $iv = random_bytes($ivLength);
        $encrypted = openssl_encrypt($data, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new InvalidArgumentException('Encryption failed.');
        }

        // Combine IV and encrypted data, then base64 encode
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt data using AES-256-CBC
     */
    public function decrypt(string $encryptedData): string
    {
        if ($encryptedData === '') {
            throw new InvalidArgumentException('Encrypted data cannot be empty.');
        }

        $data = base64_decode($encryptedData, true);
        if ($data === false) {
            throw new InvalidArgumentException('Invalid encrypted data format.');
        }

        $ivLength = openssl_cipher_iv_length($this->cipher);
        if ($ivLength === false || strlen($data) < $ivLength) {
            throw new InvalidArgumentException('Invalid encrypted data format.');
        }

        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);

        $decrypted = openssl_decrypt($encrypted, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new InvalidArgumentException('Decryption failed.');
        }

        return $decrypted;
    }
}
