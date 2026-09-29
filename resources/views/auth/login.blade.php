@extends('layouts.guest')

@section('content')
<div>
    <div class="mb-8 flex items-center gap-3">
        <span class="flex size-10 items-center justify-center rounded-lg bg-primary text-base font-bold text-primary-foreground" aria-hidden="true">LM</span>
        <span class="text-sm font-semibold tracking-tight">Laravel Manager</span>
    </div>

    <div class="mb-7">
        <h1 class="text-2xl font-semibold tracking-tight">Sign in</h1>
        <p class="mt-2 text-sm text-muted-foreground">Manage Laravel applications on this server.</p>
    </div>

    <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
        @csrf

        <div class="space-y-2">
            <label for="email" class="text-sm font-medium">Email address</label>
            <april:input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required :aria-describedby="$errors->has('email') ? 'email-error' : null" :aria-invalid="$errors->has('email') ? 'true' : 'false'" />
            @error('email')
                <p id="email-error" class="text-sm text-destructive" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div class="space-y-2">
            <label for="password" class="text-sm font-medium">Password</label>
            <april:input id="password" name="password" type="password" autocomplete="current-password" required :aria-describedby="$errors->has('password') ? 'password-error' : null" :aria-invalid="$errors->has('password') ? 'true' : 'false'" />
            @error('password')
                <p id="password-error" class="text-sm text-destructive" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <april:button type="submit" class="w-full">Sign in</april:button>
    </form>
</div>
@endsection
