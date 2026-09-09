<?php

namespace App\Console\Commands;

use App\Services\TraccarService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class LinkTraccar extends Command
{
    protected $signature = 'platform:link-traccar';

    protected $description = 'Connect the private Docker tracking engine, optionally provisioning its first service account';

    public function handle(TraccarService $traccar): int
    {
        if (config('traccar.url') !== 'http://traccar:8082' || config('traccar.private_host') !== 'traccar') {
            $this->error('This command is only for the private Docker deployment.');

            return self::FAILURE;
        }
        try {
            $traccar->checkConnection();
            $this->info('Tracking engine already linked.');

            return self::SUCCESS;
        } catch (\Throwable $error) {
            if (! $this->confirm('Is this a NEW Traccar database with no existing accounts? Create its first administrator service account?', false)) {
                $this->error('Set existing service credentials in deploy/.env and restart instead.');

                return self::FAILURE;
            }
        }
        try {
            $response = Http::timeout(20)->withoutRedirecting()->post('http://traccar:8082/api/users', [
                'name' => 'TEVERA service', 'email' => config('traccar.username'), 'password' => config('traccar.password'),
            ]);
            if (! $response->successful() || ! $response->json('administrator')) {
                $this->error('First-account provisioning was not confirmed. Check the server and existing accounts; credentials were not printed.');

                return self::FAILURE;
            }
            $traccar->checkConnection();
        } catch (\Throwable $error) {
            $this->error('Connection could not be confirmed. Run the command again to check before attempting provisioning.');

            return self::FAILURE;
        }
        $this->info('Tracking engine linked.');

        return self::SUCCESS;
    }
}
