<?php

namespace App\Exceptions;

use RuntimeException;

class TraccarException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $upstreamStatus = null)
    {
        parent::__construct(match ($reason) {
            'configuration' => 'Traccar connection is not configured correctly.',
            'authentication' => 'Traccar rejected the service credentials.',
            'validation' => 'Traccar rejected the request. Check the resource fields.',
            'not_found' => 'The requested Traccar resource was not found.',
            'connection' => 'Traccar could not be reached within the configured timeout.',
            default => 'Traccar is temporarily unavailable or returned an invalid response.',
        });
    }
}
