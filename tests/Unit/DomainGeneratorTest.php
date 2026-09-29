<?php

use App\Support\DomainGenerator;

it('combines a subdomain with the configured base domain', function () {
    expect(DomainGenerator::generate('customer', 'apps.example.com'))
        ->toBe('customer.apps.example.com');
});

it('normalizes case and a trailing dot', function () {
    expect(DomainGenerator::generate('Customer', 'Apps.Example.com.'))
        ->toBe('customer.apps.example.com');
});
