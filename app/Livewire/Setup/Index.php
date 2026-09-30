<?php

namespace App\Livewire\Setup;

use App\Models\AppSetting;
use App\Models\GitHubConnection;
use App\Support\ServerEnvironment;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Initial setup')]
class Index extends Component
{
    private const SESSION_KEY = 'laravel_manager_setup';

    #[Locked]
    public int $currentStep = 1;

    #[Locked]
    public bool $serverChecked = false;

    #[Locked]
    public array $requirements = [];

    public string $baseDomain = '';

    public string $publicIp = '';

    public string $applicationsDirectory = '';

    public string $defaultPhpVersion = '';

    public bool $wildcardDnsConfirmed = false;

    public function mount(): void
    {
        $this->baseDomain = AppSetting::valueFor('base_domain');
        $this->publicIp = AppSetting::valueFor('public_ip');
        $this->applicationsDirectory = AppSetting::valueFor('applications_directory');
        $this->defaultPhpVersion = AppSetting::valueFor('default_php_version');

        $progress = session(self::SESSION_KEY, []);
        $this->currentStep = max(1, min(6, (int) ($progress['step'] ?? 1)));
        $this->serverChecked = (bool) ($progress['server_checked'] ?? false);
        $this->requirements = is_array($progress['requirements'] ?? null) ? $progress['requirements'] : [];
    }

    public function updated(string $property): void
    {
        $this->resetValidation($property);
    }

    public function next(): void
    {
        if ($this->currentStep === 2) {
            $this->saveServerSettings();
        }

        if ($this->currentStep === 3 && ! $this->serverChecked) {
            $this->addError('requirements', 'Run the read-only server checks before continuing.');

            return;
        }

        if ($this->currentStep === 5) {
            $this->validate([
                'wildcardDnsConfirmed' => ['accepted'],
            ], [
                'wildcardDnsConfirmed.accepted' => 'Confirm the wildcard DNS record before finishing setup.',
            ]);
        }

        $this->currentStep = min(6, $this->currentStep + 1);
        $this->storeProgress();
    }

    public function back(): void
    {
        $this->currentStep = max(1, $this->currentStep - 1);
        $this->storeProgress();
    }

    public function checkServer(): void
    {
        if ($this->currentStep !== 3) {
            return;
        }

        $this->requirements = ServerEnvironment::requirements();
        $this->serverChecked = true;
        $this->storeProgress();
    }

    public function finish(): mixed
    {
        if ($this->currentStep !== 6 || ! $this->serverChecked) {
            abort(403);
        }

        $this->validate([
            'wildcardDnsConfirmed' => ['accepted'],
        ], [
            'wildcardDnsConfirmed.accepted' => 'Confirm the wildcard DNS record before finishing setup.',
        ]);

        AppSetting::query()->updateOrCreate(
            ['key' => 'setup_completed'],
            ['value' => '1'],
        );

        session()->forget(self::SESSION_KEY);
        session()->flash('status', 'Server setup is complete. Laravel Manager is ready for its first app.');

        return redirect()->route('apps.index');
    }

    protected function rules(): array
    {
        return [
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
            'publicIp' => ['required', 'ip'],
            'applicationsDirectory' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! str_starts_with($value, '/') || str_contains($value, "\0")) {
                        $fail('Enter an absolute applications directory.');

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
        ];
    }

    public function render(): View
    {
        return view('livewire.setup.index', [
            'steps' => [
                ['value' => 1, 'label' => 'Administrator'],
                ['value' => 2, 'label' => 'Server settings'],
                ['value' => 3, 'label' => 'Server checks'],
                ['value' => 4, 'label' => 'GitHub'],
                ['value' => 5, 'label' => 'Wildcard DNS'],
                ['value' => 6, 'label' => 'Finish'],
            ],
            'githubConfigured' => filled(config('services.github.client_id'))
                && filled(config('services.github.client_secret')),
            'githubConnection' => GitHubConnection::query()
                ->select(['id', 'login', 'connected_at'])
                ->first(),
            'availableRequirements' => count(array_filter(
                $this->requirements,
                static fn (array $requirement): bool => ($requirement['status'] ?? null) === 'available',
            )),
            'dnsRecordType' => filter_var($this->publicIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A',
        ]);
    }

    private function saveServerSettings(): void
    {
        $this->baseDomain = strtolower(trim($this->baseDomain));
        $this->publicIp = trim($this->publicIp);
        $this->applicationsDirectory = rtrim(trim($this->applicationsDirectory), DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR;

        $this->validate();

        DB::transaction(function (): void {
            foreach ([
                'base_domain' => $this->baseDomain,
                'public_ip' => $this->publicIp,
                'applications_directory' => $this->applicationsDirectory,
                'default_php_version' => $this->defaultPhpVersion,
            ] as $key => $value) {
                AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
            }
        });
    }

    private function storeProgress(): void
    {
        session()->put(self::SESSION_KEY, [
            'step' => $this->currentStep,
            'server_checked' => $this->serverChecked,
            'requirements' => $this->requirements,
        ]);
    }
}
