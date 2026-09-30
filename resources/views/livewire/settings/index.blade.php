<div class="max-w-3xl space-y-8">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">Settings</h1>
        <p class="mt-1.5 text-sm text-muted-foreground">Configure defaults for Laravel apps on this server.</p>
    </div>

    @if (session('status'))
        <p role="status" class="rounded-md bg-secondary px-3.5 py-3 text-sm text-secondary-foreground">{{ session('status') }}</p>
    @endif

    @if (session('error'))
        <p role="alert" class="rounded-md border border-destructive/30 bg-destructive/10 px-3.5 py-3 text-sm text-destructive">{{ session('error') }}</p>
    @endif

    <form wire:submit="save" class="space-y-8">
        <section aria-labelledby="server-settings-heading">
            <div class="border-b border-border pb-4">
                <h2 id="server-settings-heading" class="text-base font-semibold">Server</h2>
                <p class="mt-1 text-sm text-muted-foreground">Server identity and defaults used when adding an application.</p>
            </div>

            <div class="space-y-5 py-5">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="space-y-2">
                        <label for="serverHostname" class="text-sm font-medium">Server hostname</label>
                        <april:input id="serverHostname" wire:model.live.blur="serverHostname" autocomplete="off" spellcheck="false" :aria-describedby="$errors->has('serverHostname') ? 'serverHostname-error' : null" :aria-invalid="$errors->has('serverHostname') ? 'true' : 'false'" />
                        @error('serverHostname')
                            <p id="serverHostname-error" class="text-sm text-destructive" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-2">
                        <label for="publicIp" class="text-sm font-medium">Public IP <span class="font-normal text-muted-foreground">(optional)</span></label>
                        <april:input id="publicIp" wire:model.live.blur="publicIp" autocomplete="off" spellcheck="false" placeholder="203.0.113.10" :aria-describedby="$errors->has('publicIp') ? 'publicIp-hint publicIp-error' : 'publicIp-hint'" :aria-invalid="$errors->has('publicIp') ? 'true' : 'false'" />
                        <p id="publicIp-hint" class="text-sm text-muted-foreground">Leave blank if you have not configured a public IP.</p>
                        @error('publicIp')
                            <p id="publicIp-error" class="text-sm text-destructive" role="alert">Enter a valid IPv4 or IPv6 address.</p>
                        @enderror
                    </div>
                </div>

                <div class="space-y-2">
                    <label for="baseDomain" class="text-sm font-medium">Base applications domain</label>
                    <april:input id="baseDomain" wire:model.live.blur="baseDomain" placeholder="apps.example.com" autocomplete="off" autocapitalize="none" spellcheck="false" :aria-describedby="$errors->has('baseDomain') ? 'base-domain-hint baseDomain-error' : 'base-domain-hint'" :aria-invalid="$errors->has('baseDomain') ? 'true' : 'false'" />
                    <p id="base-domain-hint" class="text-sm text-muted-foreground">Subdomains will be added in front of this domain.</p>
                    @error('baseDomain')
                        <p id="baseDomain-error" class="text-sm text-destructive" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-2">
                    <label for="applicationsDirectory" class="text-sm font-medium">Applications directory</label>
                    <april:input id="applicationsDirectory" wire:model.live.blur="applicationsDirectory" autocomplete="off" spellcheck="false" :aria-describedby="$errors->has('applicationsDirectory') ? 'applications-directory-hint applicationsDirectory-error' : 'applications-directory-hint'" :aria-invalid="$errors->has('applicationsDirectory') ? 'true' : 'false'" />
                    <p id="applications-directory-hint" class="text-sm text-muted-foreground">The server path where applications will be prepared.</p>
                    @error('applicationsDirectory')
                        <p id="applicationsDirectory-error" class="text-sm text-destructive" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-2 sm:max-w-xs">
                    <label for="defaultPhpVersion" class="text-sm font-medium">Default PHP version</label>
                    <april:native-select id="defaultPhpVersion" wire:model.live.change="defaultPhpVersion" class="w-full" :aria-describedby="$errors->has('defaultPhpVersion') ? 'defaultPhpVersion-error' : null" :aria-invalid="$errors->has('defaultPhpVersion') ? 'true' : 'false'">
                        @foreach ($phpVersions as $version)
                            <option value="{{ $version }}">{{ $version }}</option>
                        @endforeach
                    </april:native-select>
                    @error('defaultPhpVersion')
                        <p id="defaultPhpVersion-error" class="text-sm text-destructive" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-2">
                    <label for="managerUrl" class="text-sm font-medium">Laravel Manager URL</label>
                    <april:input id="managerUrl" type="url" wire:model.live.blur="managerUrl" placeholder="https://manager.example.com" autocomplete="url" spellcheck="false" :aria-describedby="$errors->has('managerUrl') ? 'managerUrl-error' : null" :aria-invalid="$errors->has('managerUrl') ? 'true' : 'false'" />
                    <p class="text-sm text-muted-foreground">The URL administrators use to open Laravel Manager.</p>
                    @error('managerUrl')
                        <p id="managerUrl-error" class="text-sm text-destructive" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </section>

        <div class="flex justify-start border-t border-border pt-5">
            <april:button type="submit" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save settings</span>
                <span wire:loading wire:target="save">Saving…</span>
            </april:button>
        </div>
    </form>

    <section aria-labelledby="github-settings-heading" class="border-t border-border pt-6">
        <div class="border-b border-border pb-4">
            <h2 id="github-settings-heading" class="text-base font-semibold">GitHub</h2>
            <p class="mt-1 text-sm text-muted-foreground">Repository access for your applications.</p>
        </div>
        <div class="flex flex-col gap-4 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1">
                @if ($githubConnection)
                    <p class="text-sm font-medium">Connected as {{ $githubConnection->login }}</p>
                    <p class="text-sm text-muted-foreground">Laravel Manager can read repositories available to this account.</p>
                @elseif ($githubConfigured)
                    <p class="text-sm">GitHub connection</p>
                    <p class="text-sm text-muted-foreground">Connect an account to browse its repositories.</p>
                @else
                    <p class="text-sm">GitHub connection</p>
                    <p class="max-w-xl text-sm text-muted-foreground">Add GITHUB_CLIENT_ID and GITHUB_CLIENT_SECRET to the server environment to enable GitHub.</p>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($githubConnection)
                    <april:badge variant="secondary">Connected</april:badge>
                    <april:button-link href="{{ route('settings.github.repositories') }}">Browse repositories</april:button-link>
                    <form method="POST" action="{{ route('settings.github.disconnect') }}">
                        @csrf
                        <april:button type="submit" variant="outline">Disconnect</april:button>
                    </form>
                @else
                    <april:badge variant="outline">Not connected</april:badge>
                    @if ($githubConfigured)
                        <april:button-link href="{{ route('settings.github.connect') }}">Connect GitHub</april:button-link>
                    @endif
                @endif
            </div>
        </div>
    </section>
    <livewire:settings.manager-update />
</div>
