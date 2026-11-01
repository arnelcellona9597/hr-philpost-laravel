<?php

namespace App\Console\Commands;

use App\Models\SmtpSetting;
use App\Services\DirectMailer;
use App\Support\GovId;
use App\Support\SmtpRules;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('hr:configure-smtp {--test= : Send one test email to this address}')]
#[Description('Save the mail server from the HR_SMTP_* values and check that it works')]
class ConfigureSmtp extends Command
{
    /**
     * Signing in needs an emailed code, so a new install must have its mail server before anyone
     * can reach the Email Server page.
     */
    public function handle(DirectMailer $mailer): int
    {
        $config = config('hr.smtp');
        $host = trim((string) $config['host']);
        $username = trim((string) $config['username']);
        $from = trim((string) ($config['from_address'] ?: $username));
        $port = filter_var($config['port'], FILTER_VALIDATE_INT);
        $security = (string) $config['security'];
        $errors = SmtpRules::errors($host, $port, $security, $from);

        if ($username !== '' && blank($config['password'])) {
            $errors['password'] = 'HR_SMTP_PASSWORD is empty.';
        }

        $test = $this->option('test');

        if ($test !== null && GovId::validateEmail($test) !== null) {
            $errors['test'] = 'The --test address is not valid.';
        }

        if ($errors !== []) {
            foreach ($errors as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $settings = SmtpSetting::current();
        $settings->fill([
            'host' => $host,
            'port' => $port,
            'security' => $security,
            'username' => $username ?: null,
            'password' => $config['password'] ?: null,
            'from_address' => $from,
            'from_name' => $config['from_name'] ?: 'HR System',
        ])->save();

        $this->info("Saved {$host}:{$port} ({$security}), sending as {$from}.");

        $error = $mailer->verifyConnection();

        if ($error !== null) {
            $this->error("Connecting failed: {$error}");

            return self::FAILURE;
        }

        $this->info('Connected and signed in to the email server.');

        return $test === null ? self::SUCCESS : $this->sendTest($mailer, $test);
    }

    private function sendTest(DirectMailer $mailer, string $to): int
    {
        try {
            $mailer->deliver($to, 'Test email from the HR system', 'This is a test email from hr:configure-smtp. Email sending works.');
        } catch (Throwable $exception) {
            $this->error("Sending failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Test email sent to {$to}.");

        return self::SUCCESS;
    }
}
