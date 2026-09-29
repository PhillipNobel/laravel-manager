<?php

use App\Enums\SslStatus;

it('provides clear labels and badge variants for HTTPS states', function (SslStatus $status, string $label, string $variant) {
    expect($status->label())->toBe($label)
        ->and($status->badgeVariant())->toBe($variant);
})->with([
    [SslStatus::Pending, 'Not enabled', 'outline'],
    [SslStatus::Provisioning, 'Setting up', 'primary'],
    [SslStatus::Active, 'HTTPS enabled', 'secondary'],
    [SslStatus::Failed, 'Failed', 'destructive'],
]);
