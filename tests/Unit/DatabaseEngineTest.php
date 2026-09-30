<?php

use App\Enums\DatabaseEngine;

it('defines the supported application database engines', function () {
    expect(DatabaseEngine::cases())->toHaveCount(2)
        ->and(DatabaseEngine::MySql->value)->toBe('mysql')
        ->and(DatabaseEngine::PostgreSql->value)->toBe('pgsql')
        ->and(DatabaseEngine::MySql->label())->toBe('MySQL')
        ->and(DatabaseEngine::PostgreSql->label())->toBe('PostgreSQL');
});
