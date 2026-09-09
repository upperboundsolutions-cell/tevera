<?php

namespace App\Console\Commands;

use App\Exceptions\TraccarException;
use App\Services\TraccarService;
use Illuminate\Console\Command;

class CheckTraccar extends Command
{
    protected $signature = 'traccar:check';

    protected $description = 'Verify authenticated server-side Traccar connectivity';

    public function handle(TraccarService $service): int
    {
        try {
            $service->checkConnection();
            $this->info('Traccar authentication succeeded.');

            return self::SUCCESS;
        } catch (TraccarException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
