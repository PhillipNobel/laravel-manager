<section aria-labelledby="automatic-heading" class="border-t border-border pt-6" @if ($project->automatic_deployment && $project->webhook_status === 'configured') wire:poll.15s @endif>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 id="automatic-heading" class="text-base font-semibold">Deployment mode</h2>
            <p class="mt-1 text-sm text-muted-foreground">Saved branch: <span class="font-mono text-foreground">{{ $project->branch }}</span>.</p>
        </div>
        <april:badge variant="secondary">{{ $project->automatic_deployment ? 'Automatic' : 'Manual' }}</april:badge>
    </div>
    <div class="mt-4 flex flex-wrap gap-2" role="group" aria-label="Deployment mode">
        <april:button type="button" :variant="$project->automatic_deployment ? 'outline' : 'default'" wire:click="setDeploymentMode('manual')" wire:loading.attr="disabled" wire:target="setDeploymentMode" :aria-pressed="$project->automatic_deployment ? 'false' : 'true'">Manual updates</april:button>
        <april:button type="button" :variant="$project->automatic_deployment ? 'default' : 'outline'" wire:click="setDeploymentMode('automatic')" wire:loading.attr="disabled" wire:target="setDeploymentMode" :aria-pressed="$project->automatic_deployment ? 'true' : 'false'">Automatic updates</april:button>
    </div>
    @error('deploymentMode') <p class="mt-2 text-sm text-destructive" role="alert">{{ $message }}</p> @enderror
    @if (! $project->automatic_deployment)
        <p class="mt-3 text-sm text-muted-foreground">Develop locally and push {{ $project->branch }} to GitHub. Then click Update app to deploy the latest code. Pushes alone do not deploy this app.</p>
    @else
        <h3 class="mt-4 text-sm font-medium">Automatic deployment · {{ ucfirst($project->webhook_status) }}</h3>
        <p class="mt-2 text-sm text-muted-foreground" role="status">{{ $project->webhook_message ?: 'Configure the GitHub webhook to receive pushes automatically.' }}</p>
        @if ($project->webhook_verified_at)
            <p class="mt-2 text-xs text-muted-foreground">Last signed ping: {{ $project->webhook_verified_at->diffForHumans() }}.</p>
        @endif
        <div class="mt-3"><april:button type="button" variant="outline" wire:click="configureWebhook" wire:loading.attr="disabled" wire:target="configureWebhook">
            <span wire:loading.remove wire:target="configureWebhook">{{ $project->webhook_id ? 'Check connection / retry' : 'Configure webhook' }}</span>
            <span wire:loading wire:target="configureWebhook">Configuring…</span>
        </april:button></div>
        <p class="mt-3 text-xs text-muted-foreground">Only signed pushes to {{ $project->branch }} deploy this app when its existing deployment requirements are ready. A configured hook is verified only after a signed ping reaches Manager.</p>
    @endif
</section>

@if ($localCommands)
<section aria-labelledby="develop-heading" class="border-t border-border pt-6">
    <h2 id="develop-heading" class="text-base font-semibold">Develop locally</h2>
    @if ($project->publication_status && $project->publication_status !== 'ready')
        <p class="mt-2 text-sm text-muted-foreground">Server preparation is not complete yet. You can prepare your local checkout while Manager finishes publication.</p>
    @endif
    <p class="mt-1 text-sm text-muted-foreground">On your computer: Git, Composer, Node.js/npm and PHP {{ $project->php_version }} with PDO SQLite.</p>
    <p class="mt-2 text-sm text-muted-foreground">These are the standard Laravel starter instructions. For an existing or customized repository, follow <a class="underline underline-offset-4 hover:text-foreground" href="{{ $project->repository_url }}/blob/{{ rawurlencode($project->branch) }}/README.md" target="_blank" rel="noopener noreferrer">its README</a> for additional requirements. GitHub may ask you to sign in locally; Manager credentials are never included.</p>
    <div class="mt-4 space-y-3">
        @foreach ($localCommands as $label => $command)
            <details class="rounded-md border border-border" @if ($loop->first) open @endif>
                <summary class="cursor-pointer px-4 py-3 text-sm font-medium">{{ $label }}</summary>
                <div class="border-t border-border p-4" x-data="{ feedback: '' }">
                    <pre class="max-w-full overflow-x-auto rounded-md bg-muted p-3 text-xs leading-6"><code x-ref="command">{{ $command }}</code></pre>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <april:button type="button" variant="outline" size="sm" x-on:click="(async () => { try { if (!navigator.clipboard) throw new Error(); await navigator.clipboard.writeText($refs.command.textContent); feedback = 'Copied'; } catch { const range = document.createRange(); range.selectNodeContents($refs.command); const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range); feedback = 'Commands selected. Press Ctrl+C or ⌘C to copy.'; } })()">Copy commands</april:button>
                        <span class="text-xs text-muted-foreground" role="status" x-text="feedback"></span>
                    </div>
                </div>
            </details>
            @if ($loop->iteration === 2)
                <aside class="rounded-md border border-border bg-muted/40 p-4 text-sm">
                    <h3 class="font-medium">Before step 3: edit your local .env</h3>
                    <p class="mt-2 text-muted-foreground">Set APP_ENV=local, APP_DEBUG=true, APP_URL=http://localhost:8000 and DB_CONNECTION=sqlite. Remove DB_URL, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD and DB_SOCKET so Laravel uses database/database.sqlite. Use QUEUE_CONNECTION=sync locally. Keep the starter's database cache/session defaults; migrations create their tables.</p>
                    <p class="mt-2 text-muted-foreground">Never copy the server .env or its credentials. Confirm .env is ignored with <code class="font-mono">git check-ignore .env</code>. Generate the key and run this initialization once, not on every workday.</p>
                </aside>
            @endif
        @endforeach
    </div>
    <p class="mt-3 text-xs text-muted-foreground">Open http://localhost:8000. On later days, start Laravel and Vite, develop, then commit and push. For manual updates, click Update app here after pushing. Check deployment history for the result.</p>
</section>
@endif
