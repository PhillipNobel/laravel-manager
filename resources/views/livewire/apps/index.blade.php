<div class="space-y-7">
    @if (session('status'))
        <p role="status" class="rounded-md bg-secondary px-3.5 py-3 text-sm text-secondary-foreground">{{ session('status') }}</p>
    @endif

    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Apps</h1>
            <p class="mt-1.5 text-sm text-muted-foreground">Manage the Laravel applications on this server.</p>
        </div>
        @if ($projects->isNotEmpty())
            <april:button-link href="{{ route('apps.create') }}">
                <svg aria-hidden="true" class="-ml-1 size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14M5 12h14" />
                </svg>
                Create App
            </april:button-link>
        @endif
    </div>

    @if ($projects->isEmpty())
        <section class="mt-10 border-y border-border py-16 text-center sm:py-20">
            <div class="mx-auto flex size-11 items-center justify-center rounded-lg bg-muted text-muted-foreground" aria-hidden="true">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="16" rx="2" />
                    <path d="M3 9h18M8 9v11" />
                </svg>
            </div>
            <h2 class="mt-5 text-lg font-semibold tracking-tight">No applications yet</h2>
            <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-muted-foreground">Applications you add to Laravel Manager will appear here.</p>
            <april:button-link href="{{ route('apps.create') }}" class="mt-6">
                Create your first app
            </april:button-link>
        </section>
    @else
        <div class="hidden overflow-hidden rounded-lg border border-border bg-card lg:block">
            <table class="w-full table-fixed text-left text-sm">
                <caption class="sr-only">Managed Laravel applications</caption>
                <thead class="bg-muted/60 text-xs font-medium text-muted-foreground">
                    <tr>
                        <th scope="col" class="w-[22%] px-4 py-3">Application</th>
                        <th scope="col" class="w-[25%] px-4 py-3">Domain</th>
                        <th scope="col" class="w-[19%] px-4 py-3">Repository</th>
                        <th scope="col" class="w-[12%] px-4 py-3">Branch</th>
                        <th scope="col" class="w-[9%] px-4 py-3">PHP</th>
                        <th scope="col" class="w-[13%] px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($projects as $project)
                        <tr class="transition-colors hover:bg-muted/40">
                            <td class="px-4 py-3.5">
                                <a href="{{ route('apps.show', $project) }}" class="block truncate font-medium text-foreground underline-offset-4 hover:underline">{{ $project->name }}</a>
                            </td>
                            <td class="px-4 py-3.5"><span class="block truncate font-mono text-xs text-muted-foreground">{{ $project->domain }}</span></td>
                            <td class="px-4 py-3.5"><span class="block truncate text-muted-foreground">{{ $project->repository_name ?: 'Not connected' }}</span></td>
                            <td class="px-4 py-3.5"><span class="block truncate text-muted-foreground">{{ $project->branch }}</span></td>
                            <td class="px-4 py-3.5 text-muted-foreground">{{ $project->php_version ?: '—' }}</td>
                            <td class="px-4 py-3.5"><x-project-status :status="$project->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <ul class="divide-y divide-border rounded-lg border border-border bg-card lg:hidden" aria-label="Managed applications">
            @foreach ($projects as $project)
                <li class="px-4 py-4 sm:px-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <a href="{{ route('apps.show', $project) }}" class="block truncate font-medium underline-offset-4 hover:underline">{{ $project->name }}</a>
                            <p class="mt-1 truncate font-mono text-xs text-muted-foreground">{{ $project->domain }}</p>
                        </div>
                        <x-project-status :status="$project->status" />
                    </div>
                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        <span>Repository: {{ $project->repository_name ?: 'Not connected' }}</span>
                        <span>Branch: {{ $project->branch }}</span>
                        <span>PHP {{ $project->php_version ?: '—' }}</span>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
