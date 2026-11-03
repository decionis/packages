# Commerce Gate fixtures

Recorded, synthetic Commerce Gate data the client's tests validate against. None of it is
customer, production or merchant data, and none of it is a credential.

| File                                                               | Source                                                                                                                                                                            |
| ------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `evidence/Permitted.json`, `BelowCost.json`, `MissingContext.json` | Recorded evidence of three Commerce Gate evaluations, generated 2026-10-04 against a synthetic authority (`synthetic: true`, `production_authority: false`, no external requests) |
| `evidence/PublicVerificationKeys.json`                             | The public Ed25519 key set that verifies those recorded Dossiers                                                                                                                  |
| `CommerceGateOpenApi.json`                                         | The Commerce Gate API contract, version 1.4.0-candidate, identical to `https://commerce.decionis.com/.well-known/openapi.json` as served on 2026-11-03                            |

Refresh them together with the contract: a response the contract no longer describes fails the
contract tests, which is the point.
