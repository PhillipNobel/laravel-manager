<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function valueFor(string $key): string
    {
        return static::query()->where('key', $key)->value('value') ?? (string) config("manager.{$key}");
    }
}
