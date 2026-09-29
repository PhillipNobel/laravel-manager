<div class="max-w-2xl space-y-7">
    <div>
        <a href="{{ route('apps.index') }}" class="inline-flex items-center gap-2 text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline">
            <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m15 18-6-6 6-6" />
            </svg>
            Apps
        </a>
        <h1 class="mt-4 text-2xl font-semibold tracking-tight">Create App</h1>
        <p class="mt-1.5 text-sm text-muted-foreground">Choose a GitHub repository and set its application domain.</p>
    </div>

    @if (! $githubConnected)
        <div class="space-y-3 rounded-lg border border-border bg-card p-5" role="status">
            <div>
                <h2 class="text-sm font-medium">Connect GitHub before creating an application.</h2>
                <p class="mt-1 text-sm leading-6 text-muted-foreground">Laravel Manager needs access to a repository before it can create an application.</p>
            </div>
            <april:button-link href="{{ route('settings') }}" variant="outline">Open Settings</april:button-link>
        </div>
    @elseif ($repositoryLoadError)
        <div class="space-y-3 rounded-lg border border-destructive/30 bg-destructive/5 p-5" role="alert">
            <p class="text-sm leading-6">{{ $repositoryLoadError }}</p>
            <april:button-link href="{{ route('settings') }}" variant="outline">Open Settings</april:button-link>
        </div>
    @elseif (count($repositories) === 0)
        <div class="space-y-3 rounded-lg border border-border bg-card p-5" role="status">
            <div>
                <h2 class="text-sm font-medium">No GitHub repositories available</h2>
                <p class="mt-1 text-sm leading-6 text-muted-foreground">Connect an account with access to a repository, then return here.</p>
            </div>
            <april:button-link href="{{ route('settings') }}" variant="outline">Open Settings</april:button-link>
        </div>
    @else
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
            <label for="repositoryName" class="text-sm font-medium">GitHub repository</label>
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

        <div class="grid gap-5 sm:grid-cols-2">
            <div class="space-y-2">
                <label for="branch" class="text-sm font-medium">Branch</label>
                <april:input id="branch" wire:model="branch" autocomplete="off" :aria-describedby="$errors->has('branch') ? 'branch-error' : null" :aria-invalid="$errors->has('branch') ? 'true' : 'false'" />
                @error('branch')
                    <p id="branch-error" class="text-sm text-destructive" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-2">
                <label for="phpVersion" class="text-sm font-medium">PHP version</label>
                <april:native-select id="phpVersion" wire:model="phpVersion" class="w-full" :aria-describedby="$errors->has('phpVersion') ? 'phpVersion-error' : null" :aria-invalid="$errors->has('phpVersion') ? 'true' : 'false'">
                    @foreach ($phpVersions as $version)
                        <option value="{{ $version }}">{{ $version }}</option>
                    @endforeach
                </april:native-select>
                @error('phpVersion')
                    <p id="phpVersion-error" class="text-sm text-destructive" role="alert">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <p class="rounded-md bg-muted/60 px-3.5 py-3 text-sm leading-6 text-muted-foreground">Laravel Manager will clone the selected branch and prepare the app directory and environment. It will not run project code or install dependencies.</p>

        <div class="flex flex-col-reverse gap-3 border-t border-border pt-5 sm:flex-row sm:justify-end">
            <april:button-link href="{{ route('apps.index') }}" variant="outline">Cancel</april:button-link>
            <april:button type="submit" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Create App</span>
                <span wire:loading wire:target="save">Creating application…</span>
            </april:button>
        </div>
    </form>
    @endif
</div>
