<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config(['app.url' => 'http://localhost']);
    URL::forceScheme('http');

    Route::get('/_test/forwarded-scheme', fn (Request $request) => response()->json([
        'secure' => $request->isSecure(),
    ]));
});

it('provides persistent light dark and system theme choices in the authenticated header', function () {
    $response = $this->actingAs(User::factory()->create())
        ->get(route('apps.index'))
        ->assertOk();

    expect($response->getContent())
        ->toContain('Color theme')
        ->toContain('data-theme-option="light"')
        ->toContain('data-theme-option="dark"')
        ->toContain('data-theme-option="system"')
        ->toContain('role="menuitemradio"')
        ->toContain('localStorage')
        ->toContain('prefers-color-scheme');
});

it('recognizes forwarded HTTPS from the local Apache reverse proxy', function () {
    $this->withServerVariables(['HTTPS' => 'off', 'REMOTE_ADDR' => '127.0.0.1', 'SERVER_PORT' => 80])
        ->withHeader('X-Forwarded-Proto', 'https')
        ->get('/_test/forwarded-scheme')
        ->assertJson(['secure' => true]);
});

it('ignores forwarded HTTPS from a remote client', function () {
    $this->withServerVariables(['HTTPS' => 'off', 'REMOTE_ADDR' => '203.0.113.7', 'SERVER_PORT' => 80])
        ->withHeader('X-Forwarded-Proto', 'https')
        ->get('/_test/forwarded-scheme')
        ->assertJson(['secure' => false]);
});
