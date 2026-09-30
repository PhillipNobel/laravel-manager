<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $hidden = ['value'];

    protected $fillable = ['key', 'value'];

    public static function valueFor(string $key): string
    {
        return static::query()->where('key', $key)->value('value') ?? (string) config("manager.{$key}");
    }

    public static function initialSetupIsComplete(): bool
    {
        return static::valueFor('setup_completed') !== '0';
    }
}
