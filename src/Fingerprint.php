<?php

namespace App;

use InvalidArgumentException;

class Fingerprint
{
    public function normalize(string $identifier): string
    {
        return strtolower(trim($identifier));
    }

    public function validateIdentifier(string $identifier): bool
    {
        if ($identifier === '' || strlen($identifier) > 20) {
            return false;
        }

        // Phone number validation
        return $this->validatePhone($identifier);
    }

    private function validatePhone(string $phone): bool
    {
        $cleaned = preg_replace('/[^\d\+]/', '', $phone);

        // Basic international phone validation
        // Supports any country code and phone number
        $patterns = [
            '/^\+[1-9]\d{1,14}$/',          // International format: +[country][number], 7-15 digits total
            '/^[1-9]\d{6,14}$/'             // Local format: [number], 7-15 digits
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $cleaned)) {
                return true;
            }
        }

        return false;
    }

    public function formatPhone(string $phone): string
    {
        // Remove all non-numeric characters except +
        $cleaned = preg_replace('/[^\d\+]/', '', $phone);

        // If no country code, assume it's a local number
        if (substr($cleaned, 0, 1) !== '+' && strlen($cleaned) >= 7) {
            return '+' . $cleaned; // Convert to international format
        }

        return $cleaned;
    }

    public function fingerprint(string $identifier, string $pepper): string
    {
        if ($pepper === '') {
            throw new InvalidArgumentException('Server security pepper is not configured.');
        }

        $normalized = $this->normalize($identifier);
        if (!$this->validateIdentifier($normalized)) {
            throw new InvalidArgumentException('Identifier must be a valid email or phone value.');
        }

        // Use HMAC-SHA256 for fingerprint generation as required
        return hash_hmac('sha256', $normalized, $pepper);
    }
}
