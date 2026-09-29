<?php

use App\Enums\DomainStatus;

it('provides clear labels and April badge variants for Apache domain states', function (DomainStatus $status, string $label, string $variant) {
    expect($status->label())->toBe($label)
        ->and($status->badgeVariant())->toBe($variant);
})->with([
    [DomainStatus::Pending, 'Not configured', 'outline'],
    [DomainStatus::Active, 'Active', 'secondary'],
    [DomainStatus::Failed, 'Failed', 'destructive'],
]);
