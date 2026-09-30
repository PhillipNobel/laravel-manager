<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Throwable;

class ManagerUpdates
{
    public const HELPER = '/usr/local/sbin/laravel-manager-updates';

    public const STATE = '/var/lib/laravel-manager/updates/status.json';

    public const GATE = '/run/lock/laravel-manager-operations.lock';

    public function status(): array
    {
        return $this->call('status');
    }

    public function check(): array
    {
        return $this->call('check');
    }

    public function start(): array
    {
        $status = $this->status();
        if (! in_array($status['state'], ['available', 'failed', 'interrupted'], true)) {
            throw ValidationException::withMessages(['managerUpdate' => 'Check for an available update before installing.']);
        }

        $result = $this->call('start');
        if ($result['state'] !== 'queued') {
            throw ValidationException::withMessages([
                'managerUpdate' => 'The update could not start. Finish queued or running app operations and check the installed update service.',
            ]);
        }

        return $result;
    }

    private function call(string $operation): array
    {
        $unavailable = [
            'state' => 'unsupported',
            'message' => 'Panel updates require the installed Ubuntu update service. Run sudo laravel-manager enable-panel-updates after updating the CLI.',
        ];

        if (! is_file(self::HELPER) && ! (app()->runningUnitTests() && config('manager.test_update_bridge', false))) {
            return $unavailable;
        }

        try {
            $process = Process::timeout($operation === 'check' ? 35 : 15)
                ->run(['/usr/bin/sudo', '-n', self::HELPER, $operation]);
            $data = json_decode($process->output(), true);
            if (! $process->successful() || ! is_array($data) || strlen($process->output()) > 16384) {
                return $unavailable;
            }

            $states = ['unchecked', 'up_to_date', 'available', 'unavailable', 'queued', 'running', 'successful', 'failed', 'interrupted'];
            if (! in_array($data['state'] ?? null, $states, true)) {
                return $unavailable;
            }

            // Display only fixed messages; never forward diagnostics from a subprocess.
            $messages = [
                'unchecked' => 'Check for available Manager improvements.',
                'up_to_date' => 'Manager is up to date.',
                'available' => 'A Manager update is available.',
                'unavailable' => 'GitHub could not be checked. Try again later.',
                'queued' => 'The update is queued. The panel will reconnect after services return.',
                'running' => 'The Manager update is running. Services may be briefly unavailable.',
                'successful' => 'Update completed and the login page responded.',
                'failed' => 'Update failed. Review the server and resume with the recovery command.',
                'interrupted' => 'An incomplete update needs recovery. Use the recovery command below.',
            ];
            $safe = ['state' => $data['state'], 'message' => $messages[$data['state']]];
            foreach (['installed_commit', 'available_commit', 'source_commit'] as $key) {
                if (is_string($data[$key] ?? null) && preg_match('/\A[a-f0-9]{40}\z/', $data[$key])) {
                    $safe[$key] = $data[$key];
                }
            }
            foreach (['checked_at', 'started_at', 'finished_at'] as $key) {
                if (is_string($data[$key] ?? null) && preg_match('/\A[0-9T:+.Z-]{10,40}\z/', $data[$key]) && strtotime($data[$key]) !== false) {
                    $safe[$key] = $data[$key];
                }
            }
            $phases = ['checking', 'waiting', 'maintenance', 'source', 'dependencies', 'assets', 'migrations', 'restarting', 'verifying', 'successful', 'failed'];
            if (is_string($data['installed_version'] ?? null) && preg_match('/\A[A-Za-z0-9._-]{1,60}\z/', $data['installed_version'])) {
                $safe['installed_version'] = $data['installed_version'];
            }
            $safe['phase'] = in_array($data['phase'] ?? null, $phases, true) ? $data['phase'] : null;
            $safe['history'] = [];
            foreach (array_slice(is_array($data['history'] ?? null) ? $data['history'] : [], -12) as $step) {
                if (is_array($step) && in_array($step['phase'] ?? null, $phases, true)
                    && is_string($step['at'] ?? null) && preg_match('/\A[0-9T:+.Z-]{10,40}\z/', $step['at'])
                    && strtotime($step['at']) !== false) {
                    $safe['history'][] = ['phase' => $step['phase'], 'at' => $step['at']];
                }
            }
            $safe['last_result'] = in_array($data['last_result'] ?? null, ['successful', 'failed'], true) ? $data['last_result'] : null;

            return $safe;
        } catch (Throwable) {
            return $unavailable;
        }
    }
}
