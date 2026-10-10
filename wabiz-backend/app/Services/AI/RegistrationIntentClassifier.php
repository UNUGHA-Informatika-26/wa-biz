<?php

namespace App\Services\AI;

use App\Models\Message;
use Illuminate\Support\Str;

class RegistrationIntentClassifier
{
    public const REGISTRATION = 'REGISTRATION';

    public const GENERAL = 'GENERAL';

    public const UNKNOWN = 'UNKNOWN';

    public function classify(Message $message): string
    {
        if ($message->message_type !== 'text' || ! is_string($message->body) || trim($message->body) === '') {
            return self::UNKNOWN;
        }

        $text = Str::lower(trim($message->body));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = preg_replace('/[^\pL\pN]+/u', ' ', $text) ?? $text;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if (preg_match('/\b(daftar|mendaftar|mendaftarkan|pendaftaran|registrasi|registration|enrol|enroll|enrolment|enrollment|admission|admissions|signup)\b/u', $text) === 1
            || preg_match('/\b(sign\s+up|new\s+student)\b/u', $text) === 1
            || preg_match('/\b(mau|ingin|berminat|rencana|how\s+to|want\s+to)\b.*\b(masuk|jadi|menjadi|gabung|bergabung|peserta|santri|siswa|murid|enrol|enroll|register|join)\b/u', $text) === 1) {
            return self::REGISTRATION;
        }

        return self::GENERAL;
    }
}
