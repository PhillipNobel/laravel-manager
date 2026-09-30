<div class="max-w-2xl space-y-7">
    @error('managerUpdate')
        <p role="alert" class="rounded-md border border-destructive/30 px-4 py-3 text-sm text-destructive">{{ $message }}</p>
    @enderror

    <div>
        <a href="{{ route('apps.index') }}" class="inline-flex items-center gap-2 text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline">
            <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m15 18-6-6 6-6" />
            </svg>
            Apps
        </a>
        <h1 class="mt-4 text-2xl font-semibold tracking-tight">Create App</h1>
        <p class="mt-1.5 text-sm text-muted-foreground">Choose an existing repository or create a private Laravel repository.</p>
    </div>

    @if (! $githubConnected)
        <div class="space-y-3 rounded-lg border border-border bg-card p-5" role="status">
            <div>
                <h2 class="text-sm font-medium">Connect GitHub before creating an application.</h2>
                <p class="mt-1 text-sm leading-6 text-muted-foreground">Laravel Manager needs access to a repository before it can create an application.</p>
            </div>
            <april:button-link href="{{ route('settings') }}" variant="outline">Open Settings</april:button-link>
        </div>
    @endif

    @if ($githubConnected)
    <form wire:submit="save" class="space-y-6">
        <div class="space-y-2">
            <label for="name" class="text-sm font-medium">Application name</label>
            <april:input id="name" wire:model="name" placeholder="Customer Portal" autocomplete="off" :aria-describedby="$errors->has('name') ? 'name-hint name-error' : 'name-hint'" :aria-invalid="$errors->has('name') ? 'true' : 'false'" />
            <p id="name-hint" class="text-sm text-muted-foreground">A name to identify this app in Laravel Manager.</p>
            @error('name')
                <p id="name-error" class="text-sm text-destructive" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div class="space-y-2">
            <label for="subdomain" class="text-sm font-medium">Subdomain</label>
            <april:input id="subdomain" wire:model.live.debounce.200ms="subdomain" placeholder="customer" autocomplete="off" autocapitalize="none" spellcheck="false" :aria-describedby="$errors->has('subdomain') ? 'subdomain-hint subdomain-error' : 'subdomain-hint'" :aria-invalid="$errors->has('subdomain') ? 'true' : 'false'" />
            <p id="subdomain-hint" class="text-sm text-muted-foreground">Use lowercase letters, numbers, and hyphens.</p>
            @error('subdomain')
                <p id="subdomain-error" class="text-sm text-destructive" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div class="space-y-2">
            <span class="text-sm font-medium">Domain preview</span>
            <div class="flex min-h-11 items-center rounded-md border border-border bg-muted/50 px-3 py-2" aria-live="polite">
                <code class="break-all font-mono text-sm text-foreground">{{ $this->domainPreview ?? 'Enter a valid subdomain to preview the domain.' }}</code>
            </div>
        </div>

        <div class="space-y-2">
            <label for="repositorySource" class="text-sm font-medium">Repository</label>
            <april:native-select id="repositorySource" wire:model.live.change="repositorySource" class="w-full" :aria-describedby="$errors->has('repositorySource') ? 'repository-source-hint repositorySource-error' : 'repository-source-hint'" :aria-invalid="$errors->has('repositorySource') ? 'true' : 'false'">
                <option value="existing">Use an existing repository</option>
                <option value="new">Create a new private repository</option>
            </april:native-select>
            <p id="repository-source-hint" class="text-sm text-muted-foreground">New repositories are private and start with a Laravel application skeleton.</p>
            @error('repositorySource')
                <p id="repositorySource-error" class="text-sm text-destructive" role="alert">{{ $message }}</p>
            @enderror
        </div>

        @if ($repositorySource === 'existing')
            @if ($repositoryLoadError)
                <div class="rounded-md border border-destructive/30 bg-destructive/5 px-3.5 py-3 text-sm leading-6" role="alert">
                    {{ $repositoryLoadError }} Choose “Create a new private repository” to continue, or reconnect GitHub in Settings.
                    @error('repositoryName')
                        <span class="mt-1 block">{{ $message }}</span>
                    @enderror
                </div>
            @elseif (count($repositories) === 0)
                <div class="rounded-md border border-border bg-muted/50 px-3.5 py-3 text-sm leading-6 text-muted-foreground" role="status">
                    No GitHub repositories are available. Choose “Create a new private repository” above to start a Laravel app.
                    @error('repositoryName')
                        <span class="mt-1 block text-destructive">{{ $message }}</span>
                    @enderror
                </div>
            @else
                <div class="space-y-2">
                    <label for="repositoryName" class="text-sm font-medium">Existing repository</label>
                    <april:native-select id="repositoryName" wire:model.live.change="repositoryName" class="w-full" :aria-describedby="$errors->has('repositoryName') ? 'repository-hint repositoryName-error' : 'repository-hint'" :aria-invalid="$errors->has('repositoryName') ? 'true' : 'false'">
                        <option value="">Select a repository</option>
                        @foreach ($repositories as $repository)
                            <option value="{{ $repository['full_name'] }}">{{ $repository['full_name'] }} · {{ $repository['visibility'] }}</option>
                        @endforeach
                    </april:native-select>
                    <p id="repository-hint" class="text-sm text-muted-foreground">Choose a repository available to the connected GitHub account.</p>
                    @error('repositoryName')
                        <p id="repositoryName-error" class="text-sm text-destructive" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            @endif
        @else
            <div class="space-y-2 rounded-md border border-border bg-muted/40 px-3.5 py-3">
                <p class="text-sm font-medium">New private repository</p>
                @if ($this->domainPreview)
                    <code class="block break-all font-mono text-sm text-foreground">{{ $githubLogin }}/{{ strtolower(trim($subdomain)) }}</code>
                @else
                    <p class="text-sm text-muted-foreground">Enter a valid subdomain to preview the repository name.</p>
                @endif
                <p class="text-sm leading-6 text-muted-foreground">Laravel Manager creates the repository and pushes the starter to <code class="font-mono text-xs">main</code>. Dependencies are installed during deployment.</p>
            </div>
        @endif

        <div class="grid gap-5 sm:grid-cols-2">
            @if ($repositorySource === 'existing')
                <div class="space-y-2">
                    <label for="branch" class="text-sm font-medium">Branch</label>
                    <april:input id="branch" wire:model="branch" autocomplete="off" :aria-describedby="$errors->has('branch') ? 'branch-error' : null" :aria-invalid="$errors->has('branch') ? 'true' : 'false'" />
                    @error('branch')
                        <p id="branch-error" class="text-sm text-destructive" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            @else
                <div class="space-y-2">
                    <span class="text-sm font-medium">Branch</span>
                    <div class="flex h-10 items-center rounded-md border border-border bg-muted/50 px-3 text-sm text-foreground"><code class="font-mono">main</code></div>
                    <p class="text-sm text-muted-foreground">The starter's initial branch is <code class="font-mono text-xs">main</code>.</p>
                </div>
            @endif

            <div class="space-y-2">
                <label for="phpVersion" class="text-sm font-medium">PHP version</label>
                <april:native-select id="phpVersion" wire:model.live="phpVersion" class="w-full" :aria-describedby="$errors->has('phpVersion') ? 'phpVersion-hint phpVersion-error' : 'phpVersion-hint'" :aria-invalid="$errors->has('phpVersion') ? 'true' : 'false'">
                    @if (! $hasAvailablePhpVersion)
                        <option value="" disabled selected>No PHP-FPM runtime available</option>
                    @endif
                    @foreach ($phpOptions as $option)
                        <option value="{{ $option['value'] }}" @disabled(! $option['available'])>
                            PHP {{ $option['value'] }}{{ $option['available'] ? '' : ' (unavailable)' }}
                        </option>
                    @endforeach
                </april:native-select>
                <p id="phpVersion-hint" class="text-sm text-muted-foreground">The selected PHP-FPM version will run this application.</p>
                @foreach ($phpOptions as $option)
                    @if (! $option['available'])
                        <p class="text-xs leading-5 text-muted-foreground">PHP {{ $option['value'] }}: {{ $option['requirement'] }}</p>
                    @endif
                @endforeach
                @error('phpVersion')
                    <p id="phpVersion-error" class="text-sm text-destructive" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-2">
                <label for="databaseEngine" class="text-sm font-medium">Database engine</label>
                <april:native-select id="databaseEngine" wire:model="databaseEngine" class="w-full" :aria-describedby="$errors->has('databaseEngine') ? 'databaseEngine-hint databaseEngine-error' : 'databaseEngine-hint'" :aria-invalid="$errors->has('databaseEngine') ? 'true' : 'false'">
                    @if (! $hasAvailableDatabaseEngine)
                        <option value="" disabled selected>No database engine available</option>
                    @endif
                    @foreach ($databaseOptions as $option)
                        <option value="{{ $option['value'] }}" @disabled(! $option['available'])>
                            {{ $option['label'] }}{{ $option['available'] ? '' : ' (unavailable)' }}
                        </option>
                    @endforeach
                </april:native-select>
                <p id="databaseEngine-hint" class="text-sm text-muted-foreground">Each app gets its own database and account.</p>
                @foreach ($databaseOptions as $option)
                    @if (! $option['available'])
                        <p class="text-xs leading-5 text-muted-foreground">{{ $option['label'] }}: {{ $option['requirement'] }}</p>
                    @endif
                @endforeach
                @error('databaseEngine')
                    <p id="databaseEngine-error" class="text-sm text-destructive" role="alert">{{ $message }}</p>
                @enderror
            </div>
        </div>

        @if (! $hasAvailablePhpVersion)
            <p class="rounded-md border border-border bg-muted/50 px-3.5 py-3 text-sm leading-6 text-muted-foreground" role="status">
                No supported PHP-FPM runtime is ready. Install and start a PHP 8.2, 8.3, or 8.4 runtime before creating an app.
            </p>
        @endif

        <p class="rounded-md bg-muted/60 px-3.5 py-3 text-sm leading-6 text-muted-foreground">Laravel Manager prepares the app directory and production environment. For a new repository, it creates only the Laravel starter; dependencies are installed on deployment.</p>

        <div class="flex flex-col-reverse gap-3 border-t border-border pt-5 sm:flex-row sm:justify-end">
            <april:button-link href="{{ route('apps.index') }}" variant="outline">Cancel</april:button-link>
            <april:button type="submit" wire:loading.attr="disabled" wire:target="save" :disabled="! $hasAvailablePhpVersion || ! $hasAvailableDatabaseEngine">
                <span wire:loading.remove wire:target="save">Create App</span>
                <span wire:loading wire:target="save">Creating application…</span>
            </april:button>
        </div>
    </form>
    @endif
</div>
