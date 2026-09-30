<?php

namespace App\Livewire\Apps;

use App\Actions\Projects\ProvisionProject;
use App\Enums\DatabaseEngine;
use App\Enums\ProjectStatus;
use App\Models\AppSetting;
use App\Models\GitHubConnection;
use App\Models\Project;
use App\Support\DomainGenerator;
use App\Support\GitHubApi;
use App\Support\ServerEnvironment;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
#[Title('Create App')]
class Create extends Component
{
    public string $name = '';

    public string $subdomain = '';

    public string $branch = 'main';

    public string $phpVersion = '';

    public string $databaseEngine = '';

    public array $phpOptions = [];

    public array $databaseOptions = [];

    public string $repositoryName = '';

    public array $repositories = [];

    public bool $githubConnected = false;

    public ?string $repositoryLoadError = null;

    public function mount(GitHubApi $github): void
    {
        $connection = GitHubConnection::query()->first();

        if (! $connection) {
            return;
        }

        $this->githubConnected = true;
        $this->phpOptions = ServerEnvironment::phpOptions();
        $availablePhpVersions = collect($this->phpOptions)
            ->where('available', true)
            ->pluck('value')
            ->all();
        $defaultPhpVersion = AppSetting::valueFor('default_php_version');
        $this->phpVersion = in_array($defaultPhpVersion, $availablePhpVersions, true)
            ? $defaultPhpVersion
            : ($availablePhpVersions[0] ?? '');

        $this->updateDatabaseOptions();

        try {
            $this->repositories = array_values(array_filter(
                $github->repositories($connection->access_token),
                static fn (array $repository): bool => is_string($repository['clone_url'] ?? null),
            ));
        } catch (Throwable) {
            $this->repositoryLoadError = 'GitHub repositories could not be loaded. Try again from Settings.';
        }
    }

    public function updatedRepositoryName(): void
    {
        $repository = collect($this->repositories)->firstWhere('full_name', $this->repositoryName);

        if ($repository) {
            $this->branch = $repository['default_branch'] ?: 'main';
        }
    }

    public function updated(string $property): void
    {
        $this->resetValidation($property);

        if ($property === 'phpVersion' && $this->githubConnected) {
            $this->updateDatabaseOptions();
        }
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'regex:/\A[^\x00-\x1F\x7F]+\z/'],
            'subdomain' => [
                'required',
                'string',
                'max:63',
                'regex:/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/i',
                Rule::unique('projects', 'slug'),
            ],
            'repositoryName' => ['required', Rule::in(collect($this->repositories)->pluck('full_name')->all())],
            'branch' => ['required', 'string', 'max:120', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._\/-]{0,119}\z/'],
            'phpVersion' => ['required', Rule::in(config('manager.php_versions'))],
            'databaseEngine' => ['required', Rule::in(array_column(DatabaseEngine::cases(), 'value'))],
        ];
    }

    protected function messages(): array
    {
        return [
            'repositoryName.required' => 'Choose a GitHub repository.',
            'repositoryName.in' => 'Choose a repository listed by GitHub.',
            'branch.regex' => 'Use letters, numbers, dots, hyphens, or slashes in the branch name.',
            'databaseEngine.required' => 'Choose an available database engine.',
            'databaseEngine.in' => 'Choose MySQL or PostgreSQL.',
        ];
    }

    #[Computed]
    public function domainPreview(): ?string
    {
        if (! preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/i', $this->subdomain)) {
            return null;
        }

        return DomainGenerator::generate($this->subdomain, AppSetting::valueFor('base_domain'));
    }

    public function save(GitHubApi $github, ProvisionProject $provisionProject): void
    {
        $this->name = trim($this->name);
        $this->subdomain = strtolower(trim($this->subdomain));
        $this->branch = trim($this->branch);
        $this->repositoryName = trim($this->repositoryName);

        $validated = $this->validate();

        if ($requirement = ServerEnvironment::phpRequirement($validated['phpVersion'])) {
            $this->addError('phpVersion', "PHP {$validated['phpVersion']} is unavailable. {$requirement}");

            return;
        }

        $databaseEngine = DatabaseEngine::from($validated['databaseEngine']);
        if ($requirement = ServerEnvironment::databaseRequirement($validated['phpVersion'], $databaseEngine)) {
            $this->addError('databaseEngine', "{$databaseEngine->label()} is unavailable. {$requirement}");

            return;
        }

        $connection = GitHubConnection::query()->first();

        if (! $connection) {
            $this->addError('repositoryName', 'Connect GitHub before creating an application.');

            return;
        }

        try {
            $repository = collect($github->repositories($connection->access_token))
                ->firstWhere('full_name', $validated['repositoryName']);
        } catch (Throwable) {
            $this->addError('repositoryName', 'GitHub could not verify this repository. Try again.');

            return;
        }

        if (! $repository || ! is_string($repository['clone_url'] ?? null)) {
            $this->addError('repositoryName', 'Select an accessible GitHub repository.');

            return;
        }

        $baseDomain = AppSetting::valueFor('base_domain');

        $project = Project::query()->create([
            'name' => $validated['name'],
            'slug' => strtolower($validated['subdomain']),
            'domain' => DomainGenerator::generate($validated['subdomain'], $baseDomain),
            'repository_url' => $repository['url'],
            'repository_name' => $repository['full_name'],
            'branch' => $validated['branch'],
            'php_version' => $validated['phpVersion'],
            'database_engine' => $databaseEngine,
            'status' => ProjectStatus::Pending,
        ]);

        $provisionProject->handle($project, $repository, $connection);

        $this->redirect(route('apps.show', $project));
    }

    public function render(): View
    {
        return view('livewire.apps.create', [
            'hasAvailablePhpVersion' => collect($this->phpOptions)->contains('available', true),
            'hasAvailableDatabaseEngine' => collect($this->databaseOptions)->contains('available', true),
        ]);
    }

    private function updateDatabaseOptions(): void
    {
        $this->databaseOptions = ServerEnvironment::databaseOptions($this->phpVersion);
        $availableEngines = collect($this->databaseOptions)
            ->where('available', true)
            ->pluck('value')
            ->all();

        if (! in_array($this->databaseEngine, $availableEngines, true)) {
            $this->databaseEngine = in_array(DatabaseEngine::MySql->value, $availableEngines, true)
                ? DatabaseEngine::MySql->value
                : ($availableEngines[0] ?? '');
        }
    }
}
