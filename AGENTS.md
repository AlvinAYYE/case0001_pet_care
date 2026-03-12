# AGENTS.md

Instructions for agentic coding tools working in this repository.
Prefer facts from the repo over assumptions; keep this file updated when the
actual codebase/config lands.

## Repo Snapshot (What Exists Right Now)

- Present files:
  - `Target.md` (project brief; intended stack and scope)
  - `.idea/` (IDE metadata; not canonical build/lint/test definitions)
- Not present at repo root in this snapshot: `package.json`, `composer.json`,
  `.github/workflows/*`, `.cursorrules`, `.cursor/rules/*`,
  `.github/copilot-instructions.md`.

Implication: there are no repo-derived build/lint/test commands to run yet.
Use the discovery steps below once code/config is added.

## Intended Stack (from `Target.md`)

- Frontend: Vue 3 + TypeScript + Tailwind CSS + Vite + Axios
- Backend: PHP
- Database: MySQL

## Command Discovery (Do This Before Running Anything)

- Find the frontend root by locating `package.json`; then run `npm run` to list
  scripts.
- Find the backend root by locating `composer.json`; then run `composer run` to
  list scripts.
- If `.github/workflows/*.yml` exists, mirror CI locally.
- If config is missing, add minimal standard config and update this file.

## Build / Lint / Test Commands

Everything in this section is conditional: only use commands that match the
tooling actually present in the repo.

### Frontend (when a `package.json` exists)

Common lifecycle commands (if defined in `scripts`):

- Install (CI): `npm ci`
- Install (local): `npm install`
- Dev: `npm run dev`
- Build: `npm run build`
- Lint: `npm run lint`
- Typecheck: `npm run typecheck` (often `vue-tsc --noEmit`)
- Tests: `npm run test` (or `npm run test:unit`)

Optional scripts you may see (only if present): `preview`, `format`, `test:e2e`.

Run a single test (typical patterns):

- Vitest:
  - Single file: `npm run test -- path/to/file.test.ts`
  - Name pattern: `npm run test -- -t "name pattern"`
  - Watch: `npm run test -- --watch`
- Jest:
  - Single file: `npm run test -- path/to/file.test.ts`
  - Name pattern: `npm run test -- -t "name pattern"`

### Backend (when `composer.json` / `vendor/` exists)

Common commands (if dependencies exist):

- Install: `composer install`
- Tests (PHPUnit): `vendor/bin/phpunit`
- Single test file: `vendor/bin/phpunit path/to/Test.php`
- Single test by name: `vendor/bin/phpunit --filter "TestName"`

Static analysis / lint / format (only if configured and installed):

- PHPStan: `vendor/bin/phpstan analyse`
- Psalm: `vendor/bin/psalm`
- PHP_CodeSniffer: `vendor/bin/phpcs`
- php-cs-fixer: `vendor/bin/php-cs-fixer fix`

### Database

No DB/migration tooling is present in this snapshot.
If you add migrations/seeding, document exact commands here and add scripts
(Composer or Makefile) so commands are discoverable.

## Cursor / Copilot Rules

Not found in this snapshot:

- Cursor: `.cursorrules`, `.cursor/rules/*`
- Copilot: `.github/copilot-instructions.md`

If you add them later, summarize the non-obvious constraints here and link to
the canonical file.

## OpenCode Skills (UIUXPro)

Installed in this repo:

- `.opencode/skills/` via `uipro init --ai opencode`
- Skill package: UI/UX Pro Max

Usage guidance:

- For frontend/UI tasks, prioritize the `ui-ux-pro-max` project skill.
- Keep visuals intentional (typography, color system, spacing, motion), and
  avoid generic boilerplate layouts unless the existing design system requires
  it.
- Preserve existing product patterns when working inside an established UI.

## Code Style Guidelines (Baseline)

There is no code in this snapshot to infer local conventions. Use the baseline
below until real code establishes repo-specific style.

### General

- Keep changes small and reviewable; isolate unrelated edits.
- Prefer explicitness over cleverness; optimize for maintainability.
- Avoid hidden side effects; keep pure logic separate from I/O.
- Do not commit secrets; use `.env.example` with fake values.

### Formatting

- JS/TS/Vue: prefer Prettier + ESLint; keep formatting automated.
- PHP: prefer PSR-12 + an automated formatter (php-cs-fixer) if used.
- Do not reformat entire files unless necessary for the change.

### TypeScript / Vue 3

- Vue SFCs:
  - Prefer `<script setup lang="ts">`.
  - Keep template logic simple; move complex logic into composables.
- Types:
  - Aim for `tsconfig` strict mode (`strict: true`).
  - Avoid `any`; prefer `unknown` + narrowing/type guards.
  - Prefer narrow domain types over generic strings (e.g., union literals).
- Naming:
  - Components: `PascalCase.vue`.
  - Composables: `useXxx.ts`.
  - Handlers: `onXxx` / `handleXxx`.
- Imports:
  - Prefer alias imports (e.g., `@/`) if configured; otherwise keep relative
    imports short and consistent.
  - Avoid circular dependencies; move shared types to `types/`.
- Error handling:
  - Model async states explicitly: loading/success/empty/error.
  - Normalize API errors to a single shape before displaying.

### Tailwind / HTTP

- Prefer Tailwind utilities; keep custom CSS minimal.
- Centralize Axios base URL/interceptors; return typed API results.
- Do not swallow errors; normalize API errors to a single UI-safe shape.

### PHP

- Prefer PSR-4 autoloading + namespaces; avoid including files manually.
- Use `declare(strict_types=1);` where feasible.
- Type all parameters/returns; avoid `mixed` unless necessary.
- Error handling:
  - Use exceptions; do not swallow failures.
  - If building JSON APIs, keep error responses consistent (status + JSON body).
- Database:
  - Always use prepared statements; never interpolate user input into SQL.
  - Keep DB access behind a small data-access layer.

## Testing Expectations

- Add tests for non-trivial logic; add regression tests for bug fixes.
- Prefer deterministic tests; avoid time/network flakiness.

## Maintenance Note

When the real codebase/config is added, update this file with:

- Exact commands from `package.json` / `composer.json` scripts.
- The chosen test runner(s) + verified single-test invocations.
- Lint/format toolchain and any repo-specific naming/architecture rules.
