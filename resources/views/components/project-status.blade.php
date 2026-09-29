@props(['status'])

@php
    $variant = match ($status->value) {
        'active' => 'secondary',
        'failed' => 'destructive',
        'provisioning' => 'default',
        default => 'outline',
    };
@endphp

<april:badge :variant="$variant">{{ \Illuminate\Support\Str::headline($status->value) }}</april:badge>
