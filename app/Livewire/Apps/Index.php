<?php

namespace App\Livewire\Apps;

use App\Models\Project;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Apps')]
class Index extends Component
{
    public function render(): View
    {
        return view('livewire.apps.index', [
            'projects' => Project::query()->orderBy('name')->get(),
        ]);
    }
}
