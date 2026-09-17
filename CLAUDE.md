# CLAUDE.md

Guidance for Claude Code (and other AI assistants) working in this repository.

## Repository visibility: public, but internal

This repository is **publicly visible on GitHub**, yet it is maintained for
**internal use by Etrias**. Anyone can read every commit, branch, pull request,
issue, comment and CI log.

In short: **do not disclose secrets or internal Etrias details.** That means no
Jira tickets, Confluence pages or other internal tracker references, no
internal hostnames or infrastructure details, no credentials or tokens, and no
customer, partner or project names. This applies everywhere: code, comments,
tests, fixtures, commit messages, branch names, PR titles and descriptions,
issue text and review comments.

If a task you receive mentions such details (for example a Jira ticket), use
them to understand the work but leave them out of the deliverable. Describe
the change on its own merits, as you would for any open-source PHP library.

Use neutral placeholders in examples and fixtures (`example.com`, `acme`,
`foo`/`bar`). When in doubt, leave it out.

## What this repository is

`etrias/php-toolkit` is a set of reusable PHP components (Cache, Console,
Controller, Flysystem, Http, Messenger, Monolog, Performance, Soap) with
optional Symfony integration. It targets the PHP version pinned in
`composer.json` and is released under the MIT license.

Only the `src/` directory ships in the distributed package. See `.gitattributes`
for what is excluded via `export-ignore`.

## Dependencies

Prefer `require-dev` over `require` in `composer.json`. The toolkit is used by
many projects with differing dependency sets, so hard requirements hurt
compatibility. Keep third-party libraries (Symfony components, Doctrine,
Flysystem, Guzzle, Monolog, ...) in `require-dev` so consumers opt in to only
the integrations they actually use. Only `php` itself belongs in `require`.

Dependencies are managed by Renovate; avoid hand-editing `composer.lock`.

## Development workflow

All tooling runs inside Docker via the `Makefile`:

```sh
make composer-install   # update lock file, normalize and install dependencies
make lint               # shellcheck, yamllint, phplint
make psalm              # static analysis (baseline in psalm-baseline.xml)
make test               # phpunit
make qa                 # all of the above
```

## Conventions

- Keep changes small and focused; this toolkit is shared by multiple projects.
- Add or update tests under `tests/` for behavioural changes.
- Keep the psalm baseline honest: fix issues rather than growing the baseline
  unless there is a clear reason.
- Write commit messages and PR descriptions that make sense to an outside
  reader, following the visibility rules above.
