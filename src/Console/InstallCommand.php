<?php

namespace Calmify\Laravel\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'calmify:install {--no-migrate : Skip running the migration}';
    protected $description = 'Set up the Calmify receiver: key, table and route';

    public function handle(): int
    {
        if (! config('calmify.api_key')) {
            $key = bin2hex(random_bytes(32));
            EnvFile::set('CALMIFY_API_KEY', $key);
            config(['calmify.api_key' => $key]);
            $this->info('Generated CALMIFY_API_KEY in .env.');
        } else {
            $this->line('CALMIFY_API_KEY already set.');
        }
        if (! $this->option('no-migrate')) {
            $this->call('migrate', ['--force' => true]);
        }
        $endpoint = rtrim((string) config('app.url'), '/').'/'.ltrim(config('calmify.route'), '/');
        $this->newLine();
        $this->info('Calmify receiver installed at '.$endpoint);
        $this->line('Next: in Calmify, open Websites → your site → Connection → Connect, then run:');
        $this->line('  php artisan calmify:pair <CODE>');
        $this->line('If you use config caching, run php artisan config:clear first.');
        return self::SUCCESS;
    }
}
