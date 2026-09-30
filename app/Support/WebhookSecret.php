<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;

class WebhookSecret
{
    private const KEY = 'github_webhook_secret_encrypted';

    public static function read(): ?string
    {
        $configured = config('services.github.webhook_secret');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        $value = AppSetting::query()->where('key', self::KEY)->value('value');

        return $value ? Crypt::decryptString($value) : null;
    }

    public static function ensure(): string
    {
        if ($secret = self::read()) {
            return $secret;
        }
        AppSetting::query()->firstOrCreate(['key' => self::KEY], [
            'value' => Crypt::encryptString(bin2hex(random_bytes(32))),
        ]);

        return self::read();
    }
}
