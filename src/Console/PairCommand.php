<?php

namespace Calmify\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class PairCommand extends Command
{
    protected $signature = 'calmify:pair {code : The pairing code shown in Calmify} {--endpoint= : Public URL of the receiver (default: APP_URL + route)}';
    protected $description = 'Connect this site to your Calmify website with a one-time pairing code';

    public function handle(): int
    {
        $key = (string) config('calmify.api_key');
        if ($key === '') {
            $this->error('Run php artisan calmify:install first.');
            return self::FAILURE;
        }
        $endpoint = $this->option('endpoint') ?: rtrim((string) config('app.url'), '/').'/'.ltrim(config('calmify.route'), '/');
        $url = rtrim((string) config('calmify.calmify_url'), '/').'/api/connect/pair';

        // One retry with the address the site really answers on (www, https).
        for ($try = 0; $try < 2; $try++) {
            $this->line('Pairing '.$endpoint.' …');
            $res = Http::timeout(30)->acceptJson()->post($url, [
                'code' => $this->argument('code'),
                'endpoint' => $endpoint,
                'key' => $key,
                'receiver' => 'laravel',
            ]);
            if ($res->successful()) {
                if ($host = $res->json('data.assetHost')) {
                    EnvFile::set('CALMIFY_ASSET_HOST', $host);
                }
                $this->info('Connected to Calmify website "'.$res->json('data.website').'". Calmify can publish here now.');
                $this->line('If you use config caching, run php artisan config:cache.');
                return self::SUCCESS;
            }
            $suggested = $res->json('error.fields.suggestedEndpoint');
            if ($try === 0 && $suggested) {
                $this->warn('The site answers at '.$suggested.'. Trying that address. Consider setting APP_URL to match.');
                $endpoint = $suggested;
                continue;
            }
            $this->error((string) ($res->json('error.message') ?? 'Pairing failed (HTTP '.$res->status().').'));
            return self::FAILURE;
        }
        return self::FAILURE;
    }
}
