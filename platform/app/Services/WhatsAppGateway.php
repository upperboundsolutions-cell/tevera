<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WhatsAppGateway
{
    public function ready(): bool
    {
        return config('fleet.whatsapp.enabled') && preg_match('/^AC[a-fA-F0-9]{32}$/', (string) config('fleet.whatsapp.account_sid')) && config('fleet.whatsapp.auth_token') && preg_match('/^HX[a-fA-F0-9]{32}$/', (string) config('fleet.whatsapp.content_sid')) && preg_match('/^whatsapp:\+[1-9][0-9]{7,14}$/', (string) config('fleet.whatsapp.from'));
    }

    public function send(string $number, string $event, string $vehicle, string $time): string
    {
        if (! $this->ready() || ! preg_match('/^\+[1-9][0-9]{7,14}$/', $number)) {
            return 'not_configured';
        }
        try {
            $r = Http::asForm()->withBasicAuth(config('fleet.whatsapp.account_sid'), config('fleet.whatsapp.auth_token'))->withoutRedirecting()->connectTimeout(5)->timeout(15)->post('https://api.twilio.com/2010-04-01/Accounts/'.config('fleet.whatsapp.account_sid').'/Messages.json', [
                'From' => config('fleet.whatsapp.from'), 'To' => 'whatsapp:'.$number, 'ContentSid' => config('fleet.whatsapp.content_sid'), 'ContentVariables' => json_encode(['1' => mb_substr($event, 0, 150), '2' => mb_substr($vehicle, 0, 150), '3' => $time]),
            ]);

            return $r->successful() && is_string($r->json('sid')) ? 'accepted' : 'rejected';
        } catch (\Throwable) {
            return 'uncertain';
        }
    }
}
