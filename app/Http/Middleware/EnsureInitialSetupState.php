<?php

namespace App\Http\Middleware;

use App\Models\AppSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInitialSetupState
{
    public function handle(Request $request, Closure $next, string $state = 'complete'): Response
    {
        if ($state === 'incomplete' && AppSetting::initialSetupIsComplete()) {
            return redirect()->route('apps.index');
        }

        if ($state !== 'incomplete' && ! AppSetting::initialSetupIsComplete()) {
            return redirect()->route('setup');
        }

        return $next($request);
    }
}
