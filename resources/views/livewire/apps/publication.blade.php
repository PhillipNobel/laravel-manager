@if ($project->publication_status)
<section aria-labelledby="publication-heading" class="border-t border-border pt-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <h2 id="publication-heading" class="text-base font-semibold">Initial publication</h2>
        <april:badge variant="secondary">{{ ucfirst($project->publication_status) }}</april:badge>
    </div>
    <p class="mt-3 text-sm text-muted-foreground" role="status" aria-live="polite">{{ $project->publication_message }}</p>
    @if ($project->publication_status !== 'ready')
        <ol class="mt-4 grid gap-2 text-sm sm:grid-cols-2">
            @foreach ($publicationSteps as $step => $label)
                <li class="flex items-center gap-2 {{ $project->publication_step === $step ? 'font-medium text-foreground' : 'text-muted-foreground' }}">
                    <span class="font-mono text-xs">{{ array_search($step, array_keys($publicationSteps)) < array_search($project->publication_step, array_keys($publicationSteps)) ? 'Done' : $loop->iteration.'.' }}</span>
                    {{ $label }}{{ $project->publication_step === $step ? ' — current step' : '' }}
                </li>
            @endforeach
        </ol>
    @else
        <div class="mt-4 flex flex-wrap items-center gap-4">
            <april:button type="button" wire:click="deploy" wire:loading.attr="disabled" wire:target="deploy" :disabled="! $canDeploy">
                <span wire:loading.remove wire:target="deploy">{{ $deploymentInProgress ? 'Update in progress' : 'Update app' }}</span>
                <span wire:loading wire:target="deploy">Queuing…</span>
            </april:button>
            <a class="break-all text-sm font-medium underline underline-offset-4" href="https://{{ $project->domain }}" target="_blank" rel="noopener noreferrer">Open https://{{ $project->domain }}</a>
        </div>
        @if ($deploymentInProgress)
            <p class="mt-3 text-sm text-muted-foreground" role="status">An update is queued or running. Review deployment history below.</p>
        @endif
        @error('deployment') <p class="mt-3 text-sm text-destructive" role="alert">{{ $message }}</p> @enderror
    @endif
    @if ($project->publication_status === 'failed')
        <div class="mt-4"><april:button type="button" wire:click="retryPublication" wire:loading.attr="disabled" wire:target="retryPublication">Retry preparation</april:button></div>
    @endif
    @error('publication') <p class="mt-3 text-sm text-destructive" role="alert">{{ $message }}</p> @enderror
</section>
@endif
