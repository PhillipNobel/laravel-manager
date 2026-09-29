<?php

namespace App\Http\Controllers;

use App\Models\GitHubConnection;
use App\Support\GitHubApi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnexpectedValueException;

class GitHubController extends Controller
{
    public function connect(Request $request): RedirectResponse
    {
        $clientId = config('services.github.client_id');
        $clientSecret = config('services.github.client_secret');

        if (! is_string($clientId) || $clientId === '' || ! is_string($clientSecret) || $clientSecret === '') {
            return redirect()->route('settings')->with('error', 'Configure GitHub OAuth credentials before connecting.');
        }

        $state = bin2hex(random_bytes(32));
        $verifier = $this->base64UrlEncode(random_bytes(64));
        $challenge = $this->base64UrlEncode(hash('sha256', $verifier, true));

        $request->session()->put('github_oauth', [
            'state' => $state,
            'code_verifier' => $verifier,
        ]);

        $redirectUri = config('services.github.redirect') ?: route('settings.github.callback');

        return redirect()->away('https://github.com/login/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'repo',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'allow_signup' => 'false',
        ]));
    }

    public function callback(Request $request, GitHubApi $github): RedirectResponse
    {
        $oauth = $request->session()->pull('github_oauth');
        $returnedState = $request->query('state');
        $storedState = is_array($oauth) ? ($oauth['state'] ?? null) : null;

        if (! is_string($storedState) || ! is_string($returnedState) || ! hash_equals($storedState, $returnedState)) {
            return redirect()->route('settings')
                ->with('error', 'GitHub authorization could not be verified. Please try connecting again.');
        }

        if ($request->query('error') !== null) {
            return redirect()->route('settings')
                ->with('error', 'GitHub authorization was not completed. Please try connecting again.');
        }

        $code = $request->query('code');
        $codeVerifier = $oauth['code_verifier'] ?? null;

        if (! is_string($code) || $code === '' || ! is_string($codeVerifier) || $codeVerifier === '') {
            return redirect()->route('settings')
                ->with('error', 'GitHub authorization could not be completed. Please try connecting again.');
        }

        try {
            $tokenResponse = $github->exchangeCode($code, $codeVerifier);
            $accessToken = $tokenResponse['access_token'] ?? null;

            if (! is_string($accessToken) || $accessToken === '') {
                throw new UnexpectedValueException('GitHub did not return an access token.');
            }

            $user = $github->user($accessToken);
            $githubUserId = $user['id'] ?? null;
            $login = $user['login'] ?? null;

            if (! is_int($githubUserId) || ! is_string($login) || $login === '') {
                throw new UnexpectedValueException('GitHub returned an invalid account response.');
            }

            DB::transaction(function () use ($user, $githubUserId, $login, $accessToken, $tokenResponse): void {
                GitHubConnection::query()->delete();

                GitHubConnection::query()->create([
                    'github_user_id' => $githubUserId,
                    'login' => $login,
                    'name' => is_string($user['name'] ?? null) ? $user['name'] : null,
                    'avatar_url' => is_string($user['avatar_url'] ?? null) ? $user['avatar_url'] : null,
                    'scopes' => is_string($tokenResponse['scope'] ?? null) ? $tokenResponse['scope'] : null,
                    'access_token' => $accessToken,
                    'connected_at' => now(),
                ]);
            });
        } catch (Throwable) {
            return redirect()->route('settings')
                ->with('error', 'GitHub could not be connected. Check the OAuth configuration and try again.');
        }

        return redirect()->route('settings')->with('status', "Connected to GitHub as {$login}.");
    }

    public function disconnect(GitHubApi $github): RedirectResponse
    {
        $connection = GitHubConnection::query()->firstOrFail();

        try {
            $github->revokeToken($connection->access_token);
        } catch (Throwable) {
            return redirect()->route('settings')
                ->with('error', 'GitHub could not be disconnected. Please try again.');
        }

        $connection->delete();

        return redirect()->route('settings')->with('status', 'GitHub disconnected.');
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
