<?php

use App\Enums\DeploymentStatus;

it('defines the initial deployment statuses', function () {
    expect(array_map(fn (DeploymentStatus $status) => $status->value, DeploymentStatus::cases()))
        ->toBe(['pending', 'running', 'successful', 'failed']);
});

it('can read a deployment status from its stored value', function () {
    expect(DeploymentStatus::from('successful'))->toBe(DeploymentStatus::Successful);
});
