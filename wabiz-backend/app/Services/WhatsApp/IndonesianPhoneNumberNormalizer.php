<?php

namespace App\Services\WhatsApp;

class IndonesianPhoneNumberNormalizer
{
    public function canonicalize(string $phoneNumber): string
    {
        $phoneNumber = trim($phoneNumber);
        $hasPlus = str_starts_with($phoneNumber, '+');
        $hasInternationalPrefix = str_starts_with($phoneNumber, '00');
        $digits = preg_replace('/\D+/', '', $phoneNumber) ?? '';

        if ($hasInternationalPrefix) {
            $digits = substr($digits, 2);

            return '+'.$digits;
        }

        if ($hasPlus) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '62')) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) >= 8) {
            return '+62'.substr($digits, 1);
        }

        if (str_starts_with($digits, '8') && strlen($digits) >= 9 && strlen($digits) <= 13) {
            return '+62'.$digits;
        }

        // Bare numbers outside the known Indonesian patterns are not assigned a guessed country code.
        return $digits;
    }

    /** @return list<string> */
    public function matchingDigitForms(string $canonicalNumber): array
    {
        $digits = preg_replace('/\D+/', '', $canonicalNumber) ?? '';
        $forms = [$digits];

        if (str_starts_with($digits, '628')) {
            $forms[] = '0'.substr($digits, 2);
            $forms[] = substr($digits, 2);
        }

        return array_values(array_unique($forms));
    }
}
