<?php

namespace App\Livewire\Settings;

use App\Support\ManagerUpdates;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ManagerUpdate extends Component
{
    #[Locked]
    public array $updateStatus = [];

    public function mount(ManagerUpdates $updates): void
    {
        $this->refreshStatus($updates);
    }

    public function refreshStatus(ManagerUpdates $updates): void
    {
        abort_unless(auth()->check(), 403);
        $this->updateStatus = $updates->status();
    }

    public function check(ManagerUpdates $updates): void
    {
        abort_unless(auth()->check(), 403);
        $this->resetValidation();
        $this->updateStatus = $updates->check();
    }

    public function start(ManagerUpdates $updates): void
    {
        abort_unless(auth()->check(), 403);
        $this->resetValidation();
        $this->updateStatus = $updates->start();
        $this->dispatch('manager-update-started');
    }

    public function render(): View
    {
        return view('livewire.settings.manager-update');
    }
}
