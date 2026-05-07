# Project Documentation

- [docs/architecture.md](docs/architecture.md) — models, sending flow, retry mechanism, tracking, SNS events, BCC guard, events, listeners, provisioning, Nova integration, artisan commands, config reference, migrations, tests, development setup
- [docs/routes.md](docs/routes.md) — API & web routes, tracking endpoints, SES/SNS dashboard, controllers
- [docs/message-lifecycle-scenarios.md](docs/message-lifecycle-scenarios.md) — all 22 message lifecycle scenarios with field changes at each stage
- [docs/ses-environment-config.md](docs/ses-environment-config.md) — SES environment configuration (production, staging, local dev, DNS records)
- [docs/AWS_SES_SETUP.md](docs/AWS_SES_SETUP.md) — AWS SES concepts (identities, config sets, tenants via tags)

## Key Facts

- Namespace: `Topoff\Messenger\`
- PHP ^8.3, Laravel 11/12, AWS SDK ^3.344
- Tests: `composer test` (Pest 4.0)
- Always use Blade block form `@php ... @endphp` — inline `@php()` breaks compiled views in this package
- Development: `php artisan` works in package root (bootstraps workbench), or use `vendor/bin/testbench`

## Key Config Paths

- `messenger.models.message` / `messenger.models.message_type` — model classes
- `messenger.database.*` — connection, table names
- `messenger.tracking.*` — pixel, link tracking, Nova, content storage
- `messenger.ses_sns.*` — AWS SES/SNS provisioning & config sets
- `messenger.bcc.*` — BCC addition control
- `messenger.cleanup.*` — retention periods, schedule cron
- `messenger.sending.*` — environment send check callable

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.3.28

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.
- Use the `database-schema` tool to inspect table structure before writing migrations or models.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - `public function __construct(public GitHub $github) { }`
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<!-- Explicit Return Types and Method Params -->
```php
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
```

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

</laravel-boost-guidelines>

## Deliberate team memory

- This repository uses deliberate, versioned team memory in `.agent-memory/team-memory.md`.
- Before making non-trivial changes in this repository, read `.agent-memory/team-memory.md`.
- If this repository is used inside the Platreform workspace, also respect the workspace-level `AGENTS.md`.
- If you learn a long-lived team fact about this project, propose an update to `.agent-memory/team-memory.md`.
- Do not store personal preferences, temporary debug notes, secrets, credentials, API keys, tokens, or private customer data.
