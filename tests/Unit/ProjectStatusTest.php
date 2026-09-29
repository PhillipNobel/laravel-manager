<?php

use App\Enums\ProjectStatus;

it('defines the initial project statuses', function () {
    expect(array_map(fn (ProjectStatus $status) => $status->value, ProjectStatus::cases()))
        ->toBe(['pending', 'provisioning', 'active', 'failed', 'disabled']);
});

it('can read a project status from its stored value', function () {
    expect(ProjectStatus::from('pending'))->toBe(ProjectStatus::Pending);
});
