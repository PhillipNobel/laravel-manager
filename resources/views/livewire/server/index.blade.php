<div class="max-w-4xl space-y-8">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Server</h1>
            <p class="mt-1.5 text-sm text-muted-foreground">Review this server's environment and Laravel Manager settings.</p>
        </div>
        <april:button-link href="{{ route('settings') }}" variant="outline">Edit server settings</april:button-link>
    </div>

    <section aria-labelledby="environment-heading" class="space-y-4">
        <div>
            <h2 id="environment-heading" class="text-base font-semibold">Environment</h2>
            <p class="mt-1 text-sm text-muted-foreground">Details detected by the running Laravel Manager process.</p>
        </div>

        <dl class="grid grid-cols-1 border-y border-border sm:grid-cols-2">
            @foreach ([
                'Operating system' => $server['operatingSystem'],
                'PHP runtime' => $server['phpVersion'],
                'Default PHP version' => $server['defaultPhpVersion'],
                'Server hostname' => $server['hostname'],
                'Public IP' => $server['publicIp'],
                'Base applications domain' => $server['baseDomain'],
                'Applications directory' => $server['applicationsDirectory'],
                'Laravel Manager URL' => $server['managerUrl'],
            ] as $label => $value)
                <div class="min-w-0 border-b border-border px-4 py-3 last:border-b-0 sm:odd:border-r">
                    <dt class="text-sm text-muted-foreground">{{ $label }}</dt>
                    <dd class="mt-1 break-words text-sm font-medium {{ in_array($label, ['PHP runtime', 'Default PHP version', 'Server hostname', 'Public IP', 'Base applications domain', 'Applications directory', 'Laravel Manager URL'], true) ? 'font-mono' : '' }}">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section aria-labelledby="requirements-heading" class="space-y-4">
        <div>
            <h2 id="requirements-heading" class="text-base font-semibold">Software checks</h2>
            <p class="mt-1 text-sm text-muted-foreground">Read-only version checks. Laravel Manager does not install or change server software.</p>
        </div>

        <ul class="divide-y divide-border border-y border-border">
            @foreach ($requirements as $requirement)
                <li class="grid gap-2 py-3 sm:grid-cols-[8rem_minmax(0,1fr)_auto] sm:items-center sm:gap-4">
                    <span class="text-sm font-medium">{{ $requirement['name'] }}</span>
                    <code class="min-w-0 break-words text-xs text-muted-foreground">{{ $requirement['version'] }}</code>
                    <span>
                        <april:badge :variant="$requirement['status'] === 'available' ? 'secondary' : 'outline'">
                            {{ $requirement['status'] === 'available' ? 'Available' : 'Not detected' }}
                        </april:badge>
                    </span>
                </li>
            @endforeach
        </ul>
    </section>
</div>
