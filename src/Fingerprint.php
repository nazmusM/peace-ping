<?php

namespace App;

use InvalidArgumentException;

class Fingerprint
{
    public function normalize(string $identifier): string
    {
        return $this->formatPhone($identifier);
    }

    /**
     * Validate phone number (simplified)
     */
    public function validateIdentifier(string $identifier): bool
    {
        // Remove all non-digit characters except +
        $clean = preg_replace('/[^0-9+]/', '', $identifier);

        // Simple validation: 7-15 digits, optional + prefix
        return preg_match('/^\+?[1-9]\d{6,14}$/', $clean);
    }

    /**
     * Format phone number (simplified)
     */
    public function formatPhone(string $phone): string
    {
        $phone = trim($phone);
        $hasPlus = str_starts_with($phone, '+');
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        if ($hasPlus) {
            return '+' . $digits;
        }

        if (str_starts_with($digits, '00')) {
            return '+' . substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            return '+44' . substr($digits, 1);
        }

        return '+' . $digits;
    }

    public function fingerprint(string $identifier, string $pepper): string
    {
        if ($pepper === '') {
            throw new InvalidArgumentException('Server security pepper is not configured.');
        }

        $normalized = $this->normalize($identifier);
        if (!$this->validateIdentifier($normalized)) {
            throw new InvalidArgumentException('Identifier must be a valid phone number.');
        }

        // Use HMAC-SHA256 for fingerprint generation
        return hash_hmac('sha256', $normalized, $pepper);
    }
}
