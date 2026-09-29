<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign in · Laravel Manager</title>
    @vite('resources/css/app.css')
    @livewireStyles
</head>
<body class="min-h-screen bg-background font-sans text-foreground antialiased">
    <main class="flex min-h-screen items-center justify-center px-4 py-12 sm:px-6">
        <div class="w-full max-w-sm">
            @yield('content')
        </div>
    </main>
    @aprilScripts
    @livewireScripts
</body>
</html>
