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

        // UK phone number validation - England only
        return $this->validateUKPhone($identifier);
    }

    private function validateUKPhone(string $phone): bool
    {
        // Remove spaces, parentheses, hyphens
        $cleaned = preg_replace('/[\s\-\(\)]/', '', $phone);

        // UK mobile numbers: 07xxx xxxxxx or +447xx xxxxxx
        // UK landline: 01xxx xxxxxx, 02xxx xxxxxx, +441xxx xxxxxx, +442xxx xxxxxx
        $patterns = [
            '/^07[0-9]{9}$/',           // Mobile: 07xxxxxxxxx
            '/^\+447[0-9]{9}$/',        // Mobile: +447xxxxxxxxx
            '/^01[0-9]{8,9}$/',         // Landline: 01xxxxxxxxx
            '/^02[0-9]{8,9}$/',         // Landline: 02xxxxxxxxx
            '/^\+441[0-9]{8,9}$/',      // Landline: +441xxxxxxxxx
            '/^\+442[0-9]{8,9}$/',      // Landline: +442xxxxxxxxx
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $cleaned)) {
                return true;
            }
        }

        return false;
    }

    public function formatUKPhone(string $phone): string
    {
        // Remove all non-numeric characters except +
        $cleaned = preg_replace('/[^\d\+]/', '', $phone);

        // Convert to international format if not already
        if (substr($cleaned, 0, 1) === '0' && strlen($cleaned) === 11) {
            return '+44' . substr($cleaned, 1);
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
