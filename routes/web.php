<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\GitHubController;
use App\Http\Controllers\GitHubWebhookController;
use App\Livewire\Apps\Create as CreateProject;
use App\Livewire\Apps\Index as AppsIndex;
use App\Livewire\Apps\Show as ShowProject;
use App\Livewire\GitHub\Repositories as GitHubRepositories;
use App\Livewire\Server\Index as ServerIndex;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\Setup\Index as SetupIndex;
use App\Support\ManagerUpdates;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/apps');

Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::post('/webhooks/github', GitHubWebhookController::class)->name('webhooks.github');

Route::middleware('auth')->group(function () {
    Route::livewire('/setup', SetupIndex::class)->middleware('setup:incomplete')->name('setup');
    Route::get('/settings/github/connect', [GitHubController::class, 'connect'])->name('settings.github.connect');
    Route::get('/settings/github/callback', [GitHubController::class, 'callback'])->name('settings.github.callback');
    Route::post('/logout', LogoutController::class)->name('logout');

    Route::middleware('setup:complete')->group(function () {
        Route::livewire('/apps', AppsIndex::class)->name('apps.index');
        Route::livewire('/apps/create', CreateProject::class)->name('apps.create');
        Route::livewire('/apps/{project}', ShowProject::class)->name('apps.show');
        Route::livewire('/server', ServerIndex::class)->name('server');
        Route::get('/settings/manager-update-status', fn (ManagerUpdates $updates) => response()->json($updates->status()))
            ->name('settings.manager-update-status');
        Route::livewire('/settings', SettingsIndex::class)->name('settings');
        Route::livewire('/settings/github/repositories', GitHubRepositories::class)->name('settings.github.repositories');
        Route::post('/settings/github/disconnect', [GitHubController::class, 'disconnect'])->name('settings.github.disconnect');
    });
});
