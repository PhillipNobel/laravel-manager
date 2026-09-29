<div class="max-w-5xl space-y-8">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Repositories</h1>
            <p class="mt-1.5 text-sm text-muted-foreground">Repositories available to <span class="font-medium text-foreground">{{ $accountLogin }}</span>.</p>
        </div>
        <april:button-link href="{{ route('settings') }}" variant="outline">Back to Settings</april:button-link>
    </div>

    @if (session('error'))
        <p role="alert" class="rounded-md border border-destructive/30 bg-destructive/10 px-3.5 py-3 text-sm text-destructive">{{ session('error') }}</p>
    @endif

    @if ($repositories === [])
        <div class="border-y border-border py-10 text-center">
            <h2 class="text-base font-semibold">No repositories found</h2>
            <p class="mx-auto mt-2 max-w-md text-sm text-muted-foreground">This GitHub account does not have any repositories available to Laravel Manager.</p>
        </div>
    @else
        <div class="border-t border-border">
            <ul aria-label="GitHub repositories" class="divide-y divide-border">
                @foreach ($repositories as $repository)
                    <li class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            @if ($repository['url'])
                                <a href="{{ $repository['url'] }}" target="_blank" rel="noreferrer" class="break-all text-sm font-medium text-foreground underline-offset-4 hover:underline focus-visible:rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">{{ $repository['full_name'] }}</a>
                            @else
                                <p class="break-all text-sm font-medium">{{ $repository['full_name'] }}</p>
                            @endif
                            <p class="mt-1 text-xs text-muted-foreground">Default branch: <span class="font-mono">{{ $repository['default_branch'] }}</span></p>
                        </div>
                        <april:badge variant="outline" class="self-start sm:self-auto">{{ $repository['visibility'] }}</april:badge>
                    </li>
                @endforeach
            </ul>
            <p class="border-t border-border py-3 text-xs text-muted-foreground">Showing up to 100 repositories, sorted by recent activity.</p>
        </div>
    @endif
</div>
