<?php

namespace App\Console\Commands;

use App\Services\DeploymentHealth;
use Illuminate\Console\Command;

class DeploymentHealthCheck extends Command
{
    protected $signature = 'platform:health';

    protected $description = 'Check database, tracking engine, scheduler and queue worker';

    public function handle(DeploymentHealth $health): int
    {
        $checks = $health->checks();
        $this->table(['Service', 'Status'], collect($checks)->map(fn ($state, $name) => [$name, $state])->values()->all());

        return count(array_filter($checks, fn ($state) => $state !== 'Healthy')) ? self::FAILURE : self::SUCCESS;
    }
}
