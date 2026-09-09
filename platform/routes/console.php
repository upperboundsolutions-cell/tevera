<?php

use App\Jobs\DeploymentHeartbeat;
use App\Services\DeploymentHealth;
use App\Services\FleetEventNotifications;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Process\Process;

Artisan::command('fleet:notifications', function () {
    app(FleetEventNotifications::class)->run();
    $this->info('Notification check completed.');
});
Schedule::command('fleet:notifications')->everyMinute()->withoutOverlapping();

Artisan::command('platform:backup-local', function () {
    $process = new Process([PHP_BINARY, base_path('../tools/backup-local.php')]);
    $process->setTimeout(1800);
    $process->mustRun();
    $this->info($process->getOutput());
});
if (PHP_OS_FAMILY === 'Windows' && is_file(base_path('../.tools/traccar-local.xml'))) {
    Schedule::command('platform:backup-local')->dailyAt('02:00')->withoutOverlapping();
}
Schedule::call(function () {
    foreach (app(DeploymentHealth::class)->checks() as $name => $status) {
        if ($status !== 'Healthy') {
            Log::warning('TEVERA health check', ['service' => $name, 'status' => $status]);
        }
    }
})->everyFiveMinutes()->name('platform-health-monitor')->withoutOverlapping();

Schedule::call(function () {
    Cache::put('deployment:scheduler', time(), 300);
    DeploymentHeartbeat::dispatch();
})->everyMinute()->name('deployment-heartbeat')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
