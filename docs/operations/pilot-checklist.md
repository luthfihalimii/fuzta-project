# Pilot acceptance checklist

First-release acceptance run for the two-branch ordering, kitchen, stock, and audit flows.

- Run date: 2026-10-27
- Environment: local Laravel dev server at `http://127.0.0.1:8123`, PostgreSQL `pizza_fruzta`
- Base code: `42624f1` plus the pilot demo seeder and this record
- Display timezone: `Asia/Jakarta` (database timestamps below are UTC)
- Result: **PASS** for ordering, payments, stock, branch isolation, and audit. No open failure in those areas.
- Not covered: physical thermal-printer output. Receipt pages were verified in the browser; printer hardware/paper width still needs a per-branch test.

## Seed data

`php artisan migrate --seed` created two branches (`PF-01` and `PF-02`), the owner, one cashier and one kitchen account per branch, units, and a non-production sample catalog:

| Item | Data |
|---|---|
| Categories | Pizza, Minuman |
| Modifier group | Tambahan |
| Modifiers | Extra Cheese +10.000, Extra Pepperoni +15.000 |
| Products | Pizza Margherita (Reguler 50.000, Besar 75.000), Air Mineral 5.000 |
| Branch availability | Both products available at both branches |
| Ingredients | Keju Mozzarella (g, threshold 500), Tepung Terigu (g, threshold 1.000), Saus Tomat (ml, threshold 800) |

Demo seeding is skipped when `APP_ENV=production`. The dev database also contained one older `PF-TEST-44571313` order and an unused `Grup quae` modifier group from earlier work; neither is part of this acceptance run.

## 1. Cashier flow, branch PF-01

Create each order in the POS and confirm the receipt page.

| Check | Result |
|---|---|
| Cash order | `PF-PF-01-20261027-0001` (order id 2) |
| Fulfillment | Dine-in, table A-01, customer Rina, order note `Meja dekat jendela` |
| Item | 1x Pizza Margherita, Besar, Extra Cheese; item note `Potong 8, extra cheese` |
| Total | Rp 85.000 |
| Cash received | Rp 100.000 |
| Change | Rp 15.000 |
| Payment status | Paid / Tunai |
| Receipt | Order number, branch, cashier, item/modifier, total, cash, and change all rendered correctly |
| QRIS order | `PF-PF-01-20261027-0002` (order id 3), takeaway, customer Budi |
| QRIS total | Rp 60.000 (Reguler + Extra Cheese), status paid, method QRIS statis |
| Unpaid order | `PF-PF-01-20261027-0003` (order id 4), takeaway, Air Mineral, Rp 5.000, payment sheet closed without payment |

Receipt text for the cash order:

```text
Pizza Fruzta Pusat
No. pesanan: PF-PF-01-20261027-0001
Waktu: 27 Okt 2026, 09:42
Tipe: Dine-in    Meja: A-01
Kasir: Kasir PF-01
1x Pizza Margherita    Rp 85.000
Besar
+ Extra Cheese (+Rp 10.000)
Catatan: Potong 8, extra cheese
Total: Rp 85.000
Status: Lunas    Metode: Tunai
Tunai: Rp 100.000    Kembalian: Rp 15.000
```

Database confirmation:

```text
id | order_number              | status    | payment_status | total
 2 | PF-PF-01-20261027-0001    | completed | paid           | 85000
 3 | PF-PF-01-20261027-0002    | cancelled | voided         | 60000
 4 | PF-PF-01-20261027-0003    | queued    | unpaid         |  5000
 5 | PF-PF-01-20261027-0004    | completed | paid           | 60000
```

Order 0004 was an extra paid QRIS order used to re-confirm kitchen visibility after order 0002 was voided. It was completed during the run.

## 2. Kitchen flow, branch PF-01

- The paid order appeared in the kitchen queue.
- The unpaid order `PF-PF-01-20261027-0003` did not appear in the kitchen queue.
- A second paid order (`PF-PF-01-20261027-0004`) appeared with its note, while the unpaid order remained absent.
- The paid order was moved `accepted -> preparing -> ready -> completed`; the UI accepted each transition.

Status history:

```text
PF-PF-01-20261027-0001  (blank) -> queued     Kasir PF-01  02:42:41
PF-PF-01-20261027-0001  queued -> accepted    Dapur PF-01  02:42:47
PF-PF-01-20261027-0001  accepted -> preparing Dapur PF-01  02:42:48
PF-PF-01-20261027-0001  preparing -> ready    Dapur PF-01  02:42:49
PF-PF-01-20261027-0001  ready -> completed    Dapur PF-01  02:42:50
PF-PF-01-20261027-0004  queued -> accepted    Dapur PF-01  02:45:49
PF-PF-01-20261027-0004  accepted -> preparing Dapur PF-01  02:45:50
PF-PF-01-20261027-0004  preparing -> ready    Dapur PF-01  02:45:51
PF-PF-01-20261027-0004  ready -> completed    Dapur PF-01  02:45:52
```

## 3. Stock flow, branch PF-01

Performed on Keju Mozzarella.

| Step | Quantity | Reason | Actor |
|---|---:|---|---|
| Opening stock | +1.000 g | Stok awal pilot | Pemilik |
| Receipt | +500 g | Penerimaan pilot | Pemilik |
| Waste | -200 g | Rusak saat persiapan | Dapur PF-01 |
| Partial closing count | counted 1.100 g | Opname tutup; correction -200 g | Dapur PF-01 |

Result:

- Ledger rows: 4 (opening, receipt, waste, count correction).
- Balance: **1.100 g**, matching the count.
- Low-stock list after the count: Tepung Terigu (0 g / threshold 1.000 g) and Saus Tomat (0 ml / threshold 800 ml). Keju Mozzarella (1.100 g / threshold 500 g) was not low.
- A negative adjustment of `-2000` was rejected with `Stok tidak boleh negatif.` The balance stayed at 1.100 g and no movement row was written.

Ledger evidence:

```text
id | quantity | type             | reason
 1 |     1000 | opening          | Stok awal pilot
 2 |      500 | receipt          | Penerimaan pilot
 3 |     -200 | waste            | Rusak saat persiapan
 4 |     -200 | count_correction | Opname tutup

Keju Mozzarella balance: 1100 g
```

## 4. Branch isolation and audit

### Cashier PF-02

Signed in as `kasir.pf-02@pizzafruzta.test` and opened `/pos/orders?branch=1` to try to read branch 1 data.

- Branch shown: Pizza Fruzta Cabang 2.
- PF-01 cash order: not visible.
- PF-01 QRIS order: not visible.
- PF-01 unpaid order: not visible.

### Kitchen PF-02

Signed in as `dapur.pf-02@pizzafruzta.test`, opened the kitchen queue, then `/kitchen/stock?branch=1`.

- Kitchen queue showed PF-02, not PF-01.
- No PF-01 order was visible.
- Stock page showed PF-02, not PF-01; the 1.100 g PF-01 cheese balance was not visible.

### Owner void and audit

The owner voided paid order `PF-PF-01-20261027-0002` with reason `Salah input metode pembayaran`.

- Order status: `cancelled`.
- Payment status: `voided`; payment record status: `voided`; `voided_at`: `2026-10-27 02:44:40` UTC.
- Payment events: original `paid` 60.000, then `voided` 60.000 by the owner with the reason.
- Audit entry: `order.voided`, target order 3, actor Pemilik, reason `Salah input metode pembayaran`.
- Receipt page shows `DIBATALKAN` and `Struk ini bukan bukti pembayaran.`

### Cashier cannot void

Signed in as `kasir.pf-01@pizzafruzta.test`:

- The Void button was absent from the paid order.
- A direct `POST /orders/2/void` returned **403** (`This action is unauthorized.`, `AccessDeniedHttpException`).

## Evidence references

Screenshots were captured during the run under `/tmp/opencode/pilot/` (not committed):

`01-pos-pf01.png`, `02-receipt-cash.png`, `03-pos-after-qris.png`, `04-unpaid-order.png`, `05-kitchen-queued.png`, `06-kitchen-completed.png`, `07-stock-opening-receipt.png`, `08-kitchen-stock.png`, `09-negative-rejected.png`, `10-voided-order.png`, `11-audit-void.png`, `12-cashier2-orders.png`, `13-kitchen2-stock.png`, `14-cashier-orders.png`, `15-kitchen-paid-vs-unpaid.png`, `receipt-2.png`, `receipt-3.png`.

The durable evidence is the SQL output and status history recorded above. To reproduce the data checks:

```bash
set -a; . ./.env; set +a
PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -U "$DB_USERNAME" -d "$DB_DATABASE" -P pager=off -c "
select id, order_number, status, payment_status, total from orders order by id;
select o.order_number, e.from_status, e.to_status, u.name, e.created_at
from order_status_events e
join orders o on o.id = e.order_id
left join users u on u.id = e.actor_id
order by e.id;
select id, quantity, type, reason, actor_id from stock_movements order by id;
select i.name, coalesce(sb.quantity, 0) as qty, i.low_stock_threshold,
       coalesce(sb.quantity, 0) <= i.low_stock_threshold as low
from ingredients i
left join stock_balances sb on sb.ingredient_id = i.id and sb.branch_id = 1
order by i.name;
select action, actor_id, reason, target_id from audit_logs order by id;
"
```

Reproduce from a clean local database with:

```bash
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8123
```

## Open items

- No open failure in ordering, payments, stock, branch access, or audit.
- Thermal printer model, connection, and paper width remain unverified per branch.
- The demo catalog, prices, ingredients, thresholds, and opening stock are placeholders; replace them with business-approved data before production.
