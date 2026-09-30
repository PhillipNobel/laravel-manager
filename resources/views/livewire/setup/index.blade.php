<div class="mx-auto max-w-5xl space-y-7">
    <header class="space-y-2 border-b border-border pb-5">
        <h1 class="text-2xl font-semibold tracking-tight">Prepare this server for apps</h1>
        <p class="max-w-2xl text-sm leading-6 text-muted-foreground">Confirm the administrator, set the applications domain, and check the server before creating your first app.</p>
    </header>

    <div>
        <div class="sm:hidden" role="progressbar" aria-label="Setup progress" aria-valuemin="1" aria-valuemax="6" aria-valuenow="{{ $currentStep }}" aria-valuetext="Step {{ $currentStep }} of 6: {{ $steps[$currentStep - 1]['label'] }}">
            <div class="flex items-center justify-between gap-3 text-sm">
                <span class="font-medium">{{ $steps[$currentStep - 1]['label'] }}</span>
                <span class="shrink-0 text-xs text-muted-foreground">Step {{ $currentStep }} of 6</span>
            </div>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                <div class="h-full rounded-full bg-primary" style="width: {{ ($currentStep / 6) * 100 }}%"></div>
            </div>
        </div>

        <div class="hidden space-y-3 sm:block">
            <april:steps :items="$steps" :current="$currentStep" />
            <p class="text-right text-xs text-muted-foreground">Step {{ $currentStep }} of 6</p>
        </div>
    </div>

    <section class="rounded-lg border border-border bg-card p-5 sm:p-7" aria-live="polite">
        @if ($currentStep === 1)
            <div class="max-w-2xl space-y-5">
                <div>
                    <h2 class="text-lg font-semibold">Administrator account is ready</h2>
                    <p class="mt-1.5 text-sm leading-6 text-muted-foreground">The installer created your administrator account. This account is the only way to access Laravel Manager; public registration is disabled.</p>
                </div>

                <dl class="grid gap-3 border-y border-border py-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium text-muted-foreground">Name</dt>
                        <dd class="mt-1 text-sm font-medium">{{ auth()->user()->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-muted-foreground">Email</dt>
                        <dd class="mt-1 break-all text-sm font-medium">{{ auth()->user()->email }}</dd>
                    </div>
                </dl>
            </div>
        @elseif ($currentStep === 2)
            <div class="max-w-2xl space-y-6">
                <div>
                    <h2 class="text-lg font-semibold">Set your server defaults</h2>
                    <p class="mt-1.5 text-sm leading-6 text-muted-foreground">These values are used to generate app domains and choose defaults. You can change them later in Settings.</p>
                </div>

                <div class="space-y-5">
                    <div class="space-y-2">
                        <label for="setupBaseDomain" class="text-sm font-medium">Base applications domain</label>
                        <april:input id="setupBaseDomain" wire:model.blur="baseDomain" placeholder="apps.example.com" autocomplete="off" autocapitalize="none" spellcheck="false" :aria-describedby="$errors->has('baseDomain') ? 'setupBaseDomain-help setupBaseDomain-error' : 'setupBaseDomain-help'" :aria-invalid="$errors->has('baseDomain') ? 'true' : 'false'" />
                        <p id="setupBaseDomain-help" class="text-sm text-muted-foreground">Enter the domain below your wildcard, without a protocol. Example: apps.example.com.</p>
                        @error('baseDomain')
                            <p id="setupBaseDomain-error" class="text-sm text-destructive" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-2">
                        <label for="setupPublicIp" class="text-sm font-medium">Server public IP</label>
                        <april:input id="setupPublicIp" wire:model.blur="publicIp" placeholder="203.0.113.10" autocomplete="off" spellcheck="false" :aria-describedby="$errors->has('publicIp') ? 'setupPublicIp-help setupPublicIp-error' : 'setupPublicIp-help'" :aria-invalid="$errors->has('publicIp') ? 'true' : 'false'" />
                        <p id="setupPublicIp-help" class="text-sm text-muted-foreground">The wildcard DNS record should point to this server address.</p>
                        @error('publicIp')
                            <p id="setupPublicIp-error" class="text-sm text-destructive" role="alert">Enter a valid IPv4 or IPv6 address.</p>
                        @enderror
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="space-y-2">
                            <label for="setupApplicationsDirectory" class="text-sm font-medium">Applications directory</label>
                            <april:input id="setupApplicationsDirectory" wire:model.blur="applicationsDirectory" autocomplete="off" spellcheck="false" :aria-describedby="$errors->has('applicationsDirectory') ? 'setupApplicationsDirectory-error' : null" :aria-invalid="$errors->has('applicationsDirectory') ? 'true' : 'false'" />
                            @error('applicationsDirectory')
                                <p id="setupApplicationsDirectory-error" class="text-sm text-destructive" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="space-y-2">
                            <label for="setupDefaultPhpVersion" class="text-sm font-medium">Default PHP for new apps</label>
                            <april:native-select id="setupDefaultPhpVersion" wire:model.live.change="defaultPhpVersion" class="w-full" :aria-describedby="$errors->has('defaultPhpVersion') ? 'setupDefaultPhpVersion-error' : null" :aria-invalid="$errors->has('defaultPhpVersion') ? 'true' : 'false'">
                                @foreach (config('manager.php_versions') as $version)
                                    <option value="{{ $version }}">{{ $version }}</option>
                                @endforeach
                            </april:native-select>
                            @error('defaultPhpVersion')
                                <p id="setupDefaultPhpVersion-error" class="text-sm text-destructive" role="alert">Choose a supported PHP version.</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>
        @elseif ($currentStep === 3)
            <div class="space-y-5">
                <div>
                    <h2 class="text-lg font-semibold">Check the server</h2>
                    <p class="mt-1.5 max-w-2xl text-sm leading-6 text-muted-foreground">Laravel Manager checks installed tools, database services, and PHP-FPM runtimes. These checks only read local status; they do not install or change software.</p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <april:button type="button" variant="outline" wire:click="checkServer" wire:loading.attr="disabled" wire:target="checkServer">
                        <span wire:loading.remove wire:target="checkServer">{{ $serverChecked ? 'Run checks again' : 'Run server checks' }}</span>
                        <span wire:loading wire:target="checkServer">Checking…</span>
                    </april:button>
                    @if ($serverChecked)
                        <p role="status" class="text-sm text-muted-foreground">{{ $availableRequirements }} of {{ count($requirements) }} checks available.</p>
                    @endif
                </div>

                @error('requirements')
                    <p class="text-sm text-destructive" role="alert">{{ $message }}</p>
                @enderror

                @if ($serverChecked)
                    <ul class="divide-y divide-border border-y border-border" aria-label="Server software checks">
                        @foreach ($requirements as $requirement)
                            <li class="grid gap-2 py-3 sm:grid-cols-[9rem_minmax(0,1fr)_auto] sm:items-center sm:gap-4">
                                <span class="text-sm font-medium">{{ $requirement['name'] }}</span>
                                <span class="min-w-0 break-words text-xs text-muted-foreground">{{ $requirement['version'] }}</span>
                                <span>
                                    <april:badge :variant="$requirement['status'] === 'available' ? 'secondary' : 'outline'">
                                        {{ $requirement['status'] === 'available' ? 'Available' : 'Not detected' }}
                                    </april:badge>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="text-sm text-muted-foreground">Missing items are listed on the Server page after setup. Some app options may stay unavailable until their requirements are installed.</p>
                @endif
            </div>
        @elseif ($currentStep === 4)
            <div class="max-w-2xl space-y-5">
                <div>
                    <h2 class="text-lg font-semibold">Connect GitHub</h2>
                    <p class="mt-1.5 text-sm leading-6 text-muted-foreground">Connect the account that can access your Laravel repositories. You can also do this later in Settings.</p>
                </div>

                <div class="flex flex-col gap-4 border-y border-border py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="space-y-1">
                        @if ($githubConnection)
                            <p class="text-sm font-medium">Connected as {{ $githubConnection->login }}</p>
                            <p class="text-sm text-muted-foreground">Laravel Manager can access repositories available to this account.</p>
                        @elseif ($githubConfigured)
                            <p class="text-sm font-medium">GitHub is not connected</p>
                            <p class="text-sm text-muted-foreground">Authorize the configured GitHub OAuth app to continue.</p>
                        @else
                            <p class="text-sm font-medium">GitHub OAuth is not configured</p>
                            <p class="max-w-lg text-sm leading-5 text-muted-foreground">Add GITHUB_CLIENT_ID and GITHUB_CLIENT_SECRET to the server environment before connecting. You can finish setup and add them later.</p>
                        @endif
                    </div>
                    @if ($githubConnection)
                        <april:badge variant="secondary">Connected</april:badge>
                    @elseif ($githubConfigured)
                        <april:button-link href="{{ route('settings.github.connect') }}">Connect GitHub</april:button-link>
                    @else
                        <april:badge variant="outline">Optional</april:badge>
                    @endif
                </div>
            </div>
        @elseif ($currentStep === 5)
            <div class="max-w-2xl space-y-5">
                <div>
                    <h2 class="text-lg font-semibold">Confirm wildcard DNS</h2>
                    <p class="mt-1.5 text-sm leading-6 text-muted-foreground">Add this record with your DNS provider. Laravel Manager does not create DNS records or query Cloudflare.</p>
                </div>

                <dl class="grid gap-4 border-y border-border py-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs font-medium text-muted-foreground">Name</dt>
                        <dd class="mt-1 break-all font-mono text-sm">*.{{ $baseDomain }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-muted-foreground">Type</dt>
                        <dd class="mt-1 font-mono text-sm">{{ $dnsRecordType }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-muted-foreground">Points to</dt>
                        <dd class="mt-1 break-all font-mono text-sm">{{ $publicIp }}</dd>
                    </div>
                </dl>

                <div class="flex items-start gap-3">
                    <april:checkbox id="wildcardDnsConfirmed" wire:model="wildcardDnsConfirmed" value="1" :aria-describedby="$errors->has('wildcardDnsConfirmed') ? 'wildcardDnsConfirmed-error' : null" :aria-invalid="$errors->has('wildcardDnsConfirmed') ? 'true' : 'false'" />
                    <div class="space-y-1">
                        <label for="wildcardDnsConfirmed" class="text-sm font-medium">I have added this wildcard record and confirmed it resolves to this server.</label>
                        <p class="text-sm text-muted-foreground">DNS changes can take time to propagate. You can verify them with your DNS provider.</p>
                        @error('wildcardDnsConfirmed')
                            <p id="wildcardDnsConfirmed-error" class="text-sm text-destructive" role="alert">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>
        @else
            <div class="max-w-2xl space-y-5">
                <div>
                    <h2 class="text-lg font-semibold">Laravel Manager is ready</h2>
                    <p class="mt-1.5 text-sm leading-6 text-muted-foreground">Your server settings are saved. You can revisit them later, connect GitHub from Settings, and create your first app when you're ready.</p>
                </div>

                <dl class="grid gap-3 border-y border-border py-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium text-muted-foreground">Applications domain</dt>
                        <dd class="mt-1 break-all font-mono text-sm">*.{{ $baseDomain }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-muted-foreground">Public IP</dt>
                        <dd class="mt-1 break-all font-mono text-sm">{{ $publicIp }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-muted-foreground">Applications directory</dt>
                        <dd class="mt-1 break-all font-mono text-sm">{{ $applicationsDirectory }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-muted-foreground">GitHub</dt>
                        <dd class="mt-1 text-sm">{{ $githubConnection ? 'Connected as '.$githubConnection->login : 'Not connected' }}</dd>
                    </div>
                </dl>
            </div>
        @endif

        <footer class="mt-7 flex flex-col-reverse gap-3 border-t border-border pt-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
                @if ($currentStep > 1)
                    <april:button type="button" variant="outline" wire:click="back" wire:loading.attr="disabled">Back</april:button>
                @endif
            </div>

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center">
                @if ($currentStep < 6)
                    <april:button type="button" wire:click="next" wire:loading.attr="disabled" wire:target="next">
                        <span wire:loading.remove wire:target="next">Continue</span>
                        <span wire:loading wire:target="next">Saving…</span>
                    </april:button>
                @else
                    <april:button type="button" wire:click="finish" wire:loading.attr="disabled" wire:target="finish">
                        <span wire:loading.remove wire:target="finish">Finish setup</span>
                        <span wire:loading wire:target="finish">Finishing…</span>
                    </april:button>
                @endif
            </div>
        </footer>
    </section>
</div>
