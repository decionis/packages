# Decionis packages

Client libraries for [Decionis](https://decionis.com), each in its own directory with its own
manifest, licence and tests.

| Package               | Directory                    | Language         | Licence    | Status               |
| --------------------- | ---------------------------- | ---------------- | ---------- | -------------------- |
| `decionis/php-client` | [`php/client`](./php/client) | PHP 8.1 or later | Apache-2.0 | [Packagist](https://packagist.org/packages/decionis/php-client) |

Each package's README says what it does, how to install it and what it requires.

<!-- Packagist listing verified live on 2026-11-03. -->

## Mirrors and releases

Packagist reads only a `composer.json` at a repository's root, so `php/client` is mirrored to the
read-only [decionis/php-client](https://github.com/decionis/php-client), the repository Packagist
lists. Send issues and pull requests here, never to the mirror.

- A push to `main` that changes `php/client` updates the mirror's `main`.
- A tag `php-client/vX.Y.Z` on `main` becomes the mirror's `vX.Y.Z`, which Packagist publishes as
  version `X.Y.Z`.

Tag the tested commit on `main` after PHP client CI passes, push the tag to this repository, and
check that the mirror workflow succeeds and the version appears on Packagist. The mirror's GitHub
push webhook uses the Packagist safe API token as its secret, so releases need no manual Packagist
update. Keep that token in the webhook settings, never in repository files.

Support: [decionis.com/contact](https://decionis.com/contact).
