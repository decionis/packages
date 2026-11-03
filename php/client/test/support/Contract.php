<?php

declare(strict_types=1);

namespace Decionis\Client\Test\Support;

use Opis\JsonSchema\Validator;

/** Validates data against a schema in the canonical Commerce Gate OpenAPI contract. */
final class Contract
{
    private const ID = 'https://contract.decionis.test/commercegate.json';

    private static ?Validator $validator = null;

    /** @return string|null the first error, or null when the data matches */
    public static function check(mixed $data, string $schema): ?string
    {
        $result = self::validator()->validate(
            json_decode((string) json_encode($data, JSON_PRESERVE_ZERO_FRACTION)),
            (object) ['$ref' => self::ID . '#/components/schemas/' . $schema],
        );
        if ($result->isValid()) {
            return null;
        }
        $error = $result->error();
        while ($error !== null && $error->subErrors() !== []) {
            $error = $error->subErrors()[0];
        }
        return $error === null ? 'invalid' : $error->keyword() . ' at /' . implode('/', $error->data()->fullPath()) . ': ' . json_encode($error->args());
    }

    private static function validator(): Validator
    {
        if (self::$validator === null) {
            $validator = new Validator();
            $validator->setMaxErrors(1);
            $validator->resolver()?->registerRaw((string) file_get_contents(Fixtures::path(Fixtures::CONTRACT)), self::ID);
            self::$validator = $validator;
        }
        return self::$validator;
    }
}
