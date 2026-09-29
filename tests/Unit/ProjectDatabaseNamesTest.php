<?php

use App\Support\ProjectDatabaseNames;

it('generates stable MySQL identifiers for a valid application slug', function () {
    expect(ProjectDatabaseNames::for('customer'))->toBe([
        'database_name' => 'lm_customer_b6c45863',
        'database_username' => 'lm_customer_b6c45863',
    ]);
});

it('keeps generated MySQL identifiers within MySQL identifier limits', function () {
    $names = ProjectDatabaseNames::for(str_repeat('a', 63));

    expect(strlen($names['database_name']))->toBe(64)
        ->and(strlen($names['database_username']))->toBe(32)
        ->and($names['database_name'])->toMatch('/\A[a-z0-9_]+\z/')
        ->and($names['database_username'])->toMatch('/\A[a-z0-9_]+\z/');
});

it('rejects invalid application slugs before generating identifiers', function (string $slug) {
    expect(fn () => ProjectDatabaseNames::for($slug))->toThrow(InvalidArgumentException::class);
})->with(['', 'not a slug', 'customer;drop', '-customer', 'customer-']);
