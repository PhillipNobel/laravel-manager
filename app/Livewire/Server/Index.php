<?php

namespace App\Livewire\Server;

use App\Models\AppSetting;
use App\Support\ServerEnvironment;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Server')]
class Index extends Component
{
    public function render(): View
    {
        return view('livewire.server.index', [
            'server' => [
                'operatingSystem' => ServerEnvironment::operatingSystem(),
                'phpVersion' => PHP_VERSION,
                'defaultPhpVersion' => AppSetting::valueFor('default_php_version'),
                'hostname' => AppSetting::valueFor('server_hostname') ?: 'Not configured',
                'publicIp' => AppSetting::valueFor('public_ip') ?: 'Not configured',
                'baseDomain' => AppSetting::valueFor('base_domain'),
                'applicationsDirectory' => AppSetting::valueFor('applications_directory'),
                'managerUrl' => AppSetting::valueFor('manager_url'),
            ],
            'requirements' => ServerEnvironment::requirements(),
        ]);
    }
}
