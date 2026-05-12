<?php

namespace App;

use InvalidArgumentException;

class Fingerprint
{
    public function normalize(string $identifier): string
    {
        return $this->formatPhone($identifier);
    }

    public function validateIdentifier(string $identifier): bool
    {
        $clean = $this->formatPhone($identifier);

        return (bool) preg_match('/^\+[1-9]\d{7,14}$/', $clean);
    }

    public function formatPhone(string $phone): string
    {
        $phone = trim(str_replace(["\u{00A0}", "\u{2007}", "\u{202F}"], ' ', $phone));
        $hasPlus = str_starts_with(ltrim($phone), '+');
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

    public function getFormatGuidance(): string
    {
        return 'UK mobile numbers are accepted as 07xxx xxxxxx or +447xxx xxxxxx. International numbers are accepted in +[country code][number] format and common spacing is okay.';
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
