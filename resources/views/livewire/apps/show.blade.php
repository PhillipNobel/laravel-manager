<div class="space-y-8" @if ($deploymentInProgress || $sslProvisioning) wire:poll.10s @endif>
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
        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="truncate text-2xl font-semibold tracking-tight">{{ $project->name }}</h1>
                <p class="mt-1.5 break-all font-mono text-sm text-muted-foreground">{{ $project->domain }}</p>
            </div>
            <div class="self-start sm:self-auto">
                <x-project-status :status="$project->status" />
            </div>
        </div>
    </div>

    <section aria-labelledby="domain-heading">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 id="domain-heading" class="text-base font-semibold">Apache domain</h2>
                <p class="mt-1 text-sm text-muted-foreground">Checks this server's virtual host. Wildcard DNS must already point to this VPS.</p>
            </div>
            <april:badge class="self-start sm:self-auto" :variant="$domainStatus->badgeVariant()">{{ $domainStatus->label() }}</april:badge>
        </div>

        <div class="mt-4 flex flex-col gap-3 border-y border-border py-4 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-muted-foreground" role="status" aria-live="polite">
                {{ $project->domain_message ?: 'The Apache virtual host has not been configured yet.' }}
            </p>
            <div class="flex shrink-0 flex-col gap-2 sm:flex-row">
                <april:button
                    type="button"
                    wire:click="configureDomain"
                    wire:loading.attr="disabled"
                    wire:target="configureDomain"
                    :disabled="! $canConfigureDomain"
                >
                    <span wire:loading.remove wire:target="configureDomain">Configure Apache</span>
                    <span wire:loading wire:target="configureDomain">Configuring…</span>
                </april:button>
                <april:button
                    type="button"
                    variant="outline"
                    wire:click="refreshDomainStatus"
                    wire:loading.attr="disabled"
                    wire:target="refreshDomainStatus"
                    :disabled="! $canConfigureDomain"
                >
                    <span wire:loading.remove wire:target="refreshDomainStatus">Check status</span>
                    <span wire:loading wire:target="refreshDomainStatus">Checking…</span>
                </april:button>
            </div>
        </div>

        <div class="mt-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h3 id="https-heading" class="text-sm font-semibold">HTTPS certificate</h3>
                    <p class="mt-1 text-sm text-muted-foreground">Request a Let's Encrypt certificate and redirect visitors to HTTPS.</p>
                </div>
                <april:badge class="self-start sm:self-auto" :variant="$sslStatus->badgeVariant()">{{ $sslStatus->label() }}</april:badge>
            </div>

            <div class="mt-3 flex flex-col gap-3 border-y border-border py-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="space-y-1">
                    <p class="text-sm text-muted-foreground" role="status" aria-live="polite">
                        {{ $project->ssl_message ?: 'Enable the Apache domain before requesting HTTPS.' }}
                    </p>
                    @if ($sslStatus === \App\Enums\SslStatus::Active && $project->ssl_expires_at)
                        <p class="text-xs text-muted-foreground">
                            Certificate expires <time datetime="{{ $project->ssl_expires_at->toIso8601String() }}">{{ $project->ssl_expires_at->format('M j, Y') }}</time>. Certbot renews it automatically.
                        </p>
                    @endif
                </div>
                <div class="flex shrink-0 flex-col gap-2 sm:flex-row">
                    @if ($sslStatus !== \App\Enums\SslStatus::Active)
                        <april:button
                            type="button"
                            wire:click="enableHttps"
                            wire:loading.attr="disabled"
                            wire:target="enableHttps"
                            :disabled="! $canConfigureSsl || $sslProvisioning"
                        >
                            <span wire:loading.remove wire:target="enableHttps">{{ $sslStatus === \App\Enums\SslStatus::Failed ? 'Retry HTTPS setup' : 'Enable HTTPS' }}</span>
                            <span wire:loading wire:target="enableHttps">Queueing…</span>
                        </april:button>
                    @endif
                    <april:button
                        type="button"
                        variant="outline"
                        wire:click="refreshHttpsStatus"
                        wire:loading.attr="disabled"
                        wire:target="refreshHttpsStatus"
                        :disabled="! $canConfigureSsl || $sslProvisioning"
                    >
                        <span wire:loading.remove wire:target="refreshHttpsStatus">Check HTTPS status</span>
                        <span wire:loading wire:target="refreshHttpsStatus">Checking…</span>
                    </april:button>
                </div>
            </div>
        </div>
    </section>

    <section aria-labelledby="database-heading">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 id="database-heading" class="text-base font-semibold">Application database</h2>
                <p class="mt-1 text-sm text-muted-foreground">A dedicated {{ $project->database_engine?->label() ?? 'database' }} database and user for this Laravel app.</p>
            </div>
            <april:badge class="self-start sm:self-auto" :variant="$databaseStatus->badgeVariant()">{{ $databaseStatus->label() }}</april:badge>
        </div>

        <div class="mt-4 flex flex-col gap-3 border-y border-border py-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1">
                <p class="text-sm text-muted-foreground" role="status" aria-live="polite">
                    {{ $project->database_message ?: 'No application database has been created yet.' }}
                </p>
                @if ($databaseStatus === \App\Enums\DatabaseStatus::Active)
                    <p class="text-xs text-muted-foreground">The database password is stored in the application environment file and is not shown here.</p>
                @endif
            </div>
            @if ($databaseStatus !== \App\Enums\DatabaseStatus::Active)
                <april:button
                    type="button"
                    wire:click="provisionDatabase"
                    wire:loading.attr="disabled"
                    wire:target="provisionDatabase"
                    :disabled="! $canConfigureDatabase"
                >
                    <span wire:loading.remove wire:target="provisionDatabase">{{ $databaseStatus === \App\Enums\DatabaseStatus::Failed ? 'Retry database setup' : 'Create database' }}</span>
                    <span wire:loading wire:target="provisionDatabase">Creating database…</span>
                </april:button>
            @endif
        </div>

        @if ($databaseStatus === \App\Enums\DatabaseStatus::Active)
            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground">Database</dt>
                    <dd class="mt-1 break-all font-mono">{{ $project->database_name }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Database user</dt>
                    <dd class="mt-1 break-all font-mono">{{ $project->database_username }}</dd>
                </div>
            </dl>
        @endif
    </section>

    <section aria-labelledby="project-details-heading">
        <h2 id="project-details-heading" class="text-base font-semibold">Project details</h2>
        <dl class="mt-4 divide-y divide-border border-y border-border">
            <div class="grid gap-1 py-3 sm:grid-cols-[12rem_minmax(0,1fr)] sm:gap-6">
                <dt class="text-sm text-muted-foreground">Repository</dt>
                <dd class="break-all text-sm">{{ $project->repository_url ?: 'Not connected' }}</dd>
            </div>
            <div class="grid gap-1 py-3 sm:grid-cols-[12rem_minmax(0,1fr)] sm:gap-6">
                <dt class="text-sm text-muted-foreground">Branch</dt>
                <dd class="text-sm">{{ $project->branch }}</dd>
            </div>
            <div class="grid gap-1 py-3 sm:grid-cols-[12rem_minmax(0,1fr)] sm:gap-6">
                <dt class="text-sm text-muted-foreground">PHP version</dt>
                <dd class="text-sm">{{ $project->php_version ?: 'Not selected' }}</dd>
            </div>
            <div class="grid gap-1 py-3 sm:grid-cols-[12rem_minmax(0,1fr)] sm:gap-6">
                <dt class="text-sm text-muted-foreground">Database engine</dt>
                <dd class="text-sm">{{ $project->database_engine?->label() ?? 'Not selected' }}</dd>
            </div>
            <div class="grid gap-1 py-3 sm:grid-cols-[12rem_minmax(0,1fr)] sm:gap-6">
                <dt class="text-sm text-muted-foreground">Application path</dt>
                <dd class="break-all font-mono text-sm">{{ $project->path ?: 'Not provisioned' }}</dd>
            </div>
        </dl>
    </section>

    @if ($project->provisioning_log)
        <section aria-labelledby="provisioning-log-heading">
            <div>
                <h2 id="provisioning-log-heading" class="text-base font-semibold">Provisioning log</h2>
                <p class="mt-1 text-sm text-muted-foreground">Steps and messages from application creation.</p>
            </div>
            <pre class="mt-4 max-h-96 overflow-auto whitespace-pre-wrap break-words rounded-md border border-border bg-muted/40 p-4 font-mono text-xs leading-5 text-muted-foreground" role="log" aria-label="Provisioning log">{{ $project->provisioning_log }}</pre>
        </section>
    @endif

    <section aria-labelledby="deployments-heading">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 id="deployments-heading" class="text-base font-semibold">Deployments</h2>
                <p class="mt-1 text-sm text-muted-foreground">Deploy the configured GitHub branch and review recent output.</p>
            </div>
            <april:button
                type="button"
                wire:click="deploy"
                wire:loading.attr="disabled"
                wire:target="deploy"
                :disabled="! $canDeploy"
                class="self-start sm:self-auto"
            >
                <span wire:loading.remove wire:target="deploy">{{ $deploymentInProgress ? 'Deployment in progress' : 'Deploy now' }}</span>
                <span wire:loading wire:target="deploy">Queueing…</span>
            </april:button>
        </div>

        @if (session('status'))
            <p class="mt-4 rounded-md bg-secondary px-3.5 py-3 text-sm text-secondary-foreground" role="status" aria-live="polite">{{ session('status') }}</p>
        @endif

        @error('deployment')
            <p class="mt-4 rounded-md bg-destructive/10 px-3.5 py-3 text-sm text-destructive" role="alert">{{ $message }}</p>
        @enderror

        @if ($deploymentInProgress)
            <p class="mt-4 text-sm text-muted-foreground" role="status" aria-live="polite">A deployment is queued or running. This page refreshes its status automatically.</p>
        @elseif (! $canDeploy)
            <p class="mt-4 text-sm text-muted-foreground">Deploy is available when the application, its database, and GitHub connection are ready.</p>
        @endif

        @if ($deployments->isEmpty())
            <p class="mt-4 border-y border-border py-7 text-sm text-muted-foreground">No deployments yet.</p>
        @else
            <ul class="mt-4 divide-y divide-border border-y border-border">
                @foreach ($deployments as $deployment)
                    <li wire:key="deployment-{{ $deployment->id }}" class="py-4">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <p class="break-words text-sm font-medium">{{ $deployment->commit_message ?: ($deployment->status === \App\Enums\DeploymentStatus::Pending ? 'Waiting for queue worker' : 'Deployment') }}</p>
                                @if ($deployment->commit_hash)
                                    <p class="mt-1 font-mono text-xs text-muted-foreground" title="{{ $deployment->commit_hash }}">{{ \Illuminate\Support\Str::limit($deployment->commit_hash, 12, '') }}</p>
                                @else
                                    <p class="mt-1 text-xs text-muted-foreground">Commit will appear when the configured branch is fetched.</p>
                                @endif
                                <p class="mt-1 text-xs text-muted-foreground">
                                    <time datetime="{{ ($deployment->started_at ?? $deployment->created_at)->toIso8601String() }}">{{ ($deployment->started_at ?? $deployment->created_at)->diffForHumans() }}</time>
                                    @if ($deployment->finished_at)
                                        <span aria-hidden="true">·</span> Finished {{ $deployment->finished_at->diffForHumans() }}
                                    @endif
                                </p>
                            </div>
                            <april:badge class="self-start" :variant="$deployment->status->badgeVariant()">{{ $deployment->status->label() }}</april:badge>
                        </div>
                        @if (filled($deployment->output))
                            <details class="mt-3">
                                <summary class="cursor-pointer text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline">View deployment output</summary>
                                <pre class="mt-3 max-h-96 overflow-auto whitespace-pre-wrap break-words rounded-md border border-border bg-muted/40 p-4 font-mono text-xs leading-5 text-muted-foreground" role="log" aria-label="Deployment output">{{ $deployment->output }}</pre>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
