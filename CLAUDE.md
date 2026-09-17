# CLAUDE.md

Guidance for Claude Code (and other AI assistants) working in this repository.

## Repository visibility: public, but internal

This repository is **publicly visible on GitHub**, yet it is maintained for
**internal use by Etrias**. Anyone on the internet can read every commit,
branch, pull request, issue, comment and CI log.

Treat everything you write here as public. **Never include internal Etrias
details** in any content that ends up in this repository or on GitHub. This
applies to code, comments, docblocks, tests, fixtures, commit messages, branch
names, PR titles and descriptions, issue text, review comments and CI output.

Do not include, reference or paraphrase:

- Jira ticket keys or links (for example `ABC-123`), Confluence pages, or any
  other internal tracker or wiki reference.
- Internal project, product, customer, partner or team names.
- Internal hostnames, URLs, IP addresses, environment names, server names,
  container registries or infrastructure layouts.
- Credentials, tokens, API keys, connection strings or other secrets, even
  redacted or "example" values that resemble real ones.
- Internal Slack or Teams channels, email threads, meeting notes or people's
  names beyond what Git authorship already exposes.
- Business logic, pricing, contracts or other information specific to how
  Etrias or its customers operate.

If a task description you receive contains such details (for example it
mentions a Jira ticket), use them to understand the work but **leave them out**
of the deliverable. Describe the change on its own merits instead: what it
does and why it is useful for a generic PHP toolkit.

Prefer neutral, generic wording in examples and fixtures (`example.com`,
`acme`, `foo`/`bar`, placeholder UUIDs). When in doubt, leave it out.

## What this repository is

`etrias/php-toolkit` is a set of reusable, framework-agnostic PHP components
(Cache, Console, Controller, Flysystem, Http, Messenger, Monolog, Performance,
Soap) with optional Symfony integration. It targets the PHP version pinned in
`composer.json` and is released under the MIT license.

Only the `src/` directory ships in the distributed package. See `.gitattributes`
for what is excluded via `export-ignore`.

## Development workflow

All tooling runs inside Docker via the `Makefile`:

```sh
make composer-install   # update lock file, normalize and install dependencies
make lint               # shellcheck, yamllint, phplint
make psalm              # static analysis (baseline in psalm-baseline.xml)
make test               # phpunit
make qa                 # all of the above
```

Dependencies are managed by Renovate; avoid hand-editing `composer.lock`.

## Conventions

- Keep changes small and focused; this toolkit is shared by multiple projects.
- Add or update tests under `tests/` for behavioural changes.
- Keep the psalm baseline honest: fix issues rather than growing the baseline
  unless there is a clear reason.
- Write commit messages and PR descriptions that make sense to an outside
  reader, following the rules in the visibility section above.
