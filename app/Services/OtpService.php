<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class OtpService
{
    protected int $ttlSeconds = 300; // 5 minutes
    protected int $maxAttempts = 10;

    public function generate(string $phone): string
    {
        $otp = (string) random_int(10000, 99999);

        Cache::put($this->key($phone), [
            'otp' => hash('sha256', $otp),
            'attempts' => 0,
        ], $this->ttlSeconds);

        return $otp;
    }

    public function verify(string $phone, string $inputOtp): bool
    {
        $data = Cache::get($this->key($phone));

        if (!$data) {
            return false; // expired or never sent
        }

        if ($data['attempts'] >= $this->maxAttempts) {
            Cache::forget($this->key($phone));
            return false;
        }

        $data['attempts']++;
        Cache::put($this->key($phone), $data, $this->ttlSeconds);

        if (hash_equals($data['otp'], hash('sha256', $inputOtp))) {
            Cache::forget($this->key($phone)); // one-time use
            return true;
        }

        return false;
    }

    protected function key(string $phone): string
    {
        return "otp:{$phone}";
    }
}