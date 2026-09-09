<?php

namespace App\Services;

use App\Exceptions\TraccarException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Privileged transport. Use FleetTrackingService for requests originating from users. */
class TraccarService
{
    public function getLatestPositionsForDevices(array $deviceIds): array
    {
        return $this->getByIds('positions', 'deviceId', $deviceIds);
    }

    public function getDevicesByIds(array $deviceIds): array
    {
        return $this->getByIds('devices', 'id', $deviceIds);
    }

    private function getByIds(string $resource, string $parameter, array $ids): array
    {
        $result = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
        foreach (array_chunk($ids, 100) as $chunk) {
            $query = implode('&', array_map(fn ($id) => $parameter.'='.$id, $chunk));
            $result = array_merge($result, $this->request('GET', $resource, $query));
        }

        return $result;
    }

    private function request(string $method, string $path, array|string $data = []): array
    {
        $url = rtrim((string) config('traccar.url'), '/');
        $parts = parse_url($url);
        $local = in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
        $private = config('traccar.private_host') === 'traccar' && ($parts['host'] ?? '') === 'traccar' && ($parts['port'] ?? null) === 8082;
        if (! $parts || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || (($parts['scheme'] ?? '') === 'http' && ! $local && ! $private)
            || ! config('traccar.username') || ! config('traccar.password')) {
            throw new TraccarException('configuration');
        }
        try {
            $response = Http::baseUrl($url.'/api')->acceptJson()
                ->withBasicAuth(config('traccar.username'), config('traccar.password'))
                ->connectTimeout(max(1, (int) config('traccar.connect_timeout')))
                ->timeout(max(1, (int) config('traccar.timeout')))
                ->withOptions(['allow_redirects' => false, 'verify' => true])
                ->send($method, $path, [$method === 'GET' ? 'query' : 'json' => $data]);
        } catch (ConnectionException) {
            // Do not chain the raw exception: request headers can contain credentials.
            throw new TraccarException('connection');
        }
        if (! $response->successful()) {
            throw new TraccarException(match ($response->status()) {
                401, 403 => 'authentication', 400, 422 => 'validation', 404 => 'not_found', default => 'upstream',
            }, $response->status());
        }
        if ($response->status() === 204) {
            return [];
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new TraccarException('upstream');
        }

        return $json;
    }

    // /devices is authenticated, unlike the public /server endpoint.
    public function checkConnection(): void
    {
        $this->getDevices();
    }

    public function getDevices(): array
    {
        return $this->request('GET', 'devices');
    }

    public function getDevice(int $id): array
    {
        $devices = $this->request('GET', 'devices', ['id' => $id]);
        foreach ($devices as $device) {
            if ((int) ($device['id'] ?? 0) === $id) {
                return $device;
            }
        }
        throw new TraccarException('not_found', 404);
    }

    public function createDevice(array $data): array
    {
        return $this->request('POST', 'devices', $data);
    }

    public function findDeviceByUniqueId(string $uniqueId): ?array
    {
        foreach ($this->request('GET', 'devices', ['uniqueId' => $uniqueId]) as $device) {
            if (($device['uniqueId'] ?? null) === $uniqueId) {
                return $device;
            }
        }

        return null;
    }

    public function updateDevice(int $id, array $data): array
    {
        return $this->request('PUT', "devices/$id", array_replace($data, ['id' => $id]));
    }

    public function deleteDevice(int $id): void
    {
        $this->request('DELETE', "devices/$id");
    }

    public function getPositions(int $deviceId, string $from, string $to): array
    {
        return $this->request('GET', 'positions', compact('deviceId', 'from', 'to'));
    }

    public function getLatestPositions(int $deviceId): array
    {
        return $this->request('GET', 'positions', compact('deviceId'));
    }

    public function getTrips(int $deviceId, string $from, string $to): array
    {
        return $this->request('GET', 'reports/trips', compact('deviceId', 'from', 'to'));
    }

    public function getStops(int $deviceId, string $from, string $to): array
    {
        return $this->request('GET', 'reports/stops', compact('deviceId', 'from', 'to'));
    }

    public function getRoute(int $deviceId, string $from, string $to): array
    {
        return $this->request('GET', 'reports/route', compact('deviceId', 'from', 'to'));
    }

    public function getEvents(int $deviceId, string $from, string $to): array
    {
        return $this->request('GET', 'reports/events', compact('deviceId', 'from', 'to'));
    }

    public function getGeofences(int $deviceId): array
    {
        return $this->request('GET', 'geofences', compact('deviceId'));
    }

    public function createGeofence(array $data): array
    {
        return $this->request('POST', 'geofences', $data);
    }

    public function allGeofences(): array
    {
        return $this->request('GET', 'geofences');
    }

    public function linkGeofence(int $deviceId, int $geofenceId): void
    {
        $this->request('POST', 'permissions', compact('deviceId', 'geofenceId'));
    }

    public function updateGeofence(int $id, array $data): array
    {
        return $this->request('PUT', "geofences/$id", array_replace($data, ['id' => $id]));
    }

    public function deleteGeofence(int $id): void
    {
        $this->request('DELETE', "geofences/$id");
    }
}
