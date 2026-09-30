<?php

namespace App\Support;

use Closure;
use Illuminate\Validation\ValidationException;

class InfrastructureLock
{
    private static int $depth = 0;

    public static function run(Closure $operation): mixed
    {
        $gate = config('manager.update_lock', ManagerUpdates::GATE);
        $stateFile = config('manager.update_state', ManagerUpdates::STATE);
        if (self::$depth > 0 || ! file_exists($gate)) {
            return $operation();
        }

        $stream = is_link($gate) ? false : @fopen($gate, 'r+');
        if (! $stream || ! flock($stream, LOCK_SH | LOCK_NB)) {
            if ($stream) {
                fclose($stream);
            }
            self::blocked();
        }

        try {
            $state = null;
            if (is_file($stateFile)) {
                $state = json_decode(file_get_contents($stateFile, false, null, 0, 16384), true)['state'] ?? null;
            }
            if (in_array($state, ['queued', 'running'], true)) {
                self::blocked();
            }

            self::$depth++;
            try {
                return $operation();
            } finally {
                self::$depth--;
            }
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    private static function blocked(): never
    {
        throw ValidationException::withMessages([
            'managerUpdate' => 'A Manager update is in progress. Wait for it to finish before changing applications.',
        ]);
    }
}
