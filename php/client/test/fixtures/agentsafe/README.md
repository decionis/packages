# AgentSafe conformance fixtures

Synthetic, public conformance data copied unchanged (renamed to PascalCase) from
[decionis/agent-safe-pipeline](https://github.com/decionis/agent-safe-pipeline), Apache-2.0. None
of it is customer, production or merchant data, and none of it is a credential.

| Directory           | Source                                                                                                                                  |
| ------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| `intent/`           | `conformance/agent-safe-intent-v1.json` and `conformance/vectors/*.json` at commit `4b5942fa48bc740ee9c46d977e56fef9a57eff66`           |
| `frameworks/`       | `conformance/frameworks/*.json` (OpenAI, MCP, LangChain, Vercel AI SDK records) at commit `4b5942fa48bc740ee9c46d977e56fef9a57eff66`    |
| `dossiers/zenodo/`  | `dossiers/vectors/*.json` and `dossiers/corpus-jwks.json` at tag `v0.1.4`, the release archived as DOI 10.5281/zenodo.22692903          |
| `dossiers/current/` | `dossiers/vectors/*.json` and `dossiers/corpus-jwks.json` at commit `4b5942fa48bc740ee9c46d977e56fef9a57eff66` (Protocol 1.1 corpus)    |

The corpus key's private half is published upstream on purpose and is not copied here: the tests
only verify. A corpus signature makes no production-authenticity claim.
