<?php

namespace App\Http\Controllers;

use App\Actions\GitHub\ProcessGitHubWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

class GitHubWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 25 * 1024 * 1024;

    public function __invoke(Request $request, ProcessGitHubWebhook $processWebhook): JsonResponse
    {
        $secret = config('services.github.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return response()->json(['message' => 'GitHub webhook secret is not configured.'], 503);
        }

        $contentLength = $request->headers->get('Content-Length');
        if (is_string($contentLength)
            && ctype_digit($contentLength)
            && (int) $contentLength > self::MAX_BODY_BYTES) {
            return response()->json(['message' => 'GitHub webhook payload is too large.'], 413);
        }

        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return response()->json(['message' => 'GitHub webhook payload is too large.'], 413);
        }

        $signature = $request->header('X-Hub-Signature-256');

        if (! is_string($signature)
            || preg_match('/\Asha256=[0-9a-f]{64}\z/', $signature) !== 1
            || ! hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $signature)) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $deliveryId = $request->header('X-GitHub-Delivery');
        $event = $request->header('X-GitHub-Event');

        if (! is_string($deliveryId)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $deliveryId) !== 1
            || ! is_string($event)
            || preg_match('/\A[A-Za-z0-9_.-]{1,50}\z/', $event) !== 1) {
            return response()->json(['message' => 'GitHub webhook headers are invalid.'], 400);
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['message' => 'GitHub webhook payload is invalid.'], 400);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            return response()->json(['message' => 'GitHub webhook payload is invalid.'], 400);
        }

        $result = $processWebhook->handle(
            $deliveryId,
            $event,
            $payload,
            hash('sha256', $body),
        );

        return response()->json($result, 202);
    }
}
