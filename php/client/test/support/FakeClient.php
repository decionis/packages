<?php

declare(strict_types=1);

namespace Decionis\Client\Test\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A PSR-18 double: answers from a queue and records every request it was sent. */
final class FakeClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param list<ResponseInterface|\Throwable|callable(RequestInterface): ResponseInterface> $answers */
    public function __construct(private array $answers)
    {
    }

    public static function json(int $status, mixed $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], is_string($body) ? $body : (string) json_encode($body));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $answer = array_shift($this->answers);
        if ($answer === null) {
            throw new \LogicException('FakeClient has no answer left');
        }
        if ($answer instanceof \Throwable) {
            throw $answer;
        }
        return $answer instanceof ResponseInterface ? $answer : $answer($request);
    }
}
