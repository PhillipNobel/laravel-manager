<?php

namespace App\Livewire\GitHub;

use App\Models\GitHubConnection;
use App\Support\GitHubApi;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
#[Title('GitHub Repositories')]
class Repositories extends Component
{
    public array $repositories = [];

    public string $accountLogin = '';

    public function mount(GitHubApi $github): void
    {
        $connection = GitHubConnection::query()->firstOrFail();
        $this->accountLogin = $connection->login;

        try {
            $this->repositories = $github->repositories($connection->access_token);
        } catch (Throwable) {
            session()->flash('error', 'GitHub repositories could not be loaded. Please try again.');
            $this->redirectRoute('settings');
        }
    }

    public function render(): View
    {
        return view('livewire.github.repositories');
    }
}
