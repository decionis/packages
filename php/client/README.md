<!--
  Links verified live on 2026-11-03: decionis.com/contact, github.com/decionis/agent-safe-pipeline,
  github.com/decionis/packages, packagist.org/packages/decionis/php-client.
-->

# decionis/php-client

The PHP client for Decionis: ask your policy before a store action commits, keep the signed
Decision Dossier it returns, and verify that Dossier offline.

It decides nothing itself. Your code describes the exact action (a refund, a price change, an
order), the client asks Decionis, and you get back one of three verdicts to act on: allow,
escalate or block. Every evaluation carries an idempotency key, so a retry never counts twice.

What it covers:

- **Commerce Gate decisions** for all seven decision types: `PRICE_CHANGE`, `INVENTORY_MUTATION`,
  `ORDER_ACCEPTANCE`, `FULFILLMENT_ACTION`, `PROMOTION_CHANGE`, `REFUND_REQUEST` and
  `RETURN_AUTHORIZATION`.
- **Evidence**: the Decision Dossier, its proof packet and the Shadow report.
- **Offline verification** of a Dossier's Ed25519 signatures with libsodium, against the published
  key set.
- **AgentSafe intents and grants**: the `agent-safe.intent/1` hash, and the single-use grant an
  approved action runs on.

## Install

Install the client from [Packagist](https://packagist.org/packages/decionis/php-client):

```bash
composer require decionis/php-client:^0.1.0
```

The example below also uses `nyholm/psr7` for its PSR-17 factories:

```bash
composer require nyholm/psr7:^1.8
```

Requirements: PHP 8.1 or later, `ext-json`, `ext-sodium`, a PSR-18 HTTP client and PSR-17
factories. `ext-curl` is needed only for the bundled `CurlClient`.

## Evaluate a refund

The API key and organization id come from the environment or a secret store, never from code or
configuration exports.

```php
use Decionis\Client\Client;
use Decionis\Client\Config;
use Decionis\Client\Decision\Action;
use Decionis\Client\Decision\Request;
use Decionis\Client\Decision\Verdict;
use Decionis\Client\Error\DecionisException;
use Decionis\Client\Http\CurlClient;
use Decionis\Client\Http\Transport;
use Nyholm\Psr7\Factory\Psr17Factory;

$factory = new Psr17Factory();
$client = new Client(
    new Config(getenv('DECIONIS_API_KEY'), getenv('DECIONIS_ORG_ID')),
    new Transport(new CurlClient($factory, $factory, 2000, 5000), $factory, $factory, 'my-store/1.0'),
);

$action = Action::refund(
    ['type' => 'HUMAN', 'id' => 'store:user:hash-of-admin-id'],
    'my-store',
    'my-store:refund:order-1001:1', // stable across retries of the same refund
    'order-1001',
    1200.00,
    'EUR',
);

try {
    $verdict = $client->evaluate(new Request($action, 'my-store'))->verdict;
} catch (DecionisException) {
    $verdict = null; // unreachable, refused or malformed: no decision
}

$next = match ($verdict) {
    Verdict::Allow => 'proceed',
    Verdict::Escalate => 'hold for an approver',
    Verdict::Block => 'refuse with the reason codes',
    null => 'apply the failure policy',
};
```

## Outcomes

| Authority answer            | `Verdict`  | What the caller does                |
| --------------------------- | ---------- | ----------------------------------- |
| `APPROVE`, `ALLOW`          | `Allow`    | Proceed and keep the Dossier id     |
| `REVIEW`, `ESCALATE`        | `Escalate` | Hold the action for approval        |
| `REJECT`, `BLOCK`           | `Block`    | Refuse it, with the reason codes    |
| Anything else, or no answer | none       | The caller's failure policy applies |

`Verdict::normalize()` is exact: a different case, padding or an unknown word is no decision.
The public evaluate-decision contract evaluates in `SHADOW` and the client refuses any response
that does not confirm it. A gate in enforcement acts on the returned verdict itself.

## Timeouts and retries

Every call has a finite deadline. `Retry` bounds the attempts (at most 5), backs off with full
jitter, and never starts a retry that cannot finish inside the deadline. Retries reuse the same
`Idempotency-Key`. Only network errors, 429 and 5xx are retried. A latency-critical path, such as
checkout, uses a single attempt: `$client->evaluate($request, Retry::once(120))` with a
`CurlClient` whose total timeout is 120 ms.

## Verify a Dossier offline

```php
$record = $client->dossier($decision->dossierId);
$result = $client->verify($record); // fetches and caches the published JWKS once
echo $result->summary();            // "VERIFIED with key … (4 artifacts)"
```

`Verifier` checks every signed artifact's canonical bytes (RFC 8785/JCS or sorted JSON, as the
proof bundle states) against its SHA-256 digest and an Ed25519 signature from the key set, then
checks that the signed artifacts cover the Protocol 1.1 commitments the Dossier makes. Pass a
`Jwks` you trust; the default key set URL is
`https://api.decionis.com/v1/.well-known/decision-dossier-jwks.json`.

## AgentSafe intents and grants

`Intent` and `Hasher` reproduce the AgentSafe `agent-safe.intent/1` canonical JSON and its
`sha256:` hash byte for byte: the bytes and hash of every vector in the conformance corpus of
[decionis/agent-safe-pipeline](https://github.com/decionis/agent-safe-pipeline), including the
mutations and the bindings captured from OpenAI, MCP, LangChain and Vercel AI SDK tool calls.

`Grant\Authority` asks Decionis to decide an intent, follows a managed escalation until an approver
answers, then claims the single-use grant before the action runs and records the outcome. A grant
is bound to the intent's hash: if the action changed, it no longer matches, and the caller refuses
to run it.

## Errors

Every error extends `DecionisException`. Its message is a stable code such as
`UPSTREAM_UNREACHABLE` or `EVALUATION_RESPONSE_MALFORMED`. No error carries the API key, a request
or response body, or a URL with query parameters.

## Source

The client is developed in `php/client` of
[decionis/packages](https://github.com/decionis/packages), and `decionis/php-client` is its
read-only mirror for Packagist. Send issues and pull requests to decionis/packages.

## Support and licence

Support: [decionis.com/contact](https://decionis.com/contact). Licence: Apache-2.0.
