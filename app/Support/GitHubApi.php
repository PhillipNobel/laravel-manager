<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

class GitHubApi
{
    private const API_URL = 'https://api.github.com';

    private const API_VERSION = '2026-03-10';

    public function exchangeCode(string $code, string $codeVerifier): array
    {
        return Http::asForm()
            ->acceptJson()
            ->withHeaders(['User-Agent' => config('app.name')])
            ->timeout(10)
            ->post('https://github.com/login/oauth/access_token', [
                'client_id' => config('services.github.client_id'),
                'client_secret' => config('services.github.client_secret'),
                'code' => $code,
                'redirect_uri' => config('services.github.redirect') ?: route('settings.github.callback'),
                'code_verifier' => $codeVerifier,
            ])
            ->throw()
            ->json();
    }

    public function user(string $accessToken): array
    {
        return $this->api($accessToken)->get('/user')->throw()->json();
    }

    public function repositories(string $accessToken): array
    {
        $repositories = $this->api($accessToken)
            ->get('/user/repos', [
                'affiliation' => 'owner,collaborator,organization',
                'sort' => 'updated',
                'per_page' => 100,
            ])
            ->throw()
            ->json();

        if (! is_array($repositories)) {
            throw new UnexpectedValueException('GitHub returned an unexpected repository response.');
        }

        $repositories = array_map(static function (array $repository): array {
            $fullName = (string) ($repository['full_name'] ?? '');

            if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\/[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/', $fullName)) {
                return [];
            }

            $cloneUrl = $repository['clone_url'] ?? null;
            $expectedCloneUrl = 'https://github.com/'.$fullName.'.git';
            $safeCloneUrl = is_string($cloneUrl) && hash_equals($expectedCloneUrl, $cloneUrl)
                ? $expectedCloneUrl
                : null;

            return [
                'full_name' => $fullName,
                'visibility' => ($repository['private'] ?? false) ? 'Private' : 'Public',
                'default_branch' => (string) ($repository['default_branch'] ?? 'main'),
                'url' => 'https://github.com/'.$fullName,
                'clone_url' => $safeCloneUrl,
            ];
        }, array_filter($repositories, 'is_array'));

        return array_values(array_filter($repositories, static fn (array $repository): bool => isset($repository['full_name'])));
    }

    public function revokeToken(string $accessToken): void
    {
        Http::withBasicAuth(config('services.github.client_id'), config('services.github.client_secret'))
            ->acceptJson()
            ->withHeaders([
                'User-Agent' => config('app.name'),
                'X-GitHub-Api-Version' => self::API_VERSION,
            ])
            ->timeout(10)
            ->delete(self::API_URL.'/applications/'.rawurlencode((string) config('services.github.client_id')).'/token', [
                'access_token' => $accessToken,
            ])
            ->throw();
    }

    private function api(string $accessToken): PendingRequest
    {
        return Http::baseUrl(self::API_URL)
            ->withToken($accessToken)
            ->acceptJson()
            ->withHeaders([
                'User-Agent' => config('app.name'),
                'X-GitHub-Api-Version' => self::API_VERSION,
            ])
            ->timeout(10);
    }
}
