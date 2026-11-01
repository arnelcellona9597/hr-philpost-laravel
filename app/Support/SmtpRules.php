<?php

namespace App\Support;

/**
 * What a mail server's settings must look like, on the Email Server page and from the command line.
 */
class SmtpRules
{
    /**
     * Problems with the settings, keyed by field.
     *
     * @return array<string, string>
     */
    public static function errors(string $host, int|false $port, string $security, string $fromAddress): array
    {
        $errors = [];

        if (! preg_match('/^[a-z0-9.-]+$/i', $host)) {
            $errors['host'] = 'Enter a valid host name, e.g. mail.example.com';
        }

        if ($port === false || $port < 1 || $port > 65535) {
            $errors['port'] = 'Enter a valid port (465 for SSL/TLS, 587 for STARTTLS).';
        }

        if (! in_array($security, ['ssl', 'starttls', 'none'], true)) {
            $errors['security'] = 'Choose the connection security.';
        }

        if ($fromAddress === '' || GovId::validateEmail($fromAddress) !== null) {
            $errors['from_address'] = 'Enter a valid From address.';
        }

        return $errors;
    }
}
