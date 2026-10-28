# Transfer Antar-Cabang Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Owner can move ingredient stock between the two branches through a recorded draft → dispatched → received (or cancelled) flow, with append-only ledger movements, race-safe locking, and mandatory discrepancy reasons.

**Architecture:** Two new Inventory tables (`stock_transfers`, `stock_transfer_items`) plus four actions, each running one `DB::transaction` that locks the transfer row first, then `stock_balances` rows in ascending `ingredient_id` order (the `ConsumeOrderIngredients` idiom), writing `stock_movements` rows and audit entries inside the transaction. One Inertia admin page (`admin/stock/Transfers`) for create/dispatch/receive/cancel. Stock is touched only through movements, so `inventory:reconcile` stays valid.

**Tech Stack:** Laravel (PHP), PostgreSQL, Vue 3 + Inertia (TypeScript), `php artisan test` (PHPUnit-style feature tests).

**Spec:** `docs/superpowers/specs/2026-10-28-inter-branch-transfer-design.md`

## Global Constraints

- UI copy Bahasa Indonesia; CTAs exactly `Buat transfer`, `Kirim`, `Terima`, `Batal`.
- Quantities are integer in the ingredient's base unit (column type `bigInteger`, matching `stock_movements.quantity`); floats prohibited.
- Authz owner-only, server-side: routes live in the existing `admin.stock` group (`['auth','role:owner']`) AND every FormRequest `authorize()` returns `$user !== null && $user->isOwner()`. Hiding UI is not access control.
- Append-only ledger: `stock_balances.quantity` is written only inside the four transfer actions alongside a `stock_movements` row; no overwrite/delete of movements; no destructive deletes of transfers/items.
- No new dependencies (composer OR npm).
- Movement columns are pinned: `unit_id` = `ingredient.base_unit_id`, `reference_type` = `StockTransfer::class`, `reference_id` = transfer id, `actor_id` = acting user.
- Status machine: `draft → dispatched → received`, `draft → cancelled`. No other transition. No draft-edit route exists (only the dispatch/receive/cancel sub-routes) — absence is the spec-compliant state.
- No throttle on these routes (parity with sibling `admin/stock/*` routes).
- Ledger invariant after every operation: `stock_balances.quantity` = SUM of that (branch, ingredient) `stock_movements.quantity`.
- Concurrency: all stock mutation inside one DB transaction; lock order = transfer row → balance rows ascending by `ingredient_id`.

## Review Focus

1. Receive payload containing an `item_id` that belongs to ANOTHER transfer (passes `exists:stock_transfer_items,id` but is scoped to a different `transfer_id`) → 422, no partial write. → pinned to Task 4, test `test_receive_rejects_items_from_other_transfer`.
2. Receive payload missing one row or repeating one row → 422, transfer stays `dispatched`. → pinned to Task 4, test `test_receive_rejects_missing_or_duplicate_rows`.
3. Concurrent dispatch and consumption on the same origin balance (fork) → no lost updates; both can succeed when stock allows, exactly one movement of each type. → pinned to Task 3, test `test_concurrent_dispatch_and_consumption_do_not_lose_updates`.
4. Draft with the same ingredient on two rows, or item quantity ≤ 0, or a nonexistent ingredient → 422, nothing persisted. → pinned to Task 2, test `test_draft_rejects_invalid_items`.
5. Double-submit of dispatch or receive (second request after the first succeeded) → status guard inside the transaction rejects the second; exactly one movement set per transfer. → pinned to Task 3 (`test_second_dispatch_is_rejected`) and Task 4 (`test_receive_from_wrong_status_is_rejected`, part b).

---

### Task 1: Schema, models, enums, movement sign rules

**Files:**

- Create: `database/migrations/2026_10_28_000007_create_stock_transfers_table.php`
- Create: `database/migrations/2026_10_28_000008_create_stock_transfer_items_table.php`
- Create: `app/Modules/Inventory/Enums/TransferStatus.php`
- Create: `app/Modules/Inventory/Models/StockTransfer.php`
- Create: `app/Modules/Inventory/Models/StockTransferItem.php`
- Create: `database/factories/StockTransferFactory.php`
- Create: `database/factories/StockTransferItemFactory.php`
- Modify: `app/Modules/Inventory/Enums/StockMovementType.php`
- Modify: `app/Modules/Inventory/Actions/RecordStockMovement.php` (`validateMovement` only — append two sign-rule branches next to the `Consumption`/`ConsumptionReversal` ones)
- Test: `tests/Feature/StockTransferTest.php` (new file; later tasks append methods)

**Interfaces:**

- Produces:
    - `App\Modules\Inventory\Enums\TransferStatus` — string backed: `Draft = 'draft'`, `Dispatched = 'dispatched'`, `Received = 'received'`, `Cancelled = 'cancelled'`.
    - `StockMovementType::TransferOut` (value `'transfer_out'`, label `'Pengiriman antar cabang'`), `StockMovementType::TransferIn` (value `'transfer_in'`, label `'Penerimaan antar cabang'`). `requiresOwner()` unchanged (transfer authz is a route-layer concern).
    - `StockMovementType::label()` covers the two new cases with the exact labels above.
    - Sign rules in `RecordStockMovement::validateMovement`: `TransferOut` with `quantity > 0` → ValidationException message `Jumlah pengiriman harus mengurangi stok.`; `TransferIn` with `quantity < 0` → ValidationException message `Jumlah penerimaan harus menambah stok.` (qty 0 already rejected by the existing general rule).
    - `StockTransfer` — `#[Fillable(['origin_branch_id','destination_branch_id','status','created_by','dispatched_at','received_at','cancel_reason'])]` (house Laravel-13 attribute pattern, see `StockCount`); casts: `status => TransferStatus::class`, `dispatched_at`/`received_at` => `datetime`. Relations: `items(): HasMany` (StockTransferItem), `originBranch(): BelongsTo` (Branch via `origin_branch_id`), `destinationBranch(): BelongsTo` (Branch via `destination_branch_id`), `createdBy(): BelongsTo` (User).
    - `StockTransferItem` — `#[Fillable(['transfer_id','ingredient_id','quantity','received_quantity','discrepancy_reason'])]`; relations: `transfer()`, `ingredient()`.
    - `StockTransferFactory` defaults: `origin_branch_id`/`destination_branch_id` = two distinct `Branch::factory(2)` creations, `status` = draft, `created_by` = `User::factory()->owner()`. States: `dispatched()` (status + `dispatched_at`), `received()` (status + both timestamps), `cancelled(string $reason = 'Dibatalkan')` (status + `cancel_reason`).
    - `StockTransferItemFactory` defaults: `transfer` = `StockTransfer::factory()`, `ingredient` = `Ingredient::factory()`, `quantity` = 10.
    - Migration columns — `stock_transfers`: `id`, `origin_branch_id` FK `branches` restrict, `destination_branch_id` FK `branches` restrict, `status` string default `'draft'` + index, `created_by` FK `users` restrict, `dispatched_at` nullable, `received_at` nullable, `cancel_reason` string nullable, `timestamps`. `stock_transfer_items`: `id`, `transfer_id` FK `stock_transfers` restrict, `ingredient_id` FK `ingredients` restrict, `quantity` bigInteger, `received_quantity` bigInteger nullable, `discrepancy_reason` string nullable, `timestamps`, `unique(['transfer_id','ingredient_id'])`.

- [ ] **Step 1: Write the failing tests** in `tests/Feature/StockTransferTest.php` (class uses `RefreshDatabase`):
    - `test_transfer_out_requires_negative_quantity`: direct action call style (see `StockConcurrencyTest`), `(new RecordStockMovement($this->app(WriteAuditLog::class)))($owner, $branch->id, $ingredient->id, 5, StockMovementType::TransferOut, 'Konkur')` → `expectException(ValidationException::class)`.
    - `test_transfer_in_requires_positive_quantity`: same shape, `-5` with `TransferIn` → `expectException(ValidationException::class)`.
    - `test_transfer_factory_states`: for each of `dispatched()` / `received()` / `cancelled('rusak')` assert `status`, the non-null timestamps, `cancel_reason`, and that `items()` is `HasMany` (create one `StockTransferItem` and assert round-trip).
- [ ] **Step 2: Run tests to verify they fail**

    Run: `php artisan test tests/Feature/StockTransferTest.php`
    Expected: FAIL/ERROR — `StockMovementType::TransferOut` undefined, migrations/models missing.

- [ ] **Step 3: Implement** the two migrations, `TransferStatus`, both models (follow `StockCount`/`Ingredient` attribute + relationship style), both factories, the two enum cases (`label()` arms + `requiresOwner()` untouched), and the two `validateMovement` branches.

- [ ] **Step 4: Run tests to verify they pass**

    Run: `php artisan test tests/Feature/StockTransferTest.php`
    Expected: PASS (3 tests). RefreshDatabase proves the migrations run clean.

- [ ] **Step 5: Lint + commit**

    ```bash
    ./vendor/bin/pint database/migrations app/Modules/Inventory tests/Feature/StockTransferTest.php
    git add -A && git commit -m "feat: add stock transfer schema, models, and movement types"
    ```

---

### Task 2: CreateStockTransfer (draft) + store route

**Files:**

- Create: `app/Modules/Inventory/Actions/CreateStockTransfer.php`
- Create: `app/Http/Requests/StoreStockTransferRequest.php` (house location `app/Http/Requests/`, flat)
- Create: `app/Modules/Inventory/Http/Controllers/StockTransferController.php`
- Modify: `routes/web.php` (one line inside the `Route::prefix('stock')->name('admin.stock.')` group)
- Test: `tests/Feature/StockTransferTest.php` (append)

**Interfaces:**

- Consumes: `StockTransfer`/`StockTransferItem` + factories from Task 1; house `WriteAuditLog` (`__invoke(?User, string $action, Model $target, array $before = [], array $after = [], ?string $reason = null)`).
- Produces:
    - `StoreStockTransferRequest` — `authorize()`: `$user !== null && $user->isOwner()`. Rules: `origin_branch_id` `['required','integer','exists:branches,id']`; `destination_branch_id` `['required','integer','exists:branches,id','different:origin_branch_id']`; `items` `['required','array','min:1']`; `items.*.ingredient_id` `['required','integer','distinct','exists:ingredients,id']`; `items.*.quantity` `['required','integer','min:1']`.
    - `CreateStockTransfer::__invoke(User $actor, array $data): StockTransfer` — constructor-injects `WriteAuditLog` (precedent: `RecordStockMovement`). Body: one `DB::transaction` → create transfer (`status` draft, `created_by` actor id) + `items()->createMany` (persist only `ingredient_id`, `quantity`) → audit `transfer.created` (before `[]`, after = origin/dest id+name and item snapshot `[{ingredient, quantity}]`). No stock writes.
    - `StockTransferController::store(StoreStockTransferRequest $request, CreateStockTransfer $create): RedirectResponse` → `return back()->with('success', 'Draft transfer berhasil dibuat.');` (inject action as method param, house pattern).
    - Route: `Route::post('/transfers', [StockTransferController::class, 'store'])->name('transfers.store');`

- [ ] **Step 1: Write the failing tests** (append to `StockTransferTest`):
    - `test_cashier_cannot_create_transfer`: cashier (`User::factory()->cashier()`) `post('/admin/stock/transfers', [...valid payload...])` → `assertStatus(403)`.
    - `test_draft_rejects_same_origin_and_destination`: owner posts with `destination_branch_id === origin_branch_id` → 422 + `assertSessionHasErrors('destination_branch_id')`.
    - `test_draft_rejects_invalid_items`: three payload shapes, each expecting 422 with no row persisted (`stock_transfers` count stays 0): (a) same `ingredient_id` on two rows → error on `items.1.ingredient_id`; (b) `quantity => 0` → error on `items.0.quantity`; (c) nonexistent `ingredient_id` (e.g. 999999) → error on `items.0.ingredient_id`.
    - `test_draft_creates_transfer_without_touching_stock`: origin balance 100, destination 0; valid 2-item draft → 302; transfer row draft with items persisted; both balances unchanged; `stock_movements` count 0; `assertDatabaseHas('audit_logs', ['action' => 'transfer.created', 'target_type' => StockTransfer::class, 'target_id' => $transfer->id])`.
- [ ] **Step 2: Run tests to verify they fail** — Run: `php artisan test tests/Feature/StockTransferTest.php --filter "draft|cashier_cannot"` ; Expected: FAIL (route 404 / class not found).
- [ ] **Step 3: Implement** request, action, controller `store`, route line.
- [ ] **Step 4: Run tests to verify they pass** — Run: `php artisan test tests/Feature/StockTransferTest.php` ; Expected: PASS.
- [ ] **Step 5: Lint + commit**

    ```bash
    ./vendor/bin/pint app/Modules/Inventory app/Http/Requests/StoreStockTransferRequest.php routes/web.php tests/Feature/StockTransferTest.php
    git add -A && git commit -m "feat: draft creation for inter-branch stock transfer"
    ```

---

### Task 3: DispatchStockTransfer (source deduction, in-transit)

**Files:**

- Create: `app/Modules/Inventory/Actions/DispatchStockTransfer.php`
- Create: `app/Http/Requests/DispatchStockTransferRequest.php`
- Create: `tests/Feature/TransferConcurrencyTest.php`
- Modify: `app/Modules/Inventory/Http/Controllers/StockTransferController.php` (add `dispatch`)
- Modify: `routes/web.php` (one line in the stock group)
- Test: `tests/Feature/StockTransferTest.php` (append)

**Interfaces:**

- Consumes: Task 1/2 models + `Ingredient::query()->findMany(...)->keyBy('id')` pattern from `ConsumeOrderIngredients`.
- Produces:
    - `DispatchStockTransferRequest` — owner `authorize()`, no fields (PATCH with no body).
    - `DispatchStockTransfer::__invoke(User $actor, int $transferId): StockTransfer` — constructor-injects `WriteAuditLog`. Body, one `DB::transaction`:
        1. `$transfer = StockTransfer::query()->whereKey($transferId)->lockForUpdate()->firstOrFail();`
        2. If `$transfer->status !== TransferStatus::Draft` → ValidationException `['status' => 'Hanya transfer draft yang dapat dikirim.']`.
        3. `$items = $transfer->items()->orderBy('ingredient_id')->get();` load ingredients `keyBy('id')`.
        4. Per item (ascending `ingredient_id`): `insertOrIgnore` origin `StockBalance` → `lockForUpdate()->firstOrFail()` → `$after = (int) $balance->quantity - (int) $item->quantity`; if `$after < 0` → ValidationException `['stock' => "Stok {$ingredient->name} tidak cukup untuk transfer."]` (entire transaction aborts, transfer stays draft).
        5. `$balance->update(['quantity' => $after])`; `StockMovement::create` — branch = origin, `type` `TransferOut`, `quantity` `-$item->quantity`, `unit_id` = `$ingredient->base_unit_id`, `reason` `"Pengiriman transfer #{$transfer->id}"`, `reference_type` = `StockTransfer::class`, `reference_id` = transfer id, `actor_id`.
        6. `$transfer->update(['status' => TransferStatus::Dispatched, 'dispatched_at' => now()])`; audit `transfer.dispatched` (before `['status' => 'draft']`, after = dispatched + origin/dest names + item snapshot).
    - `StockTransferController::dispatch(DispatchStockTransferRequest $request, StockTransfer $transfer): RedirectResponse` → `($dispatch)($request->user(), $transfer->id)` → `back()->with('success', 'Transfer telah dikirim.');`
    - Route: `Route::patch('/transfers/{transfer}/dispatch', [StockTransferController::class, 'dispatch'])->name('transfers.dispatch');`

- [ ] **Step 1: Write the failing tests** (append):
    - `test_dispatch_reduces_origin_stock_and_writes_transfer_out_movements`: origin balances 100 and 50 for two ingredients; 2-item draft (30, 10) → owner `patch("/admin/stock/transfers/{$id}/dispatch")` → 302; origin balances 70 and 40; exactly two movements `type = 'transfer_out'` with quantities -30 and -10, `reference_type` = `StockTransfer::class`, `reference_id` = id, `actor_id` = owner; transfer `dispatched`, `dispatched_at` non-null; audit `transfer.dispatched` row exists.
    - `test_dispatch_shortfall_rejects_and_changes_nothing`: origin balance 20; draft item qty 30 → patch → 422 + `assertSessionHasErrors('stock')` containing the ingredient name; balance still 20; movement count 0; status still `draft`; `dispatched_at` null.
    - `test_second_dispatch_is_rejected`: after a successful dispatch, patch again → 422 + `errors('status')`; movement set unchanged (still exactly one `transfer_out` per item).
    - `test_concurrent_dispatch_and_consumption_do_not_lose_updates` — lives in the NEW class `tests/Feature/TransferConcurrencyTest.php` (uses `DatabaseMigrations`, mirroring `StockConcurrencyTest`): forked children COMMIT outside the parent's transaction, so a `RefreshDatabase` class would leak their rows into later tests. Origin balance 100; draft of one item qty 60. Fork A: `app(DispatchStockTransfer::class)(User::find($owner->id), $transfer->id)`; Fork B: `app(RecordStockMovement::class)(User::find($owner->id), $branch->id, $ingredient->id, -30, StockMovementType::Consumption, 'Konsumsi pesanan TEST')`. Both children `DB::purge(); DB::reconnect();` first; each exits 0 on success, 1 on any exception. Since 100−60−30 = 10 ≥ 0 in either lock order, both children must succeed: assert exit codes `[0, 0]`; final origin balance 10; exactly one `consumption` and one `transfer_out` movement.
- [ ] **Step 2: Run tests to verify they fail** — Run: `php artisan test tests/Feature/StockTransferTest.php --filter "dispatch"` ; Expected: FAIL (route 404).
- [ ] **Step 3: Implement** request, action (exact lock order and messages from the interface block), controller method, route line.
- [ ] **Step 4: Run tests to verify they pass** — Run: `php artisan test tests/Feature/StockTransferTest.php tests/Feature/TransferConcurrencyTest.php` ; Expected: PASS (all).
- [ ] **Step 5: Lint + commit**

    ```bash
    ./vendor/bin/pint app/Modules/Inventory app/Http/Requests/DispatchStockTransferRequest.php routes/web.php tests/Feature/StockTransferTest.php tests/Feature/TransferConcurrencyTest.php
    git add -A && git commit -m "feat: dispatch stock transfer with origin deduction"
    ```

---

### Task 4: ReceiveStockTransfer (destination addition, discrepancies)

**Files:**

- Create: `app/Modules/Inventory/Actions/ReceiveStockTransfer.php`
- Create: `app/Http/Requests/ReceiveStockTransferRequest.php`
- Modify: `app/Modules/Inventory/Http/Controllers/StockTransferController.php` (add `receive`)
- Modify: `routes/web.php` (one line in the stock group)
- Test: `tests/Feature/StockTransferTest.php` (append)

**Interfaces:**

- Consumes: Task 3 dispatch flow to produce `dispatched` fixtures (use `StockTransferFactory` `dispatched()` + real items, or run the dispatch action — implementer's choice; factories keep it short).
- Produces:
    - `ReceiveStockTransferRequest` — owner `authorize()`. Rules: `received_items` `['required','array']`; `received_items.*.item_id` `['required','integer','distinct','exists:stock_transfer_items,id']`; `received_items.*.received_quantity` `['required','integer','min:0']`; `received_items.*.discrepancy_reason` `['nullable','string','max:2000']`.
    - `ReceiveStockTransfer::__invoke(User $actor, int $transferId, array $receivedItems): StockTransfer` — constructor-injects `WriteAuditLog`. Body, one `DB::transaction`:
        1. `lockForUpdate` the transfer (`firstOrFail`); if status ≠ `Dispatched` → ValidationException `['status' => 'Hanya transfer yang sedang dikirim yang dapat diterima.']`.
        2. Integrity: `$items = $transfer->items()->orderBy('id')->get();` map $receivedItems by `item_id`; if `count($receivedItems) !== $items->count()` or any `item_id` not in `$items` keys (this covers items belonging to OTHER transfers) → ValidationException `['received_items' => 'Semua baris transfer harus diterima sekali.']`.
        3. Per item in ascending `ingredient_id`: `$sent = (int) $item->quantity`, `$received = (int) $row['received_quantity']`, `$pos` = the item's index inside the submitted `received_items` array.
            - If `$received < $sent` and `trim((string) $row['discrepancy_reason'] ?? '') === ''` → ValidationException `["received_items.{$pos}.discrepancy_reason" => 'Alasan selisih wajib diisi.']`.
            - Reason handling: when `$received < $sent`, the trimmed `discrepancy_reason` MUST be non-empty (else ValidationException above); when `$received >= $sent`, a given reason is kept, a blank one becomes `null`.
            - `$item->update(['received_quantity' => $received, 'discrepancy_reason' => $reason])`.
            - If `$received > 0`: `insertOrIgnore` + `lockForUpdate` destination balance → `$after = before + $received` → update; `StockMovement::create` — branch = destination, `type` `TransferIn`, `quantity` `+$received`, `unit_id` = base unit, `reason` `"Penerimaan transfer #{$transfer->id}"` plus ` (selisih: {$reason})` when a reason exists, `reference_type`/`reference_id`/`actor_id` as pinned in Global Constraints. If `$received === 0`: skip the movement entirely (ledger rejects zero) — the reason was mandatory in step 3.
        4. `$transfer->update(['status' => TransferStatus::Received, 'received_at' => now()])`; audit `transfer.received` (before `['status' => 'dispatched']`, after = received + per-item snapshot `{ingredient, sent, received, discrepancy}`).
    - `StockTransferController::receive(ReceiveStockTransferRequest $request, StockTransfer $transfer): RedirectResponse` → `($receive)($request->user(), $transfer->id, $request->validated('received_items'))` → `back()->with('success', 'Transfer diterima.');`
    - Route: `Route::patch('/transfers/{transfer}/receive', [StockTransferController::class, 'receive'])->name('transfers.receive');`

- [ ] **Step 1: Write the failing tests** (append):
    - `test_receive_at_sent_quantity_increments_destination`: full cycle to `dispatched` (2 items 30/10, dest balances 0/0) → POST receive with `received_items` = [{item_id A, 30, reason null}, {item_id B, 10, null}] → 302; dest balances 30/10; two `transfer_in` movements with +30/+10 and reference pinned; transfer `received`, `received_at` set; item rows carry `received_quantity`.
    - `test_receive_shortfall_without_reason_is_rejected`: submit 9 for sent 10 with no reason → 422 + `assertSessionHasErrors('received_items.0.discrepancy_reason')`; status still `dispatched`; 0 `transfer_in` movements; dest balance unchanged.
    - `test_receive_shortfall_with_reason_records_discrepancy`: submit 9 + `'leps hilang 1'` → 302; item row has `received_quantity` 9 + reason; movement qty +9 with reason text in `movement.reason`; audit `transfer.received` row exists AND its `after` JSON contains the discrepancy reason (read the row, `str_contains(json_encode($row->after), '...')`).
    - `test_receive_more_than_sent_is_allowed`: submit 12 for sent 10, no reason → 302; movement qty +12.
    - `test_receive_all_zero_closes_without_movements`: 2 items, submit 0/0 with both reasons → 302; status `received`; `stock_movements` of type `transfer_in` count 0; both `discrepancy_reason` stored; destination balances unchanged by the receive phase.
    - `test_receive_rejects_items_from_other_transfer`: two transfers on the same branches/ingredient; submit the OTHER transfer's item ids → 422 + `errors('received_items')`; status `dispatched` unchanged; no movements.
    - `test_receive_rejects_missing_or_duplicate_rows`: (a) submit only 1 of 2 rows → 422 with `errors('received_items')`; (b) submit the same `item_id` twice → 422 with `assertSessionHasErrors('received_items.1.item_id')` (the `distinct` rule). In both, status stays `dispatched` and no movement is written.
    - `test_receive_from_wrong_status_is_rejected`: (a) a `draft` transfer (never dispatched) → 422 with `errors('status')`; (b) after a successful receive, POST the same payload again → 422 with `errors('status')`; in both, status and movement sets unchanged.
- [ ] **Step 2: Run tests to verify they fail** — Run: `php artisan test tests/Feature/StockTransferTest.php --filter "receive"` ; Expected: FAIL (route 404 / validation absent).
- [ ] **Step 3: Implement** request, action (exact integrity + reason + zero-row rules from the interface block), controller method, route line.
- [ ] **Step 4: Run tests to verify they pass** — Run: `php artisan test tests/Feature/StockTransferTest.php` ; Expected: PASS (all).
- [ ] **Step 5: Lint + commit**

    ```bash
    ./vendor/bin/pint app/Modules/Inventory app/Http/Requests/ReceiveStockTransferRequest.php routes/web.php tests/Feature/StockTransferTest.php
    git add -A && git commit -m "feat: receive stock transfer with discrepancy reasons"
    ```

---

### Task 5: CancelStockTransfer + ledger invariants

**Files:**

- Create: `app/Modules/Inventory/Actions/CancelStockTransfer.php`
- Create: `app/Http/Requests/CancelStockTransferRequest.php`
- Modify: `app/Modules/Inventory/Http/Controllers/StockTransferController.php` (add `cancel`)
- Modify: `routes/web.php` (one line in the stock group)
- Test: `tests/Feature/StockTransferTest.php` (append)

**Interfaces:**

- Consumes: Task 1–4 fixtures.
- Produces:
    - `CancelStockTransferRequest` — owner `authorize()`; rule: `cancel_reason` `['required','string','max:2000']`.
    - `CancelStockTransfer::__invoke(User $actor, int $transferId, string $reason): StockTransfer` — constructor-injects `WriteAuditLog`. Body: one `DB::transaction` → `lockForUpdate` + status ≠ `Draft` → ValidationException `['status' => 'Hanya transfer draft yang dapat dibatalkan.']` → `$transfer->update(['status' => TransferStatus::Cancelled, 'cancel_reason' => $reason])` → audit `transfer.cancelled` (before/after status, `reason`). **No stock writes, ever.**
    - `StockTransferController::cancel(CancelStockTransferRequest $request, StockTransfer $transfer): RedirectResponse` → `($cancel)($request->user(), $transfer->id, (string) $request->validated('cancel_reason'))` → `back()->with('success', 'Transfer dibatalkan.');`
    - Route: `Route::patch('/transfers/{transfer}/cancel', [StockTransferController::class, 'cancel'])->name('transfers.cancel');`

- [ ] **Step 1: Write the failing tests** (append):
    - `test_cancel_draft_transfer`: draft + `cancel_reason: 'salah ketik'` → 302; row `cancelled` + reason stored; movement count 0; audit `transfer.cancelled` row.
    - `test_cancel_requires_reason`: no `cancel_reason` → 422 + `assertSessionHasErrors('cancel_reason')`; status unchanged.
    - `test_cancel_dispatched_is_rejected`: a `dispatched()` transfer → cancel → 422 + `errors('status')`; status still `dispatched`.
    - `test_full_cycle_reconciles_to_ledger`: create → dispatch (with real balances) → receive with one shortfall+reason → `$this->artisan('inventory:reconcile')->expectsOutputToContain('tidak ada selisih')->assertExitCode(0);` then `assertSame(0, StockBalance::where('quantity', '<', 0)->count())`.
- [ ] **Step 2: Run tests to verify they fail** — Run: `php artisan test tests/Feature/StockTransferTest.php --filter "cancel|reconciles"` ; Expected: FAIL (route 404).
- [ ] **Step 3: Implement** request, action, controller method, route line.
- [ ] **Step 4: Run tests to verify they pass** — Run: `php artisan test tests/Feature/StockTransferTest.php` ; Expected: PASS (all).
- [ ] **Step 5: Full suite green + lint + commit**

    ```bash
    php artisan test
    ./vendor/bin/pint app/Modules/Inventory app/Http/Requests routes/web.php tests/Feature/StockTransferTest.php
    git add -A && git commit -m "feat: cancel draft stock transfer + reconciliation invariant"
    ```

---

### Task 6: Transfers page, index route, nav links

**Files:**

- Modify: `app/Modules/Inventory/Http/Controllers/StockTransferController.php` (add `index`)
- Modify: `routes/web.php` (one line in the stock group)
- Create: `resources/js/pages/admin/stock/Transfers.vue`
- Modify: `resources/js/pages/admin/stock/Ingredients.vue`, `Units.vue`, `Recipes.vue`, `Movements.vue`, `Count.vue` (each: append one `<Link href="/admin/stock/transfers">Transfer</Link>` to the existing header link row, copying that file's exact link classes)
- Test: `tests/Feature/StockTransferTest.php` (append)

**Interfaces:**

- Consumes: all actions/routes from Tasks 2–5.
- Produces:
    - Route: `Route::get('/transfers', [StockTransferController::class, 'index'])->name('transfers.index');`
    - `StockTransferController::index(): Response` — `Inertia::render('admin/stock/Transfers', [...])` with props:
        - `transfers`: `StockTransfer::query()->with(['items.ingredient.baseUnit', 'originBranch:id,name', 'destinationBranch:id,name', 'createdBy:id,name'])->latest()->get()` mapped to `{id, status (enum value string), origin_name, destination_name, created_by_name, created_at, dispatched_at, received_at, cancel_reason, items: [{id, ingredient, unit_code, quantity, received_quantity, discrepancy_reason}]}` — datetimes as ISO-8601 strings.
        - `branches`: `[{id, name}]`; `ingredients`: `[{id, name, unit_code}]` (same `baseUnit` mapping as `RecipeController::index`).
    - `Transfers.vue` (house patterns from `Recipes.vue` / `Count.vue`: `StaffLayout`, `Head`, flash `success`/`error` blocks, `rounded-2xl border border-line bg-white` cards, `useForm`, `EmptyState`, `confirm`/`processing` disable, Badge for status):
        - Header: `h1` `Transfer antar cabang` + link row (`Bahan` → `/admin/stock/ingredients`, `Pergerakan stok` → `/admin/stock/movements`).
        - Create card: selects `Cabang asal` / `Cabang tujuan` (both from `branches`), dynamic item rows (ingredient select showing `name (unit_code)` + integer quantity input), `Tambah baris`, per-row remove, CTA `Buat transfer` posts to `/admin/stock/transfers`; per-item dot-form errors per house idiom (`form.errors['items.{i}.{field}']`).
        - One card per transfer: origin → destination label, status badge (`dispatched` shows a visible "In-transit" badge), creator + created date.
            - `draft`: item table (Bahan/Satuan/Jumlah) + CTA `Kirim` (`patch` dispatch) + inline cancel form (reason `Input` + `Batal` button, danger, `patch` cancel) — `ConfirmDialog` has no text input so the cancel reason uses an inline form, not the dialog.
            - `dispatched`: item table + per-row integer input `Jumlah diterima` (default = sent qty) + reason input (`Alasan selisih` — required in form validation when actual < sent), CTA `Terima` (`patch` receive with `received_items`).
            - `received` / `cancelled`: read-only table (include `Jumlah diterima` + `Alasan` where set); cancelled shows `cancel_reason`.
    - UI loads `admin/stock/Transfers`; all CTAs exactly `Buat transfer`, `Kirim`, `Terima`, `Batal`.

- [ ] **Step 1: Write the failing tests** (append):
    - `test_index_page_renders_for_owner`: seed one draft + one dispatched transfer; owner `get('/admin/stock/transfers')` → 200 → `AssertableInertia` `assertComponent('admin/stock/Transfers')` + props `transfers` (2 rows with statuses), `branches` (2), `ingredients` (1+).
    - `test_cashier_forbidden_on_all_transfer_routes`: cashier → GET `/admin/stock/transfers`, POST `/admin/stock/transfers` (valid payload), PATCH `.../{draft}/dispatch`, `.../{draft}/receive`, `.../{draft}/cancel` (with reason) → all `assertStatus(403)`.
- [ ] **Step 2: Run tests to verify they fail** — Run: `php artisan test tests/Feature/StockTransferTest.php --filter "index_page|forbidden"` ; Expected: FAIL (route 404).
- [ ] **Step 3: Implement** controller `index`, route line, `Transfers.vue`, and the five one-link nav edits.
- [ ] **Step 4: Verify tests + UI gates**

    Run: `php artisan test tests/Feature/StockTransferTest.php` — PASS.
    Run: `npm run typecheck && npm run lint && npm run build` — all exit 0.

- [ ] **Step 5: Commit**

    ```bash
    git add -A && git commit -m "feat: inter-branch transfer admin page"
    ```

---

## Execution notes

- Test DB is PostgreSQL `pizza_fruzta_test`; full suite ≈ 1 min. Run the full `php artisan test` after Task 5 (and at the end) — never before declaring done.
- Wall-clock is untouched: this feature must not add date-sensitive assertions (dates come from `now()`; factories use now).
- No new routes beyond the five above; no new vendor/npm packages; no config changes.
