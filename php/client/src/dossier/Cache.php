<?php

declare(strict_types=1);

namespace Decionis\Client\Dossier;

/** Where a fetched key set is kept between requests (Drupal's cache, APCu, memory). */
interface Cache
{
    public function get(string $key): ?string;

    public function set(string $key, string $value, int $ttlSeconds): void;
}
