<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DeploymentHealth
{
    public function checks(): array
    {
        $checks = [];
        foreach (['Database' => fn () => DB::select('SELECT 1'), 'Tracking engine' => fn () => app(TraccarService::class)->checkConnection()] as $name => $check) {
            try {
                $check();
                $checks[$name] = 'Healthy';
            } catch (\Throwable $error) {
                $checks[$name] = 'Unavailable — check configuration and service logs';
            }
        }
        foreach (['scheduler' => 'Scheduler', 'worker' => 'Queue worker'] as $key => $label) {
            try {
                $checks[$label] = (int) Cache::get('deployment:'.$key, 0) > time() - 180 ? 'Healthy' : 'No recent heartbeat — allow two minutes after startup';
            } catch (\Throwable $error) {
                $checks[$label] = 'Heartbeat storage unavailable';
            }
        }

        return $checks;
    }
}
