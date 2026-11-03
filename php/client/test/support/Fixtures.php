<?php

declare(strict_types=1);

namespace Decionis\Client\Test\Support;

/** Paths to the vendored conformance data, recorded evidence and contract. */
final class Fixtures
{
    public static function path(string $relative): string
    {
        return dirname(__DIR__) . '/fixtures/' . $relative;
    }

    public static function object(string $file): \stdClass
    {
        $decoded = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof \stdClass) {
            throw new \RuntimeException('Fixture is not a JSON object: ' . basename($file));
        }
        return $decoded;
    }

    /** @return array<mixed> */
    public static function array(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Fixture is not a JSON object: ' . basename($file));
        }
        return $decoded;
    }

    /**
     * A nested JSON object read as an array, failing the test when it is not one.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function at(array $data, string ...$path): array
    {
        foreach ($path as $key) {
            $data = $data[$key] ?? null;
            if (!is_array($data)) {
                throw new \RuntimeException('Fixture has no object at ' . implode('.', $path));
            }
        }
        return $data;
    }

    public const EVIDENCE = 'commercegate/evidence/';
    public const CONTRACT = 'commercegate/CommerceGateOpenApi.json';
}
