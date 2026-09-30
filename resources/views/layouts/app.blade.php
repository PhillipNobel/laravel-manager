<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) && $title ? $title.' · ' : '' }}Laravel Manager</title>
    <script>
        (() => {
            const storageKey = 'laravel-manager-theme';
            const themes = ['system', 'light', 'dark'];
            const root = document.documentElement;
            const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');
            let preference = 'system';

            try {
                const savedPreference = localStorage.getItem(storageKey);
                if (themes.includes(savedPreference)) preference = savedPreference;
            } catch {}

            const applyTheme = () => {
                const useDarkTheme = preference === 'dark' || (preference === 'system' && systemTheme.matches);
                root.classList.toggle('dark', useDarkTheme);
                root.dataset.themePreference = preference;
            };

            const updateThemeOptions = () => {
                document.querySelectorAll('[data-theme-option]').forEach((option) => {
                    const selected = option.dataset.themeOption === preference;
                    option.setAttribute('aria-checked', String(selected));
                    option.querySelector('[data-theme-check]')?.classList.toggle('hidden', !selected);
                });
            };

            window.laravelManagerSetTheme = (theme) => {
                if (!themes.includes(theme)) return;

                preference = theme;
                try {
                    localStorage.setItem(storageKey, theme);
                } catch {}

                applyTheme();
                updateThemeOptions();
            };

            document.addEventListener('click', (event) => {
                if (event.target.closest?.('[data-theme-trigger]')) requestAnimationFrame(updateThemeOptions);
            });

            systemTheme.addEventListener('change', () => {
                if (preference === 'system') applyTheme();
            });

            applyTheme();
        })();
    </script>
    @vite('resources/css/app.css')
    @livewireStyles
</head>
<body class="min-h-screen bg-background font-sans text-foreground antialiased">
    <april:sidebar-layout class="min-h-screen">
        <april:sidebar collapsible="icon">
            <slot:header>
                <a href="{{ request()->routeIs('setup') ? route('setup') : route('apps.index') }}" class="flex h-14 items-center gap-3 px-4 font-semibold text-foreground" aria-label="Laravel Manager home">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary text-sm font-bold text-primary-foreground" aria-hidden="true">LM</span>
                    <span class="truncate">Laravel Manager</span>
                </a>
            </slot:header>

            <slot:content>
                @if (request()->routeIs('setup'))
                    <div class="px-4 py-5">
                        <p class="text-sm font-medium">First-run setup</p>
                        <p class="mt-1.5 text-xs leading-5 text-muted-foreground">Complete these steps to unlock app management.</p>
                    </div>
                @else
                    <april:sidebar-group>
                        <april:sidebar-group-label>Manage</april:sidebar-group-label>
                        <april:sidebar-group-content>
                        <april:sidebar-menu>
                            <april:sidebar-menu-item>
                                <april:sidebar-menu-button-link href="{{ route('apps.index') }}" :active="request()->routeIs('apps.*')" tooltip="Apps">
                                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3.5" y="3.5" width="7" height="7" rx="1.5" />
                                        <rect x="13.5" y="3.5" width="7" height="7" rx="1.5" />
                                        <rect x="3.5" y="13.5" width="7" height="7" rx="1.5" />
                                        <rect x="13.5" y="13.5" width="7" height="7" rx="1.5" />
                                    </svg>
                                    <span>Apps</span>
                                </april:sidebar-menu-button-link>
                            </april:sidebar-menu-item>
                            <april:sidebar-menu-item>
                                <april:sidebar-menu-button-link href="{{ route('server') }}" :active="request()->routeIs('server')" tooltip="Server">
                                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="4" y="4" width="16" height="6" rx="1.5" />
                                        <rect x="4" y="14" width="16" height="6" rx="1.5" />
                                        <path d="M8 7h.01M8 17h.01M12 7h4M12 17h4" />
                                    </svg>
                                    <span>Server</span>
                                </april:sidebar-menu-button-link>
                            </april:sidebar-menu-item>
                            <april:sidebar-menu-item>
                                <april:sidebar-menu-button-link href="{{ route('settings') }}" :active="request()->routeIs('settings')" tooltip="Settings">
                                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="3" />
                                        <path d="m19.4 15 .1.1a1.7 1.7 0 0 1-2.4 2.4l-.1-.1a1.7 1.7 0 0 0-2.9 1.2v.2a1.7 1.7 0 0 1-3.4 0v-.2a1.7 1.7 0 0 0-2.9-1.2l-.1.1a1.7 1.7 0 0 1-2.4-2.4l.1-.1a1.7 1.7 0 0 0-1.2-2.9H4a1.7 1.7 0 0 1 0-3.4h.2a1.7 1.7 0 0 0 1.2-2.9l-.1-.1a1.7 1.7 0 0 1 2.4-2.4l.1.1a1.7 1.7 0 0 0 2.9-1.2V2a1.7 1.7 0 0 1 3.4 0v.2a1.7 1.7 0 0 0 2.9 1.2l.1-.1a1.7 1.7 0 0 1 2.4 2.4l-.1.1a1.7 1.7 0 0 0 1.2 2.9h.2a1.7 1.7 0 0 1 0 3.4h-.2a1.7 1.7 0 0 0-1.2 2.9Z" />
                                    </svg>
                                    <span>Settings</span>
                                </april:sidebar-menu-button-link>
                            </april:sidebar-menu-item>
                        </april:sidebar-menu>
                        </april:sidebar-group-content>
                    </april:sidebar-group>
                @endif
            </slot:content>

            <slot:footer>
                <div class="flex items-center gap-3 border-t border-sidebar-border px-4 py-3">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-sidebar-accent text-sm font-medium text-sidebar-accent-foreground" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                    <div class="min-w-0 leading-tight">
                        <p class="truncate text-sm font-medium">{{ auth()->user()->name }}</p>
                        <p class="truncate text-xs text-muted-foreground">Administrator</p>
                    </div>
                </div>
            </slot:footer>

            <april:sidebar-rail />
        </april:sidebar>

        <april:sidebar-inset class="min-w-0 flex-1">
            <header class="sticky top-0 z-20 flex h-14 items-center justify-between border-b border-border bg-background px-4 sm:px-6">
                <april:sidebar-trigger aria-label="Toggle navigation" />

                <div class="flex items-center gap-1.5">
                    <april:dropdown-menu x-teleport="body">
                        <slot:trigger>
                            <button type="button" data-theme-trigger class="inline-flex size-9 items-center justify-center rounded-md text-muted-foreground outline-none transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2" aria-label="Color theme" title="Color theme">
                                <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="4" />
                                    <path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42" />
                                </svg>
                            </button>
                        </slot:trigger>
                        <slot:content class="w-44">
                            <april:dropdown-menu-label>Appearance</april:dropdown-menu-label>
                            <april:dropdown-menu-separator />
                            <april:dropdown-menu-item data-theme-option="system" role="menuitemradio" aria-checked="false" x-on:click="window.laravelManagerSetTheme('system')">
                                <span>System</span>
                                <svg data-theme-check aria-hidden="true" class="ml-auto size-4 hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6" /></svg>
                            </april:dropdown-menu-item>
                            <april:dropdown-menu-item data-theme-option="light" role="menuitemradio" aria-checked="false" x-on:click="window.laravelManagerSetTheme('light')">
                                <span>Light</span>
                                <svg data-theme-check aria-hidden="true" class="ml-auto size-4 hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6" /></svg>
                            </april:dropdown-menu-item>
                            <april:dropdown-menu-item data-theme-option="dark" role="menuitemradio" aria-checked="false" x-on:click="window.laravelManagerSetTheme('dark')">
                                <span>Dark</span>
                                <svg data-theme-check aria-hidden="true" class="ml-auto size-4 hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6" /></svg>
                            </april:dropdown-menu-item>
                        </slot:content>
                    </april:dropdown-menu>

                    <april:dropdown-menu x-teleport="body">
                        <slot:trigger>
                            <button type="button" class="inline-flex min-h-9 items-center gap-2 rounded-md px-2 text-sm font-medium text-foreground outline-none transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2" aria-label="Account menu for {{ auth()->user()->name }}">
                                <span class="hidden max-w-40 truncate sm:inline">{{ auth()->user()->name }}</span>
                                <svg aria-hidden="true" class="size-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m7 10 5 5 5-5" />
                                </svg>
                            </button>
                        </slot:trigger>
                        <slot:content class="w-56">
                            <april:dropdown-menu-label>{{ auth()->user()->email }}</april:dropdown-menu-label>
                            <april:dropdown-menu-separator />
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <april:dropdown-menu-item type="submit">Sign out</april:dropdown-menu-item>
                            </form>
                        </slot:content>
                    </april:dropdown-menu>
                </div>
            </header>

            <div class="min-w-0 px-4 py-8 sm:px-6 lg:px-8">
                <div class="mx-auto w-full max-w-7xl">
                    {{ $slot }}
                </div>
            </div>
        </april:sidebar-inset>
    </april:sidebar-layout>

    @aprilScripts
    @livewireScripts
</body>
</html>
