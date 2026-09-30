@php
    $state = $updateStatus['state'] ?? 'unsupported';
    $busy = in_array($state, ['queued', 'running'], true);
    $supported = $state !== 'unsupported';
    $canStart = in_array($state, ['available', 'failed', 'interrupted'], true);
    $phaseLabels = [
        'checking' => 'Checking source', 'waiting' => 'Waiting for operations',
        'maintenance' => 'Stopping services', 'source' => 'Updating source',
        'dependencies' => 'Installing dependencies', 'assets' => 'Building assets',
        'migrations' => 'Running migrations', 'restarting' => 'Restoring services',
        'verifying' => 'Checking login', 'successful' => 'Verified', 'failed' => 'Failed',
    ];
@endphp
<section aria-labelledby="manager-updates-heading" class="border-t border-border pt-6"
    x-data="{
        reconnecting: false, reconnectMessage: '', timer: null, deadline: 0,
        begin() {
            if (this.reconnecting) return;
            this.reconnecting = true;
            this.deadline = Date.now() + 3600000;
            this.reconnectMessage = 'Waiting for the update. This page reconnects automatically.';
            this.timer = setTimeout(() => this.reconnect(), 5000);
        },
        async reconnect() {
            if (Date.now() > this.deadline) {
                this.reconnectMessage = 'The panel has not returned. Check the server with sudo laravel-manager update.';
                return;
            }
            try {
                const response = await fetch(@js(route('settings.manager-update-status')), {
                    headers: { Accept: 'application/json' }, cache: 'no-store', signal: AbortSignal.timeout(5000)
                });
                if (response.status === 401 || response.redirected) { window.location.assign(@js(route('login'))); return; }
                if (response.ok) {
                    const status = await response.json();
                    if (['successful', 'failed', 'interrupted', 'up_to_date', 'available', 'unsupported'].includes(status.state)) {
                        window.location.reload(); return;
                    }
                    this.reconnectMessage = status.message;
                } else {
                    this.reconnectMessage = 'Services are restarting. Waiting for the panel to return.';
                }
            } catch {
                this.reconnectMessage = 'Services are restarting. Waiting for the panel to return.';
            }
            this.timer = setTimeout(() => this.reconnect(), 5000);
        },
        destroy() { clearTimeout(this.timer); }
    }"
    x-init="@if ($busy) begin() @endif"
    @manager-update-started.window="begin()">
    <div class="border-b border-border pb-4">
        <h2 id="manager-updates-heading" class="text-base font-semibold">Manager updates</h2>
        <p class="mt-1 text-sm text-muted-foreground">Keep Laravel Manager current on this server.</p>
    </div>

    <dl class="grid gap-4 py-5 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-muted-foreground">Installed version</dt>
            <dd class="mt-1 font-mono">{{ $updateStatus['installed_version'] ?? substr($updateStatus['installed_commit'] ?? '', 0, 12) ?: 'Not available' }}</dd>
        </div>
        <div>
            <dt class="text-muted-foreground">Available commit</dt>
            <dd class="mt-1 font-mono">{{ isset($updateStatus['available_commit']) ? substr($updateStatus['available_commit'], 0, 12) : 'Not checked' }}</dd>
        </div>
        @if (isset($updateStatus['checked_at']))
            <div>
                <dt class="text-muted-foreground">Last checked (UTC)</dt>
                <dd class="mt-1">{{ \Carbon\Carbon::parse($updateStatus['checked_at'])->format('M j, Y · H:i') }}</dd>
            </div>
        @endif
        @if ($updateStatus['last_result'] ?? null)
            <div>
                <dt class="text-muted-foreground">Last update</dt>
                <dd class="mt-1">{{ $updateStatus['last_result'] === 'successful' ? 'Successful' : 'Failed' }}@if (isset($updateStatus['finished_at'])) · {{ \Carbon\Carbon::parse($updateStatus['finished_at'])->format('M j · H:i') }} UTC @endif</dd>
            </div>
        @endif
    </dl>

    <p role="status" class="text-sm {{ in_array($state, ['failed', 'interrupted'], true) ? 'text-destructive' : 'text-foreground' }}">{{ $updateStatus['message'] ?? 'Panel updates are unavailable.' }}</p>
    @if ($busy && isset($phaseLabels[$updateStatus['phase'] ?? '']))
        <p class="mt-2 text-sm text-muted-foreground">Current step: {{ $phaseLabels[$updateStatus['phase']] }}</p>
    @endif
    <p x-cloak x-show="reconnecting" x-text="reconnectMessage" role="status" class="mt-2 text-sm text-muted-foreground"></p>

    @if (! empty($updateStatus['history']))
        <details class="mt-3 text-sm">
            <summary class="cursor-pointer text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">Update steps</summary>
            <ol class="mt-2 space-y-1 text-muted-foreground">
                @foreach ($updateStatus['history'] as $step)
                    <li>{{ $phaseLabels[$step['phase']] }} · {{ \Carbon\Carbon::parse($step['at'])->format('H:i:s') }} UTC</li>
                @endforeach
            </ol>
        </details>
    @endif

    @error('managerUpdate')
        <p role="alert" class="mt-3 text-sm text-destructive">{{ $message }}</p>
    @enderror

    @if (in_array($state, ['failed', 'interrupted'], true))
        <p class="mt-3 text-sm text-muted-foreground">If the panel cannot recover, run this on the server. Database migrations are not rolled back automatically.</p>
        <code class="mt-2 block overflow-x-auto rounded-md bg-secondary px-3 py-2 text-sm">sudo laravel-manager update</code>
    @endif

    <div class="mt-5 flex flex-wrap gap-2">
        <april:button variant="outline" wire:click="check" wire:loading.attr="disabled" :disabled="! $supported || $busy" x-bind:disabled="reconnecting || {{ ! $supported || $busy ? 'true' : 'false' }}">
            <span wire:loading.remove wire:target="check">Check for updates</span>
            <span wire:loading wire:target="check">Checking…</span>
        </april:button>
        @if ($canStart)
            <april:alert-dialog>
                <x-slot:trigger>
                    <april:button wire:loading.attr="disabled" x-bind:disabled="reconnecting">{{ $state === 'available' ? 'Update now' : 'Retry update' }}</april:button>
                </x-slot:trigger>
                <x-slot:content class="max-w-[calc(100%-2rem)] sm:max-w-lg" aria-labelledby="confirm-manager-update-title" aria-describedby="confirm-manager-update-description">
                    <april:alert-dialog-header>
                        <x-slot:title id="confirm-manager-update-title">Update Laravel Manager?</x-slot:title>
                        <x-slot:description id="confirm-manager-update-description">The panel and managed applications may be briefly unavailable while Apache and PHP-FPM restart. Wait for app operations to finish first. The installed GitHub branch will be checked again. Database migrations cannot be rolled back automatically.</x-slot:description>
                    </april:alert-dialog-header>
                    <april:alert-dialog-footer>
                        <april:alert-dialog-cancel>Cancel</april:alert-dialog-cancel>
                        <april:alert-dialog-action wire:click="start" wire:loading.attr="disabled">Confirm update</april:alert-dialog-action>
                    </april:alert-dialog-footer>
                </x-slot:content>
            </april:alert-dialog>
        @endif
    </div>
</section>
