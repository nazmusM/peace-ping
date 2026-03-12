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
        if ($identifier === '' || strlen($identifier) > 255) {
            return false;
        }

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false) {
            return true;
        }

        return (bool) preg_match('/^\+?[0-9][0-9\-\s\(\)]{6,24}$/', $identifier);
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

        return hash('sha256', $normalized . $pepper);
    }
}
