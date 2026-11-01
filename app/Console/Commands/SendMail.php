<?php

namespace App\Console\Commands;

use App\Services\MailOutbox;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hr:send-mail')]
#[Description('Send what is waiting in the message outbox, within the hourly limit')]
class SendMail extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MailOutbox $outbox): int
    {
        $result = $outbox->drain();

        $this->line("Sent {$result['sent']}, failed {$result['failed']}, still waiting {$result['remaining']}.");

        return self::SUCCESS;
    }
}
