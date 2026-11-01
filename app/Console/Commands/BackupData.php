<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

#[Signature('hr:backup {--keep=14 : How many days of backups to keep}')]
#[Description('Back up the MySQL database and the uploaded files')]
class BackupData extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $directory = storage_path('app/private/backups');
        File::ensureDirectoryExists($directory, 0700);

        $stamp = now()->format('Y-m-d-His');
        $database = "{$directory}/hr-{$stamp}.sql.gz";

        if (! $this->dumpDatabase($database)) {
            return self::FAILURE;
        }

        chmod($database, 0600);
        $this->line("Database saved to {$database}");

        $files = "{$directory}/files-{$stamp}.tar.gz";
        $disk = Storage::disk('local');
        $folders = array_values(array_filter(['media', 'imports'], fn (string $folder): bool => $disk->exists($folder)));

        if ($folders !== []) {
            $archive = Process::path($disk->path(''))->run(['tar', '-czf', $files, ...$folders]);

            if ($archive->failed()) {
                $this->error('Could not archive the uploaded files.');

                return self::FAILURE;
            }

            chmod($files, 0600);
            $this->line("Uploaded files saved to {$files}");
        }

        $this->removeOldBackups($directory, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    /**
     * The password goes through a private options file, so it never shows in the process list.
     */
    private function dumpDatabase(string $target): bool
    {
        $connection = config('database.connections.'.config('database.default'));
        $options = tempnam(storage_path('app/private'), 'dump');
        chmod($options, 0600);
        file_put_contents($options, "[client]\npassword=\"".addcslashes((string) $connection['password'], '"\\')."\"\n");

        $command = sprintf(
            'mysqldump --defaults-extra-file=%s --single-transaction --quick --no-tablespaces --default-character-set=utf8mb4 -h %s -P %s -u %s %s | gzip > %s',
            escapeshellarg($options),
            escapeshellarg((string) $connection['host']),
            escapeshellarg((string) $connection['port']),
            escapeshellarg((string) $connection['username']),
            escapeshellarg((string) $connection['database']),
            escapeshellarg($target),
        );

        $result = Process::timeout(600)->run(['bash', '-o', 'pipefail', '-c', $command]);
        unlink($options);

        if ($result->successful() && is_file($target) && filesize($target) > 100) {
            return true;
        }

        File::delete($target);
        $this->error('The database backup failed: '.trim($result->errorOutput()));

        return false;
    }

    private function removeOldBackups(string $directory, int $keepDays): void
    {
        $cutoff = now()->subDays($keepDays)->getTimestamp();

        foreach (File::files($directory) as $file) {
            if ($file->getMTime() < $cutoff) {
                File::delete($file->getPathname());
            }
        }
    }
}
