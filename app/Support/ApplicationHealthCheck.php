<?php

namespace App\Support;

use App\Models\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ApplicationHealthCheck
{
    public static function check(Project $project): int
    {
        if (! is_string($project->domain) || ! filter_var($project->domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new RuntimeException('The configured application domain is invalid for the health check.');
        }
        try {
            $response = Http::connectTimeout(3)->timeout(5)->withHeaders(['Host' => $project->domain])
                ->withOptions(['allow_redirects' => false])->get('http://127.0.0.1/');
        } catch (ConnectionException) {
            throw new RuntimeException('The application did not respond to the health check after deployment.');
        }
        if ($response->serverError()) {
            throw new RuntimeException('The application returned HTTP '.$response->status().' after deployment.');
        }

        return $response->status();
    }
}
