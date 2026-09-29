<?php

namespace App\Livewire\Settings;

use App\Models\AppSetting;
use App\Models\GitHubConnection;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Settings')]
class Index extends Component
{
    public string $serverHostname = '';

    public ?string $publicIp = '';

    public string $baseDomain = '';

    public string $applicationsDirectory = '';

    public string $defaultPhpVersion = '';

    public string $managerUrl = '';

    public function mount(): void
    {
        $this->serverHostname = AppSetting::valueFor('server_hostname');
        $this->publicIp = AppSetting::valueFor('public_ip');
        $this->baseDomain = AppSetting::valueFor('base_domain');
        $this->applicationsDirectory = AppSetting::valueFor('applications_directory');
        $this->defaultPhpVersion = AppSetting::valueFor('default_php_version');
        $this->managerUrl = AppSetting::valueFor('manager_url');
    }

    public function updated(string $property): void
    {
        $this->resetValidation($property);
    }

    protected function rules(): array
    {
        return [
            'serverHostname' => ['required', 'string', 'max:253'],
            'publicIp' => ['nullable', 'ip'],
            'baseDomain' => [
                'required',
                'string',
                'max:253',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)
                        || ! str_contains($value, '.')
                        || filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                        $fail('Enter a base domain without a protocol or subdomain.');
                    }
                },
            ],
            'applicationsDirectory' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! str_starts_with($value, '/') || str_contains($value, "\0")) {
                        $fail('Enter an absolute directory path.');

                        return;
                    }

                    $normalized = rtrim($value, '/');
                    $segments = explode('/', trim($normalized, '/'));

                    if (
                        ! preg_match('/\A\/[A-Za-z0-9._\/ -]+\z/', $normalized)
                        || str_contains($normalized, '//')
                        || in_array('.', $segments, true)
                        || in_array('..', $segments, true)
                    ) {
                        $fail('Use an absolute applications directory with letters, numbers, spaces, dots, underscores, hyphens, and slashes.');
                    }
                },
            ],
            'defaultPhpVersion' => ['required', Rule::in(config('manager.php_versions'))],
            'managerUrl' => ['required', 'url', 'max:2048'],
        ];
    }

    public function save(): void
    {
        $this->publicIp = filled($this->publicIp) ? trim($this->publicIp) : null;
        $this->applicationsDirectory = rtrim(trim($this->applicationsDirectory), DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR;

        $this->validate();

        DB::transaction(function (): void {
            foreach ([
                'server_hostname' => $this->serverHostname,
                'public_ip' => $this->publicIp ?? '',
                'base_domain' => $this->baseDomain,
                'applications_directory' => $this->applicationsDirectory,
                'default_php_version' => $this->defaultPhpVersion,
                'manager_url' => $this->managerUrl,
            ] as $key => $value) {
                AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
            }
        });

        session()->flash('status', 'Settings saved.');
    }

    public function render(): View
    {
        return view('livewire.settings.index', [
            'phpVersions' => config('manager.php_versions'),
            'githubConfigured' => filled(config('services.github.client_id'))
                && filled(config('services.github.client_secret')),
            'githubConnection' => GitHubConnection::query()
                ->select(['id', 'login', 'connected_at'])
                ->first(),
        ]);
    }
}
