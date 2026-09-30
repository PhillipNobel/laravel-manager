<?php

namespace App\Support;

use App\Models\Project;

class LocalDevelopmentGuide
{
    public static function commands(Project $project): array
    {
        $repo = $project->repository_name;
        if (! is_string($repo) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\/[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/', $repo)
            || $project->repository_url !== "https://github.com/{$repo}"
            || ! preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/', $project->slug)
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\/-]{0,119}\z/', $project->branch)) {
            return [];
        }
        $q = fn (string $value) => escapeshellarg($value);

        return [
            '1. Clone the configured branch' => 'git clone --branch '.$q($project->branch).' -- '.$q("https://github.com/{$repo}.git").' '.$q($project->slug)."\ncd ".$q($project->slug),
            '2. Install dependencies and prepare your local environment' => "composer install\nif [ ! -f .env ]; then cp .env.example .env; fi\nif [ -f package-lock.json ]; then npm ci; else npm install; fi",
            '3. After editing .env, initialize the local application once' => "php artisan key:generate\ntouch database/database.sqlite\nphp artisan migrate\nnpm run build",
            'Terminal 1 — Laravel' => 'php artisan serve',
            'Terminal 2 — Vite' => 'npm run dev',
            'Daily workflow — commit and push' => "git add .\ngit commit -m 'Describe your change'\ngit push origin ".$q($project->branch),
        ];
    }
}
