<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaynowGateway
{
    public function ready(): bool
    {
        $url = parse_url((string) config('paynow.public_url'));

        return (bool) (config('paynow.enabled') && config('paynow.integration_id') && config('paynow.integration_key')
            && preg_match('/^[A-Z]{3}$/', (string) config('paynow.currency')) && ($url['scheme'] ?? '') === 'https'
            && str_contains($url['host'] ?? '', '.') && ! filter_var($url['host'] ?? '', FILTER_VALIDATE_IP)
            && ! isset($url['user']) && ! isset($url['query']) && ! isset($url['fragment']));
    }

    public function signature(array $fields): string
    {
        $values = '';
        foreach ($fields as $key => $value) {
            if (! is_scalar($value)) {
                throw new RuntimeException('Invalid payment message.');
            }
            if (strtolower($key) !== 'hash') {
                $values .= $value;
            }
        }

        return strtoupper(hash('sha512', $values.config('paynow.integration_key')));
    }

    public function verified(string $body): array
    {
        if (! config('paynow.integration_key') || strlen($body) > 16384) {
            throw new RuntimeException('Invalid payment message.');
        }
        $fields = [];
        foreach (explode('&', $body) as $pair) {
            $parts = explode('=', $pair, 2);
            $key = strtolower(urldecode($parts[0]));
            if (! preg_match('/^[a-z]+$/', $key) || isset($fields[$key]) || count($parts) !== 2) {
                throw new RuntimeException('Invalid payment message.');
            }
            $fields[$key] = urldecode($parts[1]);
        }
        if (! isset($fields['hash']) || ! hash_equals($this->signature($fields), strtoupper($fields['hash']))) {
            throw new RuntimeException('Invalid payment signature.');
        }

        return $fields;
    }

    public function safeUrl(string $url, bool $poll = false): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== 'www.paynow.co.zw'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)
            || ($poll && strtolower(rtrim($parts['path'] ?? '', '/')) !== '/interface/checkpayment')) {
            throw new RuntimeException('Invalid gateway URL.');
        }

        return $url;
    }

    public function initiate(Payment $payment, string $email): array
    {
        if (! $this->ready() || $payment->currency !== config('paynow.currency')) {
            throw new RuntimeException('Checkout is not configured.');
        }
        $base = rtrim(config('paynow.public_url'), '/');
        $fields = ['id' => config('paynow.integration_id'), 'reference' => $payment->id,
            'amount' => number_format($payment->amount_cents / 100, 2, '.', ''),
            'additionalinfo' => 'TEVERA '.$payment->plan_name.' - 30 day subscription',
            'returnurl' => $base.'/billing/payments/'.$payment->id,
            'resulturl' => $base.'/paynow/result', 'authemail' => $email, 'status' => 'Message'];
        $fields['hash'] = $this->signature($fields);
        $response = Http::asForm()->timeout(25)->connectTimeout(8)->withoutRedirecting()->post('https://www.paynow.co.zw/interface/initiatetransaction', $fields);
        if (! $response->successful()) {
            throw new RuntimeException('Checkout could not be started.');
        }
        $result = $this->verified($response->body());
        if (strtolower($result['status'] ?? '') !== 'ok') {
            throw new RuntimeException('Checkout was not accepted.');
        }

        return ['poll_url' => $this->safeUrl($result['pollurl'] ?? '', true), 'checkout_url' => $this->safeUrl($result['browserurl'] ?? '')];
    }

    public function poll(Payment $payment): array
    {
        $response = Http::asForm()->timeout(20)->connectTimeout(8)->withoutRedirecting()->post($this->safeUrl($payment->poll_url ?? '', true), []);
        if (! $response->successful()) {
            throw new RuntimeException('Payment status could not be verified.');
        }

        return $this->verified($response->body());
    }
}
