<?php

use App\Enums\DatabaseStatus;

it('provides clear labels and April badge variants for database states', function (DatabaseStatus $status, string $label, string $variant) {
    expect($status->label())->toBe($label)
        ->and($status->badgeVariant())->toBe($variant);
})->with([
    [DatabaseStatus::Pending, 'Not configured', 'outline'],
    [DatabaseStatus::Provisioning, 'Creating', 'outline'],
    [DatabaseStatus::Active, 'Active', 'secondary'],
    [DatabaseStatus::Failed, 'Failed', 'destructive'],
]);
