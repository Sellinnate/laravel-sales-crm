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
 * one email, or `{"emails": [...]}` — signed with HMAC-SHA256 of the raw body and the secret of
 * `sales-crm.email.inbound.webhook_secret` in the `X-Sales-Crm-Signature` header. Without a secret
 * the webhook is closed.
 */
class InboundWebhookController extends Controller
{
    public function __invoke(Request $request, Inbound $inbound): JsonResponse
    {
        $secret = config('sales-crm.email.inbound.webhook_secret');

        abort_unless(is_string($secret) && $secret !== '', 404);

        $signature = (string) $request->header('X-Sales-Crm-Signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        abort_unless(hash_equals($expected, str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature), 401);

        $payload = $request->json()->all();
        $emails = isset($payload['emails']) && is_array($payload['emails']) ? $payload['emails'] : [$payload];
        $stored = 0;

        foreach (array_slice($emails, 0, 100) as $email) {
            if (! is_array($email)) {
                continue;
            }

            try {
                /** @var array<string, mixed> $email */
                $stored += $inbound->receive(InboundEmail::fromArray($email)) === null ? 0 : 1;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return new JsonResponse(['received' => count($emails), 'stored' => $stored], 202);
    }
}
