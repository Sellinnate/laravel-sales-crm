<?php

namespace Sellinnate\SalesCrm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sellinnate\SalesCrm\Data\InboundEmail;
use Sellinnate\SalesCrm\Inbound\Inbound;
use Throwable;

/**
 * The inbound webhook: your mail provider (or a small adapter of yours) POSTs the emails as JSON —
 * one email, or `{"emails": [...]}` with at most MAX_BATCH — signed with HMAC-SHA256 of the raw body and the secret of
 * `sales-crm.email.inbound.webhook_secret` in the `X-Sales-Crm-Signature` header. Without a secret
 * the webhook is closed.
 */
class InboundWebhookController extends Controller
{
    public const MAX_BATCH = 100;

    public function __invoke(Request $request, Inbound $inbound): JsonResponse
    {
        $secret = config('sales-crm.email.inbound.webhook_secret');

        abort_unless(is_string($secret) && $secret !== '', 404);

        $signature = (string) $request->header('X-Sales-Crm-Signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        abort_unless(hash_equals($expected, str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature), 401);

        $payload = json_decode($request->getContent(), true);

        // A body that isn't a JSON object (cut, form-encoded) is an error of the integration, not "no email".
        if (! is_array($payload) || array_is_list($payload) && $payload !== []) {
            return new JsonResponse(['message' => 'The body must be a JSON object: one email, or {"emails": [...]}.'], 400);
        }

        $emails = isset($payload['emails']) && is_array($payload['emails']) ? $payload['emails'] : [$payload];
        $stored = 0;
        $failed = 0;

        // Refused whole, never cut: the sender splits the batch and retries, no email is lost.
        if (count($emails) > self::MAX_BATCH) {
            return new JsonResponse(['message' => 'At most '.self::MAX_BATCH.' emails per request.', 'max' => self::MAX_BATCH], 413);
        }

        // An item that isn't an email object fails every retry: the batch is refused before storing any.
        $invalid = array_keys(array_filter($emails, fn (mixed $email): bool => ! is_array($email)));

        if ($invalid !== []) {
            return new JsonResponse(['message' => 'Every email must be a JSON object.', 'invalid' => $invalid], 422);
        }

        foreach ($emails as $email) {
            if (! is_array($email)) {
                continue;
            }

            try {
                /** @var array<string, mixed> $email */
                $stored += $inbound->receive(InboundEmail::fromArray($email)) === null ? 0 : 1;
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        // A failure (a deadlock, the database away) asks the sender to retry the batch: the emails
        // already stored are recognised by their Message-ID and not stored twice.
        return new JsonResponse(['received' => count($emails), 'stored' => $stored, 'failed' => $failed], $failed > 0 ? 503 : 202);
    }
}
