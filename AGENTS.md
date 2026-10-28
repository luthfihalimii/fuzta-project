# AGENTS.md — Pizza Fruzta

Instructions for AI coding agents working in this repository. Read this file first, then `PRD.md` for product context. If the two conflict, stop and ask; do not guess.

## Project summary

Pizza Fruzta is a digital ordering and multi-branch operations platform for a pizza business (initially 2 branches, Indonesia). One shared backend serves:

- Public website and customer ordering (Vue)
- POS, kitchen display, and admin (Vue)
- Customer mobile app (Flutter)

Core quality bar: **orders, payments, and stock records must stay correct and explainable across both branches.** Correctness beats features and speed.

## Stack

| Layer | Technology |
|---|---|
| Backend | Laravel (PHP), REST API under `/api/v1` |
| Web UI | Vue 3 + TypeScript + Inertia.js |
| Mobile | Flutter + Dart |
| Database | PostgreSQL |
| Queue/cache | Redis (optional initially) |
| Push | Firebase Cloud Messaging |
| Storage | S3-compatible object storage |

Do not add frameworks, major dependencies, or new services without asking. Lock dependency versions; prefer stable releases.

## Repository layout

> Proposed layout. Update this section once the repo is scaffolded.

```
/                 Laravel app (backend + Inertia web UI)
  app/Modules/    Domain modules (see below)
  resources/js/   Vue 3 + TypeScript
  tests/
/mobile           Flutter customer app
PRD.md            Product requirements (source of product truth)
AGENTS.md         This file
```

## Commands

> Standard defaults. Replace with the real commands once they exist, and keep this list accurate.

```bash
# Backend
composer install
php artisan migrate
php artisan test                # run before every commit
php artisan inventory:reconcile # scan saldo vs ledger; tambahkan --fix untuk menulis ulang + audit
./vendor/bin/pint               # code style (if Pint is installed)

# Web UI
npm install
npm run dev
npm run build
npm run test:js
npm run lint && npm run typecheck

# Mobile
cd mobile && flutter pub get
flutter analyze && flutter test
```

Always run the relevant tests and linters for the code you changed before declaring work done.

## Architecture rules

- **Modular monolith.** Keep modules separated by responsibility: Identity & Access, Branches, Catalog & Pricing, Ordering & Checkout, Payments, POS & Shifts, Kitchen, Inventory & Recipes, Purchasing & Warehouse, Reporting, Notifications, Audit. No microservices.
- **One business-logic layer.** Put logic in services/actions inside the module. Inertia controllers and `/api/v1` controllers must both call the same actions. Never duplicate checkout, pricing, or stock logic across web and API.
- **Controllers stay thin:** validate (Form Requests), authorize (Policies/permissions), call an action, return a response.
- **Laravel is authoritative.** Never trust client-provided prices, discounts, totals, payment state, or stock availability (web or Flutter).
- API: consistent JSON and validation-error format, pagination on lists, filters by branch/status/date, no internal DB IDs used as authorization.

## Non-negotiable business rules

1. **Money:** never use floats. Use integer minor units or fixed-precision decimals. Currency is IDR.
2. **Order totals** (line prices, modifiers, discounts, fees) are recalculated on the server at checkout.
3. **Snapshots:** orders store product name, options, unit price, discount, and quantity at time of sale. Later catalog edits must not change past orders.
4. **Two state machines:** order status and payment status are independent. Do not merge them.
5. **Idempotency:** order creation and payment actions accept an idempotency key. Payment webhooks can arrive multiple times; verify authenticity and process idempotently.
6. **One fulfillment branch per order.**
7. **Stock ledger:** stock changes are append-only `stock_movements` rows with quantity, unit, source/destination, timestamp, actor, and reason/reference. Never overwrite or delete them; correct with reversal/adjustment entries. Any cached `stock_balances` must be reconcilable against the ledger.
8. **Units:** represent units and conversions explicitly. Never mix grams, kilograms, pieces, and packs without a conversion rule.
9. **Negative stock is blocked by default.** Overrides are permission-restricted and audited.
10. **Transfers** go draft → dispatched → received (or cancelled). Dispatch reduces source stock and records in-transit; stock is added to the destination only on receipt. Quantity discrepancies require a reason.
11. **Cancellations/refunds** create reversing financial and inventory entries; they never erase the original transaction.
12. **No destructive deletes** for financial or stock records. Deactivate staff instead of deleting, to preserve attribution.
13. **Concurrency:** order, payment, and stock operations that must stay consistent run inside DB transactions with suitable locking. Add a concurrency test when touching stock deduction.
14. **Time:** use one consistent timezone strategy. Business-facing schedules and reports use `Asia/Jakarta`.

## Security rules

- Enforce authorization **server-side** for every protected action, including branch scope. Hiding UI elements is not access control.
- A staff user must not read or change another branch's data by manipulating requests. Test this.
- Sensitive actions (price changes, discounts, voids, refunds, stock adjustments, role changes, transfer discrepancies) require a permission **and** write an audit log entry (actor, action, target, timestamp, safe before/after values).
- Never log or commit passwords, tokens, payment secrets, or unnecessary personal data. Never store card credentials; store only provider references and safe metadata.
- Secrets come from environment/secret management, never from source control. Keep `.env` out of git.
- Rate-limit authentication, checkout, and other sensitive endpoints.
- Do not expose customer phone numbers or addresses to kitchen staff unnecessarily.
- Production: HTTPS, debug mode off.

## Testing expectations

- Every feature includes automated tests for success **and** failure paths.
- Must-have coverage areas: checkout totals and validation, idempotent retries, webhook replay, role/branch authorization, stock movements per business event, transfer lifecycle, cancellation/refund reversals, cashier shift reconciliation.
- Use factories/seeders with representative two-branch data.
- Do not weaken, skip, or delete a failing test to make a change pass. Fix the cause or ask.

## Definition of done

A change is done when:

- Requirements and acceptance criteria in `PRD.md` are met.
- Authorization and validation are implemented server-side.
- Tests cover core success and failure paths and pass.
- Audit events and error handling are present where relevant.
- UI includes loading, empty, success, and error states.
- API and setup documentation are updated.
- Logs expose no secrets or unnecessary customer data.

## Do NOT decide these yourself — ask

These are open business decisions listed in `PRD.md`. Implement them behind clear configuration or a single isolated point, and ask the team before hard-coding behavior:

- **When ingredients are deducted** (order acceptance, preparation start, etc.) and how cancellations reverse it.
- Payment provider, supported methods (e.g. QRIS), fees, and refund/settlement process.
- Tax / service charge rules.
- Delivery radius, fees, and who delivers.
- Who may approve discounts, refunds, purchases, and stock adjustments.
- Brand colors, typography, logo, and product photography (do not invent a visual identity).
- Receipt printer model and connection method.

## Scope guardrails

MVP only unless told otherwise. The following are **out of scope** for the MVP: loyalty/CRM, delivery-driver app and live GPS, demand forecasting, accounting integration, franchise/multi-entity, offline-first POS, microservices, and full ERP features. Do not add them "while you're at it."

## Working style

- Make small, focused changes. One concern per commit/PR.
- Follow existing conventions in the surrounding code before introducing new patterns.
- Prefer clarity over cleverness; restaurant staff-facing flows should need minimal steps.
- If requirements are ambiguous or a change affects money, stock, or permissions, state your assumption explicitly or ask before proceeding.
- Never run destructive commands (dropping databases, `migrate:fresh`, force-push) against any shared or production environment.
- Update `PRD.md`, this file, and API docs when behavior or conventions change.
