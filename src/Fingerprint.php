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

        // Phone patterns (currently UK-focused but can be extended)
        $patterns = [
            '/^07[0-9]{9}$/',           // 07xxx xxxxxx
            '/^\+447[0-9]{9}$/',       // +447xx xxxxxx
            '/^01[0-9]{9}$/',           // 01xxx xxxxxx
            '/^02[0-9]{9}$/',           // 02xxx xxxxxx
            '/^03[0-9]{9}$/',           // 03xxx xxxxxx
            '/^\+44[12][0-9]{9}$/',     // +441xxx, +442xxx, +443xxx
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
