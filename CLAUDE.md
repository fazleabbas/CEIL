# FEATURE: Budget amounts per supplier [x]
**Status: COMPLETED** — display only, no schema change, no migration. Verified
on all 30 budgets with a temporary Admin session file (removed).

**Files (MODIFIED, 3):** `modules/transactions/budget_view.php`, `lang/en.php`,
`lang/sw.php`. Repo now in git (github.com/fazleabbas/CEIL, private),
`core.autocrlf=false` so files are stored byte-exact for FTP deploys.

## Decisions (user-confirmed)
- Supplier Summary table (Supplier | Type | Goods | VAT | Total) under the lines
  table, every status, PRINTED. Grouped by supplier + type like the cards.
- Procurement cards gain Budgeted / Procured / On PO / Remaining VALUES, at the
  **BUDGET rate incl. pro-rated VAT** (option a), not actual purchase amounts,
  so they always reconcile to the budget.
- Approve modal gets the same summary, recomputed live in `recalcApprove()`.

## Worth knowing
- Card values are computed per supplier+type+PRODUCT, handing procured then
  on-PO qty across the product's rows in order. The quantity rows above still
  apply a product's whole procured qty to EACH row (pre-existing, untouched),
  so on a product split over 2 rows the qty rows and the value line can differ.
- Verified: summary == SQL on 30/30; card values == independent SQL on 25/25
  approved (15 with purchases/POs); 0 PHP diagnostics; 6 inline scripts pass
  `node --check`. Pre-change render diff shows only additions.

---
---

# FIX: `&amp;` showing on screens (master data) + Auto Approved label [x]
**Status: COMPLETED** — verified through the real save APIs and screens, test rows
removed. **Needs migration 0052.** Master data only, by user decision: remarks and
other free text still go through `clean()` and can still show `&amp;`.

**Files (NEW, 1):** `sql/migrations/0052_decode_html_entities_in_master_data.sql`
**Files (MODIFIED, 12):** `api/locations-sync.php`, `cost_centres-sync.php`,
`customers-sync.php`, `suppliers-sync.php`, `categories-sync.php`,
`modules/payments/payments_form.php`, `receipts_form.php` (new-account names only),
`index.php`, `modules/transactions/bom_view.php`, `boq_view.php`, `lang/en.php`,
`lang/sw.php`

- Cause: `clean()` stores an HTML-encoded copy, and output encodes it again.
  Those save paths now use `cleanText()`. Output must keep `htmlspecialchars()`:
  `cleanText()` strips tags but leaves quotes.
- **Never write a literal semicolon inside a migration string.**
  `executeMigration()` splits on it. 0052 builds entities with `CHAR(59)`.
- `UPDATE IGNORE`: a decoded name/code that would clash with a UNIQUE value is
  left encoded, not merged. Angle-bracket entities are deliberately not decoded.
- Some cost centre codes were cut at varchar(50) while encoded. Decoding cannot
  restore the lost characters.
- Approved By shows "Auto Approved" when status is approved and `approved_at` is
  empty, i.e. the record was approved by a migration, not a person.

---
---

# FEATURE: BOQ approval, with protected revisions [x]
**Status: COMPLETED** — verified with real form/API posts, 2 temporary users
(removed) and a headless Edge click-through. BOQ/BOM tables diff IDENTICAL after
cleanup. **Needs migration 0051.**

**Files (NEW, 2):** `sql/migrations/0051_add_boq_approval.sql`, `api/boq-sync.php`
**Files (MODIFIED, 7):** `includes/functions.php`, `modules/transactions/boq.php`,
`boq_list.php`, `boq_view.php`, `bom.php`, `lang/en.php`, `lang/sw.php`

## Decisions (user-confirmed)
- Same shape as BOM approval: Draft -> Pending -> Approved / Rejected; Revise
  takes Approved or Rejected back to Draft. Plain approve/reject, no edit.
  Unlike BOM, a rejection REQUIRES a reason.
- Approver = `boq.approve`; Revise = `boq.edit` OR `boq.approve`. Gates live in
  `boqCanEdit/Delete/SendForApproval/Revise()`. Do not re-inline them.
- Existing BOQs became APPROVED (column added with default 'approved').
- Only an APPROVED BOQ may be newly linked to a BOM (`boqLinkError()`). A link a
  BOM already has is always kept, and stays selectable in its dropdown.

## THE SNAPSHOT IS WHAT PROTECTS A REVISION
`boqs.approved_snapshot` = JSON `{product_id: qty}`, written when an APPROVED BOQ
is revised, cleared only on approve. While set, `getBoqProductBalances()` reads
it instead of `boq_details`, so unapproved quantities never reach a BOM balance.
- A rejected revision KEEPS the snapshot; revising it again must not overwrite
  it, because `boq_details` then holds unapproved figures.
- A BOQ with a snapshot cannot be deleted: that would remove the approved
  version. A never-approved Draft or Rejected BOQ can be deleted.
- `boq_view.php` lists approved vs revised per product while a snapshot exists.
- Approving a reduction does NOT re-check BOMs already approved. Accepted.

## Testing gotcha
`<option value="2"` also matches other selects on `bom.php`. Scope any check to
`select[name=boq_id]`.

---
---

# FEATURE: Product filter on the Purchases list [x]
**Status: COMPLETED** — no schema change, no migration. Verified in headless
Edge over CDP (14 checks, all matching SQL), as Admin and a location-scoped user.

**Files (NEW, 1):** `api/purchased-products.php` (read-only)
**Files (MODIFIED, 3):** `modules/transactions/purchases.php`, `lang/en.php`,
`lang/sw.php` (`filter_by_products`)

## Decisions (user-confirmed)
- Multi-select (Select2) on its own row under the existing filters. A purchase
  shows if it holds ANY selected product. Combines with dates, search box and
  the `?supplier_id=` drill-down.
- **Options = every product EVER purchased** at the user's locations (any
  date, inactive products included), from `api/purchased-products.php`.
  **Results still follow the dates.** The first cut built options from the
  date-scoped purchases, so on the default range (today) the dropdown was
  EMPTY. Do not tie the options back to the date range.
- Offline / fetch failure: options fall back to products on purchases cached
  in IndexedDB. In-memory purchases are always merged in (temp_* offline ones).
- **Picking the FIRST product switches the list to ALL TIME** (both date boxes
  blanked); the user may then type dates to narrow it. The dates in use before
  are saved in `sessionStorage['purchasesFilterDatesBeforeProducts']` and put
  back when the LAST product is removed. Adding/removing further products
  leaves the dates alone. Clear wipes the saved dates too.
- Remembered in `sessionStorage['purchasesFilterProducts']` and kept across
  date changes; shown as chips, so never a hidden filter. On load, a stored
  BLANK date stays blank when products are remembered (otherwise blank falls
  back to today, as before). No separate Filter button: the list applies on pick.
- The search box was NOT extended to product names (Option A declined).

## Testing gotcha
On 0 results `displayPurchases()` hides the table but leaves the OLD rows in
`#purchasesTableBody`. Read rows only when `#purchasesContainer` is visible.

---
---

# FEATURE: Separate View right per report [x]
**Status: COMPLETED** — verified with one temporary user (removed; users,
permissions, locations and cost-centre access diff IDENTICAL) and in headless
Edge. **Needs migration 0050.**

**Files (NEW, 1):** `sql/migrations/0050_split_reports_permission_per_report.sql`
**Files (MODIFIED, 24):** the 17 report pages and exports that checked `reports`,
`api/dead-stock-detail.php`, `ajax/dashboard-data.php`, `includes/functions.php`,
`includes/header.php`, `modules/masters/users_form.php`, `lang/en.php`, `lang/sw.php`

## Design
- Rights are ordinary `user_permissions` rows named `report_<key>`. No schema change.
- `getReportRights()` is the single list. **A new report needs an entry there, a
  menu item and a `canViewReport('<key>')` gate on its page, export and any API.**
- Stock Card and Location Breakdown come with `stock_report` (user-confirmed): no
  menu entry, only reached by clicking through. Stock Value's 4 pages share one right.
- A link into a report the user lacks is hidden, not left to bounce: comparison ->
  cost centre report, stock reports -> stock value, Dead Stock -> Stock Card.
- Stock Value had no menu item (dashboard link only, and the dashboard is
  Admin-only), so one was added or the right would have led nowhere.

## THE OLD ROW IS A BRIDGE, NOT A BACKDOOR
`canViewReport()` honours the old `reports` row so uploading code before running
the migration cannot lock anyone out. The migration gives each holder all 9 rights
and deletes the row. `users_form.php` shows a surviving old row as all 9 ticked, so
saving a user before the migration keeps their access. Once the rows are gone, a
re-run copies nothing, so a removed right is never given back.

`index.php:507,553` still call `hasPermission('reports')`. Harmless: that part of
the page is Admin-only and Admins pass every check.

## Decisions (user-confirmed)
- Stock Taking stays inside the Reports menu, so a user with Stock Taking but no
  report still gets no menu (21 of 23 on the 8 Sep snapshot). Unchanged on purpose.
- That link now needs the Stock Taking right; before, it showed and then refused.
- `ajax/dashboard-data.php` is Admin-only. Before this, any logged-in user could
  pull total stock value and stock lists by typing the URL (reproduced as Stores).

## Verification
Each right alone against all 18 report URLs (9 x 18), no rights, `can_view = 0`,
old row, Admin; 276 checks across both runs, plus the form save round trip. A test
needing page rows must give the user a cost centre: the comparison page lists only
assigned ones, so a "no link" check on an empty page proves nothing.

---
---

# FEATURE: Purchase Status (Open/Closed) on the budget list [x]
**Status: COMPLETED** — read-only, no schema change, no migration. Rule tests ran
on `cei_prod_b` inside rolled-back transactions; the page was checked in headless
Edge with a temporary session file.

**Files (MODIFIED, 4):** `includes/functions.php`
(`getBudgetPurchaseStates()`), `modules/transactions/budget_list.php`,
`lang/en.php`, `lang/sw.php`

## Decisions (user-confirmed)
- Closed = every supplier + product + type allocation fully purchased or imported
  through documents linked to the budget. Computed live, never stored, so
  deleting a purchase reopens the budget.
- **Quantity on a PO is NOT purchased, so that budget stays Open.** This differs on
  purpose from `budget_view.php`, whose "Fully procured" badge subtracts open POs
  and really means nothing is left to raise. 13 budgets on the snapshot show both.
- Only approved budgets get a state; the rest show a dash.
- No manual close: a line bought short keeps the budget Open.
- Open/Closed filter added. It runs in PHP after the lookup, since nothing is stored.
- The info icon in the column header opens a modal listing these rules. **If a rule
  changes, update the `budget_purchase_rule_*` lang keys too.**

## Worth knowing
`budget_view.php:122-126` subtracts a product's whole purchased quantity from EACH
row of that product, so a product on several rows can show "Fully procured" early.
The list sums per product and is not affected. Not fixed, out of scope.

---
---

# FEATURE: Dead Stock report [x]
**Status: COMPLETED** — read-only, no schema change, no migration. Verified with
temporary session files (no test users) and a real headless Edge click-through.

**Files (NEW, 4):** `includes/dead_stock_query.php`,
`modules/reports/dead_stock.php`, `dead_stock_export.php`,
`api/dead-stock-detail.php`
**Files (MODIFIED, 3):** `includes/header.php`, `lang/en.php`, `lang/sw.php`

## Decisions (user-confirmed)
- Filters: days (default 90, 1-3650), location, category. Runs on Generate only.
- One row per product per EXACT location; a parent filter lists its children
  separately, never rolled up. Idle means `days_idle >= N`.
- Movement = purchase, import, sale, consumption (incl. returns), transfer out
  from dispatch, transfer in from receipt.
- **Opening stock and adjustments are NOT movement**, but do count in quantity.
  Counting opening would make a 365-day report empty; most stock has only an
  opening entry. Those rows show "Never moved" with no days figure.
- Summary rows click through to a popup: latest document per movement type, plus
  adjustment and stock count marked as not counting. Any-status stock counts are
  used because this tenant's are all draft.
- Inactive products excluded, matching the stock value pages.

## Things to keep
- Report and export share `deadStockFetchRows()`. Quantity + rate formulas match
  `stock_value_by_location_products.php`; reconciled on `cei_prod_b` at days=1:
  92 locations, 0 differing, total 15,198,592,964.94.
- Location access is stricter than the older reports: a parent's children are
  intersected with the user's own locations (`deadStockResolveLocationIds()`).
- "Excel" is a UTF-8 BOM CSV, the same as `stock_report_export.php`. Text cells
  starting with = + - @ are prefixed so Excel cannot run them as formulas.
- 2 product names on this tenant contain a line break (ids 3499, 3619): parse
  the export with a real CSV reader, never by splitting lines.
- `last_mark` packs date and kind into one string (`date|kind`) so MAX() returns
  the type of the latest movement without a second query.

---
---

# FEATURE: BOM approval + BOQ balance per BOM item [x]
**Status: COMPLETED** — verified via curl with 3 temporary logins (editor,
approver, Admin) and 2 test BOMs, all removed; boms / bom_details /
breakdowns / boqs / boq_details / requisition_vouchers diff IDENTICAL to
pre-test snapshots (original columns). **Needs migration 0049.**

**Files (NEW, 3):** `sql/migrations/0049_add_bom_approval_and_boq_link.sql`,
`api/bom-sync.php` (send_for_approval / approve / reject / revise),
`api/boq-balances.php`
**Files (MODIFIED, 9):** `includes/functions.php`, `modules/transactions/bom.php`,
`bom_view.php`, `bom_list.php`, `requisition_voucher.php`,
`requisition_voucher_view.php`, `api/requisition-vouchers-sync.php`,
`lang/en.php`, `lang/sw.php`

## Decisions (user-confirmed)
- Draft -> Pending -> Approved / Rejected; Revise takes Approved OR Rejected
  back to draft (clears all approval/rejection fields, must be re-approved).
  **Plain approve/reject, NO approve-with-edit** — breakdowns must sum to the
  line total, so editing qty in a modal would break them.
- Approver = `bom.approve` (the existing checkbox); `notifyApprovers('bom')`.
  Signature pad + profile-signature fallback (approver IS the logged-in user).
- Only draft is editable; delete = draft/rejected only. Gates live in ONE place:
  `bomCanEdit/Delete/SendForApproval/Revise()` — form, view and list all call
  them. Pre-0049 rows (no `status` key) keep the old always-editable behaviour.
- **Existing BOMs became APPROVED**: the column is ADDED with default
  'approved', then the default is switched to 'draft'. Making them drafts would
  have dropped them out of the RV dropdown overnight.
- BOQ link `boms.boq_id` is optional. Balance is **per PRODUCT** (summed over
  lines, breakdown labels ignored): `BOQ qty - qty on OTHER BOMs of that BOQ in
  pending_approval/approved` (drafts + rejected claim nothing — same rule as
  budgets vs RV). `getBoqProductBalances()` / `bomBoqExcessProducts()`.
- Over the BOQ balance = **allowed with a written reason** (option c), stored
  on every row of that product in `bom_details.boq_excess_reason` (cleanText,
  raw). Checked on the form POST AND re-checked inside the approve transaction
  (the balance can move in between); approver may type one, else the stored one
  is reused; a product back within balance has its reason cleared.
- Product NOT on the BOQ = flagged "Not in BOQ", **no reason required**.

## RV side — only APPROVED BOMs may be newly linked
`bomLinkError()` guards all three write paths (RV form POST, sync create/update,
`map_bom`). **A link the RV already has is always kept** whatever the BOM's
status — the RV form now appends the linked BOM to its dropdown (the view
already did), otherwise revising a BOM to draft would make the next RV save
silently UNLINK it. Verified: revised BOM stays `selected`, re-map is a no-op.

## Verification
- Migration run 3x, idempotent. Lint clean; 28 inline scripts pass
  `node --check`; 0 PHP diagnostics on list / form / view (editor + approver).
- Guards: unexplained excess rejected on save (nothing written) and on approve
  (rolled back, stayed pending); send/approve/revise permission matrix; approve a
  rejected BOM refused; hand-posted delete of an approved BOM ignored; edit of a
  pending BOM 302.
- Form JS run in headless Edge against a stub balance (page served from a temp
  dir, since removed): duplicate-product lines aggregate, reason box appears /
  disappears / remembers text, Not-in-BOQ needs none, clearing the BOQ hides all.
- Cleanup reset AUTO_INCREMENT on boms 6, bom_details 450, breakdowns 450,
  notifications 1219, audit_log 9996+1, users 46, user_permissions 1800,
  user_locations 5409; RV000106 `updated_at` restored explicitly.

---
---

# FEATURE: Payment Terms + Delivery Terms on Purchase Orders [x]
**Status: COMPLETED** — verified via curl with a temporary Admin login (removed).
`purchase_orders`, `purchase_order_details` and `settings` diff IDENTICAL to
pre-test snapshots afterwards. **Needs migration 0048.**

**Files (NEW, 1):** `sql/migrations/0048_add_terms_to_purchase_orders.sql`
**Files (MODIFIED, 6):** `modules/transactions/purchase_order.php`,
`purchase_order_view.php`, `api/purchase-orders-sync.php`,
`modules/admin/company_settings.php`, `lang/en.php`, `lang/sw.php`

Users were typing "1) Payment Terms: ... 2) Delivery Terms: ..." into remarks.
Decisions (user-confirmed): multi-line free text (TEXT columns); pre-filled
from Company Settings defaults (`po_default_payment_terms` /
`po_default_delivery_terms`, not seeded) on a NEW PO only — an edit shows the
PO's own stored values; existing remarks NOT backfilled; the approver CAN
change them in the approve modal.

- Stored with `cleanText()` (raw, escaped on output) — NOT `clean()` like
  remarks, which HTML-encodes on save. Tags stripped, `&` stored as `&`.
- Approve API: key ABSENT = leave alone, present-but-empty = clear
  (`array_key_exists`). Verified both.
- View: screen row + two printed header rows above Remarks, each emitted only
  when non-empty — an old PO renders with 0 terms rows.
- All 3 insert/update paths verified: direct draft, edit, budget PO (born
  approved, prefilled with the defaults).
- Not carried into the Purchase raised from a PO (purchases have no terms).

---
---

# FEATURE: Consumption Voucher RETURNS [x]
**Status: COMPLETED** — verified via curl with a temporary login (removed)
against a real 5-batch product. `stock_batches` **byte-for-byte identical** to
the pre-test snapshot afterwards. **Needs migration 0047.**

**Files (NEW, 2):** `sql/migrations/0047_add_consumption_voucher_returns.sql`,
`modules/transactions/consumption_voucher_return.php`
**Files (MODIFIED, 5):** `includes/functions.php`,
`modules/transactions/consumption_voucher.php`, `consumption_voucher_view.php`,
`consumption_voucher_list.php`, `lang/en.php`, `lang/sw.php`

## Why not just Edit the voucher down
Editing rewrites history: the voucher then claims only the net quantity ever
went out, the return date is lost, and the movement is backdated to the
original document. Material issued in August and returned in September would
post the September movement in August.

## THE SIGN CONVENTION IS THE WHOLE DESIGN — do not "clean it up"
A return is an ORDINARY `consumption_vouchers` row with **negative quantities,
amounts and total**, flagged `return_of_voucher_id`. Consumption is aggregated
as a plain `SUM(cvd.quantity)` in **EIGHT report files** (stock_report,
stock_report_by_type, product_stock_card, product_location_breakdown,
cost_centre_report, cost_centre_comparison and others). Negative rows net out
there by themselves, so **ZERO report files were touched**.

Both alternatives were worse:
- a separate `consumption_returns` table is INVISIBLE to all eight, leaving
  consumption permanently overstated;
- a return flagged but POSITIVE makes all eight count it as *more* consumption.

Verified live: 70 issued, 20 returned -> stock_report formula returns **50**,
cost_centre_comparison formula returns **51,946** (74,206 - 22,260), and
physical on-hand is **78** = 128 - 50. Books agree with the shelf.

## Stock goes back on the ORIGINAL batches, in REVERSE order of consumption
Most recently drawn layer first, so the consumption that remains still sits on
the earliest layers. `cvReturnableBatches()` computes per-batch availability as
`taken - given_back`, ordered by link id DESC.

Verified on a 5-batch product: consuming 70 drew 8@650 (b32) + 50@1113 (b6412)
+ 12@1113 (b6538). Returning 20 gave back **b6538 first (12), then b6412 (8)** —
exactly reverse. A second return of 5 correctly **skipped the now-exhausted
b6538** and took from b6412.

## THE NEGATIVE BATCH LINKS ARE WHY cvRestoreStock() NEEDED NO CHANGE
The return writes its own `consumption_voucher_batches` rows with **NEGATIVE**
quantity against the same batch_id. `cvRestoreStock()` does
`quantity_remaining + quantity`, so deleting a return SUBTRACTS the stock it had
given back. Verified: deleting a 5-unit return took b6412 from 13 back to 8, net
consumption 45 -> 50, 0 orphan links. **Not one line of that helper changed.**

## EDIT AND DELETE ARE DIFFERENT GATES (this is deliberate)
`cvCanEdit()` and `cvCanDelete()` in functions.php, called by the form, the view
AND the list so they can never disagree about who sees a pencil.

| voucher | edit | delete |
|---|---|---|
| plain | yes | yes |
| has returns against it | **no** | **no** |
| IS a return | **no** | **yes** |

A return must stay DELETABLE — deleting and raising another is the only way to
correct one. What must never happen is deleting a voucher that has been
returned against: `cvRestoreStock()` would put the FULL issued quantity back on
top of what already came back, leaving **more in stock than ever went out**.
That is guarded in the view's delete handler too, for a hand-typed `?delete=`.

Deleting the LAST return unlocks the parent again — verified, edit form back to
200 and the batches byte-identical to their post-consumption state.

## Other decisions (all user-confirmed)
- Location and cost centre are INHERITED and not editable — the stock has to go
  back onto batches that live at the original location.
- Separate number series via `cvr_prefix` = `CVR` (seeded by the migration).
- Same `consumption_vouchers` permission; `create` raises a return.
- View page only, no separate printable. The delivery note is untouched.
- Pre-0040 vouchers with no batch links fall back to a NEW batch at the rate the
  original line recorded — the same fallback `cvRestoreStock()` already had.

## Verification
- Guards, each rejected with NOTHING written (voucher count and batch quantity
  unchanged): over-return 46 of 45 returnable, all-zero lines, a `detail_id`
  belonging to another voucher, a negative quantity, and a missing CSRF token
  (dies with `CSRF token validation failed`). The over-return message names the
  real figure: "more than the 45.000 still returnable".
- Locks: edit parent 302, edit the return 302, return a return 302, hand-typed
  `?delete=` on the returned-against parent 302 — all 3 vouchers still present.
- List: 1 Return badge, **0** pencils (parent locked, return locked), and
  exactly **1** delete button, on the return. NOTE `grep -c confirmDelete`
  counts the JS function DEFINITION too — match `onclick="confirmDelete(`.
- Re-check runs INSIDE the transaction, so a second person returning between
  page load and POST cannot overshoot.
- 0 PHP diagnostics across all 6 CV pages; 4 inline `<script>` blocks pass
  `node --check`. Migration idempotent (re-run 4x). Full en/sw key parity.
- 0 GL rows written — a CV posts nothing to `transactions`, so a return has no
  journal to reverse.
- **Restore discipline:** `SELECT *` snapshot of the product's batches taken
  BEFORE testing; after cleanup the quantities matched but `updated_at` had
  bumped on the 3 touched rows (`ON UPDATE CURRENT_TIMESTAMP`), so it was
  reassigned EXPLICITLY. Final `diff` byte-for-byte identical. Test user, its
  permissions and all test audit rows removed; the 5 genuine CV audit rows
  (June/Aug 2026) left intact.

## KNOWN GAP (pre-existing, NOT introduced, NOT fixed)
`consumption_voucher_view.php` calls `formatCurrency($amount)` with ONE argument
everywhere, so it renders the TENANT DEFAULT currency and ignores the voucher
`currency_id` — the same defect class as the payments/lists fixes, which never
reached this page. The new Returns panel deliberately MATCHES that behaviour
rather than rendering one lone correct cell that disagrees with the total below
it. Fixing it means `getCurrency($voucher['currency_id'])` on all 5 call sites.

## Also worth knowing
A return appears in `product_stock_card.php` as a consumption line with a
NEGATIVE quantity, not a distinctly labelled "Return" row. Arithmetic is right
and the number reads `CVR...`; labelling it would mean editing report files,
which is exactly what this design avoids. User-accepted.

---
---

# FEATURE: Project Manager / Engineer sign-off on Consumption Vouchers [x]
**Status: COMPLETED** — verified via curl with a temporary login (removed) and
two test vouchers (deleted through the app so stock reversed precisely; batch
2950 back to 77,630.000, 0 GL rows written). **Needs migration 0046** — unlike
the last several features this one was NOT free: consumption_vouchers had no
signature column of any kind.

**Files (NEW, 1):** `sql/migrations/0046_add_project_manager_to_consumption_vouchers.sql`
**Files (MODIFIED, 5):** `modules/transactions/consumption_voucher.php`,
`consumption_voucher_view.php`, `consumption_voucher_dn.php`, `lang/en.php`,
`lang/sw.php`

## The gap
A CV has **no approval workflow at all** — no `approved_by`, no `*_signature`,
create/edit/delete only. Yet the delivery note printed three blank ruled boxes
(Prepared By / Approved By / **Received By**), and "Approved By" was backed by
nothing in the data. The person who actually authorises material leaving a site
is the PM or site engineer, and there was nowhere to record them. Zero hits for
`project_manager` / `engineer` anywhere in the app before this.

## FREE TEXT, NOT A LINK TO `users` (user-confirmed)
The site engineer frequently has no login. So `project_manager_name` is
varchar(150) free text and `project_manager_signature` is a base64 data URL
drawn on a pad at entry time.

## THE SIGNATURE MUST NOT FALL BACK TO THE CREATOR
`requisition_voucher.php` fills Prepared By from
`getUserSignature($conn, getUserId())` when the pad is left blank — correct
there, because the preparer IS the logged-in user. Here it would stamp the
storekeeper's real e-signature into a box asserting the PM authorised the
issue. **Same defect class as the budget-PO approver attribution bug (0043).**
Blank pad = NULL, full stop. For the same reason the shared upload control is
included with **`$sigUploadOfferSave = false`** — the save-to-my-profile
checkbox must never appear on a third party's signature. Verified: 0 in the
rendered form.

## THREE POST OUTCOMES, because a re-save must not destroy a signature
`array_key_exists` distinguishes them:
- a valid data URL -> stored;
- field present but **empty** -> explicit removal (the hidden input is
  pre-seeded with the signature already on the voucher, so it can only come
  back empty if Clear was pressed — `clearPmSignature()` blanks the input too);
- field **absent, or contents unparseable** -> column left ALONE.

Verified in that order on one voucher: absent -> sig survived at 1882 bytes;
`javascript:alert(1)` -> survived, neither stored nor destroyed; empty ->
NULL; name blanked -> NULL.

## The name is truncated in PHP, deliberately
`sql_mode` here is `NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION` — **no
`STRICT_TRANS_TABLES`** — so a 200-char name was silently cut to 150. Harmless
here (display only, no unique key, unlike the cost-centre CODE case). But on a
tenant that IS strict the same POST would throw and **roll back the whole
voucher save**, so `mb_substr(..., 0, 150)` makes it behave identically
everywhere.

## "Approved By" was LEFT ALONE — the PM is a FOURTH box (user-confirmed)
The DN now renders 4 boxes at **23%** instead of 3 at 30% (`display:flex` with
default `nowrap`, so they can never wrap). The box is emitted **only when a
name was recorded**, so a voucher without one prints the original three.
- **Proved**: rendered the no-PM DN against a reconstructed pre-change copy of
  the file — `<body>` md5 **IDENTICAL** (3a08c274dbd5895bc627693098fb6c7a). The
  only delta is two `<style>` rules that match zero elements on that page.
- The signature sits in a fixed **46px** slot and the PM box's `.line` loses its
  50px top margin, so 46+4+0 lands its rule at exactly the height of the three
  unsigned ones. Scoped via `.pm-box` so the other three boxes, and every no-PM
  voucher, are untouched. Without this the PM rule hung ~64px low.

On the **view page** it fills the empty right half of the footer row, which was
a lone `col-6` — no layout change needed.

## The offline path is untouched
`api/consumption-vouchers-sync.php` inserts an explicit column list that does
not include the new columns, so a CV created offline simply has NULL PM and
prints as before. It can be filled in later via Edit. Zero references to
`project_manager` in that file.

## Verification
- Create with name + signature, edit, re-save, clear, blank — all as above.
  Edit path still reverses and re-applies stock exactly (77,630 -> 77,623 ->
  77,630, single batch, no new batch rows).
- Printed headlessly to PDF: with-PM and no-PM are **both 2 pages**, so the 4th
  box adds no overflow. Screenshot confirms 4 boxes on one row with the rules
  aligned.
- All 7 inline `<script>` blocks pass `node --check`. 0 PHP diagnostics across
  list / form / edit form / view / DN (7 URL sweep).
- Migration idempotent (re-run 3x, no error).
- **Cleanup:** both test vouchers deleted via the app; test user, its
  permissions and all 11 test audit rows removed; the 5 genuine CV audit rows
  (June/Aug 2026) left intact; `consumption_vouchers` AUTO_INCREMENT back to 3.
  NOTE the two child tables were already empty and their original
  AUTO_INCREMENT was not recorded before testing — both reset to 1, which is
  cosmetic (CV numbering is `getNextNumber()`/MAX-based, not id-based).

## Gotchas worth keeping
- `<?php if ?>` blocks indented inside markup emit their surrounding
  whitespace, so a skipped block still shifted the closing `</div>` by 20
  spaces. Putting `<?php if ?>` / `<?php endif ?>` at **column 0** restored
  byte-identical output.
- `"            <?php endif; ?>
        </div>"` occurs **3 times** in
  `consumption_voucher_dn.php`. Anchor edits on surrounding content, not on
  that fragment.
- Attributes split across lines defeat single-line greps — a prefill that looks
  empty may just be on the next line. Use `grep -A1`.

---
---

# FEATURE: Edit an APPROVED requisition until a budget exists + change log [x]
**Status: COMPLETED** — verified via curl with 2 temporary logins (an editor
WITH `requisition_vouchers.edit`, a viewer WITHOUT it); both removed. RV000116,
RV000106, RV000002 and every audit row restored. **No schema change, no
migration**: `audit_log.old_values` / `new_values` have existed since the base
schema and had **zero** users app-wide, so the change log was free.

**Files (MODIFIED, 6):** `includes/functions.php`,
`modules/transactions/requisition_voucher.php`, `requisition_voucher_view.php`,
`requisition_voucher_list.php`, `lang/en.php`, `lang/sw.php`

## The gate
`requisitionCanEdit()` in functions.php is the ONLY place that decides. Draft is
unchanged (`edit` permission, nothing else). Approved additionally requires that
NOTHING has been raised against the requisition. Everything else —
`pending_approval`, `rejected`, `closed` — stays locked exactly as before.

A budget of **ANY** status blocks, draft included: `budget_details` rows hold
`requisition_detail_id` references to the very lines an edit could delete.
Purchases and imports carrying `requisition_id` block too — `purchases.php`
`?from_requisition` can raise one with no budget in between. **0 such rows exist
today**, so that arm is a hole-closer, not a live rule.

Unlocks **55 of 75** approved RVs on this tenant; 20 are budget-locked.
`requisitionProcurementBlockers()` is batched so the list resolves a whole page
in 3 queries, not 3 per row — and the list calls the SAME helper, so it can
never show a pencil the form refuses (the exact bug that was real in
`budget_list.php`). Verified: 56 pencils rendered, `diff` IDENTICAL against the
SQL ground truth (1 draft + 55 approved).

## THE EXISTING EDIT PATH WOULD HAVE WRECKED AN APPROVED RV
`requisition_voucher.php` edits a draft by `DELETE`-ing every detail row and
re-inserting. On an approved RV that would wipe `approved_quantity`, reset
`transferred_quantity` to 0 (**allowing double dispatch**), and — because
`stock_transfer_details.requisition_detail_id` is FK `ON DELETE SET NULL` —
silently sever the transfer links that reversal walks
(`transfers-sync.php:452-468`). **17 rows app-wide carry that link**; RV000002
alone holds 5.

So the approved path is diff-based (`rvfApplyApprovedLineEdit`): existing rows
UPDATEd by id, only new products INSERTed, only removed lines DELETEd. **The
draft path is byte-for-byte untouched** — do not merge the two. Verified after a
full test round: 17 links still there, 0 dangling.

## THE QUANTITY BOX ON AN APPROVED VOUCHER IS THE *APPROVED* QUANTITY
This is the subtle one. The form prefilled `requested_quantity`, but everything
downstream reads `COALESCE(approved_quantity, requested_quantity)`
(`budget.php:106`). Two live consequences on RV000116 alone:
- a line the approver **rejected** (`approved_quantity = 0`, requested 1) would
  have been **resurrected at 1** by a save that changed nothing else;
- any approver reduction (requested 24, approved 20 on RV000002) would have been
  silently raised back.

So in approved mode the box carries the EFFECTIVE quantity, the column header
reads **Approved Qty**, and the original request is shown beneath it as
read-only text. On save `approved_quantity` is written and
**`requested_quantity` is deliberately left alone** — overwriting it would
destroy the record of what was actually asked for. A posted `0` on an EXISTING
line keeps that line rejected; only a line absent from the POST is a removal.
(`approval_remarks` is never touched either — verified it survives an edit.)

## Rules, all re-enforced server-side inside the transaction
- qty >= `transferred_quantity` on that line;
- a line with any transfer can be neither deleted nor have its product swapped;
- destination frozen once anything is dispatched — the same rule
  `change_destination` already enforces;
- a posted `detail_id` must belong to this requisition, and only once;
- **blockers re-checked INSIDE the transaction**, so a budget raised between
  page-load and POST still stops the edit.

Status stays `approved`; `approved_by` / `approved_at` / signature are untouched
(user-confirmed). The sync API is **not touched at all** — `update`, `approve`,
`revise_approval`, `change_destination`, `make_transfer`, `close` all unchanged.

## The change log
One `audit_log` row per edit, action **`edit_after_approval`** — distinct from
the plain `update` a draft writes, so post-approval amendments are greppable and
a draft's ordinary edits never pollute the panel. `new_values` holds a
ready-to-render `changes` list built at write time **with human-readable labels
resolved then**, so the history never depends on a later lookup of a name that
has since changed. A no-op save writes NO row (matches `map_bom`).

Rendered as a Change History card on the view page, `no-print` — the printed
voucher and all three delivery notes are unchanged.

## setAlert() IS DEAD, SO THE FORM NOW RENDERS ERRORS INLINE
`getAlert()` has no callers anywhere in the app. Every validation error on this
form went to `setAlert()` and therefore **nowhere** — a rejected save looked like
a dead Save button. Same block `budget.php:426` already uses. Without it this
feature is unusable: every guard above would fail silently.

## Verification
- Guards, each rejected with NOTHING written and the message rendered inline:
  qty below transferred, delete a transferred line, swap its product, a
  `detail_id` from another voucher, the same `detail_id` twice, destination
  change after dispatch. Plus a budget-locked RV refused on the form (302), on
  the list (no pencil) and on the view (lock notice naming BUD000001).
- Real edit on RV000116: raised a line 1 -> 3, dropped a line, added a product,
  changed header remarks — one audit row, status still `approved`,
  `requested_quantity` preserved at 1, transferred line untouched.
- **Draft regression**: a draft re-save still wipes and re-inserts (fresh ids),
  still logs `update`, writes no change-log row, content identical.
- Permissions: viewer without `edit` gets 302 on the form and 0 pencils on a
  117-row list. NOTE **21 users hold `create` without `edit`** and none hold
  `edit` without `create`, so requiring both changes nobody's access.
- 0 rows written to stock_batches / transactions / stock_transfers / budgets.
  0 PHP diagnostics on any page; 13 inline `<script>` blocks pass `node --check`.
- **Restore discipline:** full `SELECT *` snapshots first; `updated_at` assigned
  EXPLICITLY on every restored row (both tables are ON UPDATE
  CURRENT_TIMESTAMP); the draft's re-inserted rows put back under their ORIGINAL
  ids and AUTO_INCREMENT reset to 3681. `diff` IDENTICAL on both tables.

## Gotcha worth keeping
`users` has **`name`**, not `full_name` — the change-log query was written with
`u.full_name` and, because the helper catches `Throwable`, the panel would have
rendered EMPTY forever instead of erroring. Several helpers alias it
(`SELECT u.name AS full_name`), which is what makes the mistake easy.

Also: the RV list defaults to a date range that excludes older vouchers — an
empty list there is the filter, not a bug.

---
---

# FEATURE: Budget may EXCEED the requisition quantity, with a written reason [x]
**Status: COMPLETED** — verified via curl with a temporary login (removed). All
30 budgets and 85 detail rows restored byte-for-byte. **No migration**:
`budget_details.remarks` exists from 0034 and was previously written as a
literal `NULL` with no input anywhere, so it was free.

**Files (MODIFIED, 5):** `modules/transactions/budget.php`, `budget_view.php`,
`api/budgets-sync.php`, `lang/en.php`, `lang/sw.php`

## "Over" means over what is LEFT, not over the requisition line
User-confirmed. The rule is unchanged from before; only the consequence is:
`remaining = RV approved qty - qty taken by OTHER live budgets on that RV`
(`getRequisitionBudgetedQty()` counts `pending_approval` + `approved` only).
So on a product another budget has already fully claimed, ANY quantity asks for
a reason. Summed **per product across every supplier row**, so 5 + 8 against an
approved 10 is over by 3 even though neither row alone exceeds it.

## THE CAP LIVED IN FOUR PLACES — all four had to move together
1. `budget.php` server POST, 2. `budget.php` client `anyProductOver()` (which
DISABLED Save), 3. `budget_view.php` `$approveCap` (approve modal), 4.
`budgets-sync.php` `budgetApprove()`. Relaxing only 1+2 would have let a creator
submit 12-against-10 that the approver then **could not approve** — #4 throws.
Same trap class as the `send_for_approval` gap in the feature above. The
approver may now also exceed, with a reason, so neither side can be blocked by
the other.

## The reason is stored on EVERY row of an over-allocated product
Deliberate. The rows of one product are routinely aggregated (see the `GROUP BY`
in `getBudgetSupplierRemaining()`), so hanging the reason off one arbitrary row
would lose it. Read back = first non-empty. `NULL` for any product within its
remaining quantity — and the approve path **clears** it when a product is
brought back within cap, so a stale justification cannot outlive the excess it
described. Rendered once per product on the view page (`$reasonShown` map), and
it PRINTS: the justification has to travel with the figures it explains.

`budgets-sync.php` falls back to the stored reason when the approver posts none,
so leaving a pre-existing excess untouched does not mean retyping the creator's
words. Verified.

## PRE-EXISTING BUG this forced out — client and server disagreed on the grain
The form rendered one block per REQUISITION LINE, each computing
`remaining = thisLine.approved - budgetedOther[product]` — subtracting the
product-wide figure once per line. The server has always aggregated per PRODUCT.
For a product on several lines the client was therefore STRICTER than the server
and blocked saves the server would accept. **8 requisitions carry a duplicated
product; RV 122 has "Safety boot" on FOUR lines (1+2+2+2 = 7).**

Fixed by moving the client to the product grain: `allocatedByProduct()` /
`remainingByProduct()` / `productsOver()` replace `anyProductOver()`, and
`data-remaining` is now the product total.

**That change needed `data-prefill` as well.** The on-load prefill used
`data-remaining`, so with the product total on all four blocks a duplicated
product would have prefilled 7 four times and opened the form already over by
21. `$linePrefill` hands the remaining out across the product's lines in order,
each taking at most its own approved qty — 1+2+2+2, exactly as before.
**Do not point the prefill back at `data-remaining`.**

## Nothing downstream re-derives from the requisition
Checked before building: `purchases-sync.php` stores `requisition_id` as a link
and never caps quantity against it; the PO path caps against the **budget**
(`po_budget_over_remaining`), not the RV. So the excess simply flows. Verified:
an over-allocated budget (10 vs approved 7) prefilled `purchases.php` with
quantity **10** at the quantity-weighted rate 140.

`budget_over_remaining` / `budget_over_block` were LEFT IN PLACE — new keys were
added alongside, since the PO module has its own separate message.

## No ceiling on the excess (user-confirmed)
A fat-finger 100-instead-of-10 will save with any reason text. A multiplier cap
and a large-excess `confirm()` were both offered and declined; the written
reason plus the approval step are the control.

## Verification
- Client logic lifted VERBATIM from the rendered page and run in node against a
  DOM stub: **13 assertions, 0 failures** — single line, two suppliers, the
  4-line RV122 product (all-on-one-block, spread 1+2+2+2, and 6+4 over),
  mixed products, remaining 0, no rows, blank/junk qty, float tolerance either
  side of 0.0005.
- RV122 render: 4 blocks for product 3027, all `data-remaining="7"`, prefills
  `1,2,2,2`, ONE reason box (not four), 21 boxes for 21 distinct products.
- Server: 7 on a single line of the duplicated product **saved** (the old client
  blocked this); 10 with no reason **rejected, nothing written**; 6+4 across two
  lines with a reason **saved**, reason on BOTH rows, the within-limit product
  left `NULL`.
- Approve: unexplained raise **rejected with the transaction rolled back**
  (status stayed `pending_approval`); one call that simultaneously brought 3027
  within cap (**reason cleared**) and raised 3516 over (**reason stored**);
  approving an over budget with no posted reason **reused the stored one**.
- 0 rows written to purchases / POs / imports / stock_batches / transactions;
  0 requisition detail rows touched. 0 PHP diagnostics on any page. Both
  inline `<script>` blocks pass `node --check`.
- Restore: `diff` IDENTICAL on both tables; AUTO_INCREMENT back to 130; no
  `budget_details` row left carrying a remark.

---
---

# FEATURE: Budget editable before approval + creator override [x]
**Status: COMPLETED** — verified via curl with 2 temporary logins (a creator
WITHOUT `budget.edit`, and an editor WITH it); both removed. All 30 budgets and
85 detail rows restored byte-for-byte. No schema change, no migration —
`budgets.created_by` and the `pending_approval` status both exist from 0034.

**Files (MODIFIED, 7):** `includes/functions.php`,
`modules/transactions/budget.php`, `budget_view.php`, `budget_list.php`,
`api/budgets-sync.php`, `lang/en.php`, `lang/sw.php`

## The gap
`pending_approval` was editable by NOBODY — including Admin. budget.php:41
whitelisted only `['draft','approved']`, and there was no recall/withdraw action
either, so once a budget was sent it was frozen until an approver acted, and
`rejected` is a dead end. 2 budgets were sitting in that state.

## THE CREATOR OVERRIDE IS THE APP'S FIRST OWNER-SELF-EDIT EXCEPTION
Nothing anywhere else gates write access on `created_by == getUserId()` (the only
prior hits are notification suppression in purchase-orders-sync.php:182). It
therefore lives in ONE place — `budgetCanEdit()` / `budgetCanSendForApproval()`
in functions.php — and the form, the view and the list all call it, so they can
never disagree about who sees a pencil. Do not re-inline the checks.

Deliberately limited to `draft` + `pending_approval`. An APPROVED budget stays
permission-only: those figures are an approver's signed decision, so the creator
changing them afterwards is a different act from fixing their own draft.

## Editing a pending budget WITHDRAWS it to draft
User-confirmed. The approver must never be able to sign off on figures that
changed under them after they were notified. The save nulls
`sent_for_approval_by` / `sent_for_approval_at`, logs a distinct
`withdraw_from_approval` audit action (greppable, alongside the ordinary
`update` — same reasoning as `map_bom`/`unmap_bom`), and best-effort notifies
the approvers via `notifyApprovers('budget','budget_withdrawn',...)` so nobody
chases a link that no longer has an Approve button. An APPROVED budget's status
is still left unchanged, exactly as before.

An info alert renders on the form BEFORE they start typing — not a toast once
the status has already moved.

## send_for_approval HAD to be widened too, or the feature is a trap
`budgets-sync.php` gated it on `budget.edit`, and budget_view.php:416 hid the
button behind the same check. A creator without `budget.edit` could therefore
edit their own pending budget (dropping it to draft) and then have **no way to
put it back** — strictly worse off than before. It now accepts the creator of
that budget. `approve` / `reject` / `delete` are UNTOUCHED and stay on their own
permissions. NOTE the permission check had to move BELOW the row fetch (it needs
`created_by`), so a cheap `budget.view` pre-check guards id probing.

## NOTHING CHANGES FOR ANY EXISTING USER ON THIS TENANT
All 10 users holding any budget permission hold BOTH `can_create` and
`can_edit`, and all 3 real creators (Regina 6, Benedict 16, Kelvin 8 budgets)
have `can_edit = 1`. The creator override is a no-op until someone is given
create-without-edit. The `pending_approval` unlock is what actually unblocks
people today.

## Bug fixed in passing (pre-existing, user-confirmed in scope)
`budget_list.php` decided editability from its own `purchase_count` sub-select,
which counts purchases + imports but **NOT open POs** — while
`budgetHasLinkedPurchases()` (the form's gate) also counts open POs. So the list
showed a pencil the form then refused with a 302. Real on this tenant:
**BUD000016 / 17 / 18** have 0 purchases and 1 open PO each. The list now calls
the shared helper, and those three correctly show no pencil.
Separately, `budget_view.php` never rendered Edit for an approved-with-no-
purchases budget even though the list did and the form allowed it — that button
now exists, and editing there does NOT change the status.

## Behaviour worth knowing
Reverting to draft RELEASES the quantity back to the requisition:
`getRequisitionBudgetedQty()` counts only `pending_approval` + `approved`. While
a budget sits in draft being edited, another budget can claim the same
requisition line. Identical to how a never-sent draft already behaves, but it is
a window that did not exist before.

`budgetCanEdit()` hits the DB only for `approved` budgets (draft/pending
short-circuit). Measured 2.8 ms for all 25 approved rows; `purchases.budget_id`
and `purchase_orders.budget_id` are both indexed.

## Verification
- Helper unit-tested against stubbed `hasPermission`/`getUserId`: **28
  assertions, 0 failures** — editor / creator / other-user / locked-approved /
  NULL + 0 + string `created_by` / empty + non-array / unknown status.
- Creator without `budget.edit`: own draft **200**, own pending **200** (was 302
  for everyone), other user's pending **302**, own APPROVED **302** (override
  deliberately stops there).
- Real edit POST on a pending budget: status -> draft, both
  `sent_for_approval_*` NULL, 2 audit rows (`update` +
  `withdraw_from_approval`), all **6** approvers notified.
- API guards: creator re-sends own budget **200**; someone else's draft, plus
  `approve` / `reject` / `delete`, all **"No permission"**.
- List scoping: creator sees a pencil on exactly 1 of 6 checked budgets (their
  own pending); their own approved shows none.
- 0 rows written to purchases / imports / POs / stock_batches / transactions /
  requisition_vouchers. 0 PHP diagnostics on any rendered page.
- **Restore discipline:** full `SELECT *` snapshots first; `updated_at`
  reassigned EXPLICITLY on all 3 touched budgets (`ON UPDATE
  CURRENT_TIMESTAMP`); the re-inserted detail row put back under its ORIGINAL id
  (the edit path deletes and re-inserts, so 106 had become 130) and
  AUTO_INCREMENT reset. `diff` IDENTICAL on both tables.

---
---

# FEATURE: Cost Centres importer (Code + Parent Code, unlimited depth) [x]
**Status: COMPLETED** — verified via curl with 2 temporary logins; all 790
existing rows restored byte-for-byte. No schema change, no migration.

**Files (NEW, 2):** `api/cost_centres-import.php`,
`modules/masters/cost_centres-import.php`
**Files (MODIFIED, 3):** `modules/masters/cost_centres.php` (one button),
`lang/en.php`, `lang/sw.php`
Named with an UNDERSCORE to match the existing `cost_centres-sync.php`.

## WHY THIS IS NOT A COPY OF THE LOCATIONS IMPORTER
`locations` is UNIQUE on **name**. `cost_centres` is UNIQUE on **code**, and its
names repeat massively — on this tenant "Equipment Container" x78,
"Battery Container" x76, "WING A" x18; only 568 distinct names across 790 rows.
So the CODE is the identity and **the parent is given BY CODE**. Matching a
parent by name would have been ambiguous hundreds of times over.

The codes look like paths (`SCHNEIDER/B01/Equipment Container`) but the tree is
**NOT** inferred from them: `CRDB BANK` is the parent of
`CRDB MAINT/GROUND FLOOR`, so the code path and the real parent chain disagree.
Only the explicit parent column decides the tree.

## TWO SCHEMA TRAPS — both are why the code looks the way it does
1. **`code` collates `utf8mb4_unicode_ci`, i.e. CASE-INSENSITIVE.** MySQL
   already treats `schneider/b01` as equal to `SCHNEIDER/B01`. All matching is
   therefore case-insensitive; treating a differently-cased code as "new" would
   sail past the importer's own duplicate check and then die on the UNIQUE key.
   Verified: `zzt/s1` matched existing `ZZT/S1` as an UPDATE, and the stored
   code kept its original casing because the UPDATE never writes `code`.
2. **`sql_mode` has NO `STRICT_TRANS_TABLES`** (it is
   `NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION`), so an over-long value
   is **silently truncated**, not rejected — and the longest existing code is
   already exactly 50, the `varchar(50)` ceiling. Lengths are validated in PHP
   (code <= 50, name <= 100) so a long code is reported instead of being chopped
   into a collision. Verified with a 51-char code.

## Sheet + rules (user-confirmed)
`code | name | parent code | description | start date | end date | status | budget | active`
Headers matched loosely (`parent_code`, `parent`, `budget_amount`, ...).
- **Update details, never re-parent.** `parent_id` is absent from the UPDATE
  statement. Reports aggregate a parent plus all descendants, so moving a node
  moves other people's totals. Verified: two rows given a different parent kept
  their original one.
- **Blank cell = leave the stored value alone**, so a sparse sheet cannot wipe a
  budget or deactivate anything. NULL (not 0 or empty string) is the "untouched"
  sentinel in `cci_activeValue` / `cci_status` / `cci_date` / `cci_number`;
  those helpers return FALSE for "present but invalid", a DIFFERENT case.
- Gate is `cost_centres.create` (20 users). **0 users hold `can_import`** on
  this module, so the usual `import` flag would have made it Admin-only.

## THE XLSX DATE GOTCHA
SimpleXLSX renders a real Excel date cell as **`2026-05-01 00:00:00`** — a
datetime string, NOT a serial number. The first cut rejected it as invalid,
which would have hit any normal user immediately since date cells are how people
actually type dates. `cci_date()` now strips a trailing time component. It still
also accepts bare serials (45000 gives 2023-03-15, epoch 1899-12-30), Y-m-d,
d-m-Y, d/m/Y, Y/m/d, d.m.Y, and rejects impossible dates like 31-02-2026 via a
format round-trip check.

## Verification
- Three rows all named "WING A" imported cleanly — proves identity is the code.
- Shuffled 4-level sheet at **limit=1** (a chunk boundary between every parent
  and child): 7 chunks, 7 creates, 0 errors.
- 14 error cases each reported with nothing written: case-insensitive duplicate,
  over-long code + name, unknown parent AND its descendant, both halves of a
  cycle, self-parent, bad status, 31-02-2026, end-before-start, non-numeric
  budget, blank code, blank name. The 2 valid rows in that sheet still imported.
- XLSX with a second sheet: sheet 2 ignored (`rows()` reads index 0).
- `cost_centre_approvers` never read or written — still 13 rows.
- Restore: `diff` of the full table vs a pre-test `SELECT *` snapshot is
  IDENTICAL across all 790 rows; AUTO_INCREMENT reset to 795.

---
---

# FEATURE: Locations importer (Name + Parent, unlimited depth) [x]
**Status: COMPLETED** — verified via curl with 2 temporary logins; all 77
existing rows restored byte-for-byte. No schema change, no migration.

**Files (NEW, 2):** `api/locations-import.php`,
`modules/masters/locations-import.php`
**Files (MODIFIED, 3):** `modules/masters/locations.php` (one button),
`lang/en.php`, `lang/sw.php`

Follows the house importer shape (`upload -> preview -> process (chunked) ->
cleanup`, plus `template`), wizard page modelled on
`modules/masters/categories-import.php`.

## THE CHUNK-ORDERING BUG — read this before touching either importer
`process` re-derives the plan on EVERY chunk and slices it by `offset`. The
first cut decided "is this row ready?" by asking whether its parent existed in
the DB. That makes the sort order **depend on DB state**: the moment chunk 1
created a root, its children were promoted to the front of chunk 2's plan, so
offset-slicing walked different entries. Observed symptom: 1 created, 1 bogus
"updated", then four `Parent not found` failures and a broken tree.

**The order must be a pure function of the sheet.** A row is ready when its
parent is NOT defined by this sheet (so it can only have come from the DB, which
the resolve pass already proved) or when the sheet row defining it has been
placed. Re-run at `limit=1` afterwards: 6 chunks, 6 creates, full 5-level chain.
`cost_centres-import.php` carries the same fix — do not "simplify" either one
back to checking the database.

## Design points
- `locations.name` is UNIQUE table-wide (`unique_location_name`), not per
  parent, which is why the tenant's own names are paths
  (`M3/Main House Store/Entrance`). Name is therefore the identity.
- **Unknown parent is REPORTED, never auto-created as a root.** That is what
  `api/categories-import.php` does, and it turns one typo into a junk top-level
  row. (Its lookup is by name+parent while `product_categories.name` is UNIQUE,
  so a child listed above its parent can also make it INSERT a duplicate name —
  not fixed, out of scope, just do not copy the pattern.)
- Descendants of any unusable row are walked and reported too, so the user sees
  every casualty at once instead of one re-run at a time.
- Update details, never re-parent; blank cell = leave as is. Because nothing
  existing is ever re-parented, a cycle can only be formed WITHIN the sheet.
- Gate is `locations.import`. NOTE only 1 of 23 users holds `can_import`, so it
  is effectively Admin-plus-one until the box is ticked in Masters -> Users.

## Verification
- Sheet listing the great-grandchild on row 2 and its root on row 5 built
  `L1 -> L2 -> L3 -> L4 -> L5` correctly; names with commas, quotes and
  non-ASCII stored as valid UTF-8 (U-umlaut = c39c).
- Unknown parent + 2 descendants, cycle, duplicate, self-parent, blank name all
  reported; the 2 good rows in the same sheet still imported.
- XLSX second sheet ignored; a new location attached under the real existing M2.
- `diff` vs the pre-test snapshot IDENTICAL across all 77 rows;
  AUTO_INCREMENT reset to 139.

---
---

# FEATURE: Map an EXISTING requisition voucher to a BOM from its view screen [x]
**Status: COMPLETED** — verified end-to-end via curl with 4 temporary test
logins and a temporary 2nd BOM (all removed; RV000120 and RV000010 restored
byte-for-byte). No schema change and NO migration: `requisition_vouchers.bom_id`
already existed from 0033.

## The gap
`bom_id` could only be written from `requisition_voucher.php` (redirects away
unless `status='draft'`) and the sync API's `update` action (throws
`Only draft requisition vouchers can be edited`). **Once an RV left draft its
BOM could never be set** — which is exactly when people notice the omission,
often after the material has already gone out. On this tenant that was 116 of
117 RVs; **0 were mapped to a BOM.**

`requisition_voucher_view.php` only ever READ `bom_id`.

**Files (MODIFIED, 4):** `api/requisition-vouchers-sync.php`,
`modules/transactions/requisition_voucher_view.php`, `lang/en.php`, `lang/sw.php`

## User-confirmed decisions
- **Any status** may be mapped — including `closed`. Retro-mapping a finished
  RV is the main use case, not an edge case.
- **`edit` OR `approve`** admits (18 users here vs 6 for approve alone). Nobody
  outside Admin holds `can_approve` on requisition_vouchers; approval runs
  through `cost_centre_approvers`, and only 4 cost centres have any configured
  — so gating on `approve` would have made this an Admin-only chore.
- **Unlink allowed** via a blank option, behind a JS `confirm()`.

## THIS IS NOT INERT — it moves other people's numbers
`getBomProductBalances()` (functions.php:1545) derives "transferred so far" by
joining EVERY requisition whose `bom_id` matches. So linking an RV that already
has transfers immediately CONSUMES that BOM's remaining balance for everyone
looking at it, and unlinking gives it straight back. Verified live: mapping
RV000010 (product 935, 8 transferred) moved BOM000001's balance 5,025 -> 5,017.
That is the intended retro-mapping behaviour, and it is why the action is
permission-gated and `logAudit()`ed as `map_bom` / `unmap_bom` (two distinct
actions, so an unlink is greppable).

## Why almost no rendering code was needed
The view already had `if ($bomLinked)` blocks for the header row, the BOM
Balance column, the row highlighting, the legend and the approve/revise modal
columns. Setting `bom_id` lights all of them up on reload. `getBomProductBalances()`
and `api/bom-balances.php` were **not touched at all**.

## The dropdown trap (a bug this avoids)
The BOM list is scoped to the user's accessible locations, same query as the
form. A BOM that is already linked but sits OUTSIDE those locations would be
absent from the list, so the `<select>` would fall back to the blank option and
the next save would **silently UNLINK it**. The currently-linked BOM is
therefore appended to the list unconditionally. Verified: with the BOM's
location revoked it still renders `<option selected>`. (The API still refuses
to LINK an inaccessible BOM — the fallback only preserves an existing link.)

## Other implementation notes
- `map_bom` is modelled on `change_destination`, but deliberately has **no
  status check** and **accepts an empty `bom_id`** (= unlink), so the client
  must NOT reject a blank selection the way `submitChangeDestination()` does.
- No-op early return when the value is unchanged, so a re-save neither bumps
  `updated_at` nor writes an audit row. Verified across 6 failed/no-op calls:
  RV000120's `updated_at` stayed 2026-08-11 18:26:52.
- Caller must pass `canAccessLocation()` on the RV's OWN destination as well as
  on the BOM's location. `rv_hasColumn()` guards pre-0033 tenants.
- When unmapped, the whole `BOM:` header row carries `no-print` — otherwise the
  printed voucher would gain an empty label row, since it holds only a button.

## Verification
- Permission matrix: edit-only 200 + modal, approve-only 200 + modal,
  **view-only 200 with no modal and no BOM row**.
- Guards all rejected with nothing written: view-only user, logged out,
  bom_id 999999, RV id 999999, missing RV id, BOM in an inaccessible location,
  RV in an inaccessible location.
- NOTE RV000120 shares **zero** products with the tenant's only BOM, so it
  cannot exercise the balance math — RV000010 was used for that.
- Rendered BOM Balance 5,017.000 with tooltip `BOM Qty: 5,025.000 ·
  Transferred So Far: 8.000`, row highlighted — matches SQL exactly.
- Full cycle: map on an `approved` RV -> no-op repeat -> change to a 2nd BOM
  (balance re-derived from the new one) -> unlink. Exactly 4 audit rows, one
  per real change.
- 0 rows written to `stock_batches` / `transactions` / `stock_transfers` /
  `requisition_voucher_details`. 0 PHP diagnostics; page valid UTF-8.
- **Restore discipline:** full `SELECT *` snapshots first; `updated_at`
  reassigned EXPLICITLY on both RVs (`ON UPDATE CURRENT_TIMESTAMP`). `diff`
  confirms both identical.

## ENVIRONMENT TRAP hit mid-session
`xampp82`'s mysqld took over port 3306 partway through, and `cei` lives in
`C:\xampp74\mysql\data\`. Symptom: `SHOW DATABASES` stops listing `cei` and the
whole app fails, with nothing wrong in the code. Check
`SELECT @@datadir` before believing a DB-shaped failure.

---
---

# FIX: BOM / BOQ import template column renamed Label -> Breakdown [x]
**Status: COMPLETED** — display-only rename. No schema change, no migration, no
parser change needed.

The downloaded starter sheet's 3rd column read "Label"; the user wanted
"Breakdown" (it is what populates `bom_detail_breakdowns.label`).

**Files (MODIFIED, 3):** `api/bom-boq-import.php`, `lang/en.php`, `lang/sw.php`

- [x] `$cols` header row, the sample row's hint text and the file's format
      docblock in `api/bom-boq-import.php`.
- [x] `bom_import_col_label` + `bom_import_col_help` in both lang files, so the
      "expected columns" table in `includes/bom_boq_import_control.php` agrees
      with the sheet. sw: 'Lebo' -> 'Mchanganuo'.
- [x] **Previously-downloaded sheets still import.** `bbi_col()`'s alias list
      (bom-boq-import.php:221) already accepted `breakdown` alongside `label`,
      so no parser change was required and both headers work.
- [x] The internal key stays `label` — `$cIdx['label']`, the
      `bom_detail_breakdowns.label` / `boq_detail_breakdowns.label` columns and
      the lang KEY NAME `bom_import_col_label` are untouched. Display only.
- [x] NOT renamed: the entry form's own breakdown column heading, which is
      `__('label')` (bom.php:349, boq.php:333) — a generic key shared with other
      screens, so it needs its own key rather than editing `'label'`.

## Watch the apostrophes in lang strings
`'...that BOM\'s balance'` written through a heredoc lost its backslash and
produced `syntax error, unexpected 's'`. The strings were reworded to avoid the
possessive entirely. Always `php -l` both lang files after editing them.

---
---

# FEATURE: Per-line VAT on budgets, flowing into Purchase / Import / PO [x]
**Status: COMPLETED** — verified end-to-end via curl against budget BUD000025
(restored byte-for-byte afterwards).

## What was added
`budget_details` gains `vat_percent` + `vat_amount` (migration 0044). The budget
form, view and approve modal get **VAT % and VAT columns AFTER Amount**, and the
VAT agreed at budget stage prefills the VAT box of whatever is raised from it.

**Files (NEW, 1):** `sql/migrations/0044_add_vat_to_budget_details.sql`
**Files (MODIFIED, 10):** `modules/transactions/budget.php`, `budget_view.php`,
`budget_list.php`, `purchases.php`, `purchase_order.php`,
`purchase_import_form.php`, `api/budgets-sync.php`, `includes/functions.php`,
`lang/en.php`, `lang/sw.php`

## Why VAT sits AFTER Amount (user-confirmed)
Amount stays goods-only (`qty x rate`). Putting VAT before it would invite
reading Amount as the all-in figure. After Amount also matches the
Total -> VAT -> Payable order the purchase and import forms already use.
Footer is **Goods / VAT / Total** on the view, the form badge and the approve
modal.

## Why TWO columns
`vat_percent` (what the user types, e.g. 18) + `vat_amount` (the money that
counts). Mirrors `purchase_import_costs` (0017), the only other per-line VAT in
the system.
- The percent lets the amount RE-DERIVE when the approver edits quantity or rate
  in the approve modal — otherwise a rate cut would silently leave VAT
  overstated.
- **The percent is authoritative when present**: both `budget.php` and
  `api/budgets-sync.php` recompute `vat_amount = qty * rate * pct / 100`
  server-side, so a stale or hand-crafted POST can never disagree with the
  stored percent. Verified: posting `vat_percent: 10` with `vat_amount: 999`
  stored 2,596,000, not 999.
- `vat_percent` is NULL when a flat amount was typed. Verified that stays NULL.

## THE STRUCTURAL CONSTRAINT: no destination has per-line VAT
`purchases`, `purchase_orders` and `purchase_imports` each carry ONE header
`vat_amount decimal(15,2)`; `purchase_details` / `purchase_order_details` /
`purchase_import_details` have no VAT column and never have. So budget line VATs
are **summed per supplier+type group into the destination's header VAT**.

## Pro-rating — `budgetLineVatForQty()` in functions.php
One helper, five call sites, so the formula cannot drift. A line of 10 units
carrying 180 VAT contributes 72 when 4 units are converted and 108 later.
Unit-tested (10 cases + a split that must not lose or leak: 100 + 116 of 216
gives 1,800 + 2,088 = exactly 3,888).

For **PO -> purchase** the PO holds one header VAT with no per-line breakdown, so
it pro-rates by the VALUE of the lines being taken (identical result when rates
are constant). Verified: a 2-line PO with 6,796,800 VAT, one line already
purchased, carried exactly 2,124,000 (31.25%).

## The remaining-quantity math was NOT touched
`getBudgetSupplierRemaining()`, `getBudgetPoOutstanding()` and
`getRequisitionBudgetedQty()` are entirely QUANTITY-based. VAT rides alongside
as passenger data exactly like `rate` does. There is no "budget consumed by
value" anywhere for this to corrupt.

## Integration gotchas (why each hook is where it is)
- `purchases.php` is a JS modal whose `showCreateModal()` hard-resets the VAT box
  to `'0'`, so `applyPrefilledVat()` MUST run after it, and must call
  `updatePayable()`. Shared by the budget and PO prefills.
- `purchase_order.php` / `purchase_import_form.php` render `$po['vat_amount']` /
  `$import['vat_amount']` server-side — setting those needs NO JS at all.
- Import: VAT goes to the header only. It is reclaimable and must never enter
  `costing_factor` / landed cost (see the note above `updateLandedCosts()`).
- Prefilled VAT is left as a flat amount, not switched onto the % toggle: it is
  summed from lines that may each carry a different rate, so no single
  percentage describes it. The user can still flip the toggle to re-derive.

## THE QUOTING TRAP (cost me a fatal)
`budget_list.php`'s query is a PHP **double-quoted** string. An SQL `--` comment
containing a double quote terminates the PHP string —
`syntax error, unexpected 'Budget'`. Never put `"` inside an SQL comment that
lives in a double-quoted PHP string.

## Nothing changes for existing budgets
`vat_amount` defaults to 0.00, so `SUM(quantity*rate + vat_amount)` equals the
old `SUM(quantity*rate)` for every pre-0044 row. Verified across all budgets:
diff = 0.00000 on every one. `budget_list.php`'s total is now VAT-inclusive so
it agrees with the view's Budget Total.

## Verification
- View footer read Goods 37,794,600 / VAT 6,800,706 / Total 44,595,306 — matches
  SQL exactly. 4 lines rendered their VAT % and VAT.
- Prefills: purchase 6,796,800 (= 4,672,800 + 2,124,000), PO 6,796,800,
  import 18.00 — each matching its supplier group.
- Budget -> PO saved `vat_amount = 6,796,800`; PO -> purchase carried the full
  6,796,800, and 2,124,000 after a partial.
- Approve API rejects VAT % of 150 and -5 with the transaction rolled back
  (status stayed pending_approval, the row's VAT unchanged).
- Regression with VAT cleared: PO box back to `0`, import box `0.00`, all six
  pages render with **zero** real PHP diagnostics (`<b>Warning</b>` etc — the
  naive grep hits are JS `typeof x !== 'undefined'`).
- Restore discipline: full `SELECT *` snapshots of the budget header AND its 24
  detail rows taken first; `updated_at` restored explicitly on both (each is
  ON UPDATE CURRENT_TIMESTAMP). `diff` confirms both byte-for-byte identical.
  Test PO deleted, numbering back to PO000017, no orphan detail rows.

---
---

# FIX: Budget POs credited the wrong person as approver [x]
**Status: COMPLETED** — code fixed, 8 existing rows corrected on the dev DB,
verified end-to-end by raising a real budget PO as a non-approver (removed
afterwards). No schema change.

## What was reported
PO000014 was approved the instant it was created, by Benedict — who has
`can_approve = 0` on purchase_orders.

## What it actually was — NOT a permission bypass
The approve action IS properly guarded
(`hasPermission('purchase_orders','approve')`, api/purchase-orders-sync.php:101).
Benedict never went near it. A PO raised from a budget takes a completely
separate path — `modules/transactions/purchase_order.php`, the `?from_budget=`
branch — which INSERTs with `status='approved'` in the same statement that
creates it. Hence `created_at == approved_at` to the second.

Being born approved is BY DESIGN and was a user-confirmed decision ("budget
approval covers it"). **The defect was the ATTRIBUTION**: it stamped the
CREATOR into `approved_by` and their signature into `approved_by_signature`, so
the document asserted a non-approver had approved it. Budget BUD000019 was
really approved by Akbar Meghji, not Benedict.

Scale found: 8 POs, all budget-raised, all 0 seconds create->approve, creators
Kelvin (x5) and Benedict (x3), both `can_approve = 0`.

**Files:** `modules/transactions/purchase_order.php`, `purchase_order_view.php`,
`sql/migrations/0043_fix_budget_po_approver_attribution.sql` (NEW),
`sql/fixes/fix_budget_po_approver_LIVE.sql` (NEW), `lang/en.php`, `lang/sw.php`

- [x] The budget branch now credits `budgets.approved_by` and that person's
      signature. Creator keeps `prepared_by_signature`, which is true. Falls
      back to NULL rather than the creator if a budget somehow has no approver
      — better to name nobody than the wrong person.
- [x] View + PRINTED sign-off show "Approved via budget BUDxxxxxx", so the
      approver's name can never be read as a personal sign-off on that PO. The
      printed copy goes to the supplier, so it mattered there most.
- [x] Migration 0043 backfills. Idempotent (after it runs `approved_by` no
      longer equals `created_by`, so a re-run matches nothing — verified).
      Skips rows where creator genuinely IS the budget approver, and skips
      budgets with no approver so NULL can never overwrite a real name.
- [x] `sql/fixes/fix_budget_po_approver_LIVE.sql` — standalone script for the
      production server, wrapping the same UPDATE in inspect-before /
      verify-after queries, plus a final sweep for ANY PO approved by someone
      without `can_approve` (Admins excluded — hasPermission grants them
      everything regardless of the permission row).

## THE `updated_at` TRAP (this is why the UPDATE looks odd)
`purchase_orders.updated_at` is `ON UPDATE CURRENT_TIMESTAMP`. The backfill
assigns `po.updated_at = po.updated_at` EXPLICITLY, which suppresses the
automatic bump — otherwise a pure attribution correction would make all 8 POs
look edited today. Verified: timestamps unchanged (e.g. PO000007 stayed
2026-07-28 17:52:13), and a full `SELECT *` diff before/after shows
**`approved_by` as the only field that changed** across all 8 rows.

## Latent risk that this closed just in time
The old code also wrote `approved_by_signature = getUserSignature(creator)`.
Both signature fields on PO000014 were empty ONLY because neither Kelvin nor
Benedict had a signature on file. The new self-service "My Signature" page lets
any user upload one — so the next budget PO would have stamped their real
e-signature onto an approval they never gave.

## Verification
- Raised a live budget PO (from BUD000025) as a test user with
  `can_approve = 0`: `approved_by` = Akbar Meghji (the budget's approver),
  `approved_by_signature` = HIS signature, `prepared_by_signature` = the
  creator's. Before the fix both would have been the creator.
- View page and print sign-off both render "Approved via budget BUD000025".
- LIVE script re-run against the already-fixed DB: step 1 returns no rows,
  `remaining_to_fix = 0`, all 8 rows `check_result = OK`, and the
  non-approver sweep returns nothing.
- Test PO + details + user + permissions deleted; `getNextNumber()` is
  MAX-based so PO numbering returned cleanly to PO000017 with no gap; the
  temporary signature on user 2 restored to NULL (0 users have one).

---
---

# FEATURE: Product filter on the Requisition Voucher list [x]
**Status: COMPLETED** — verified via curl against real data (109 vouchers,
921 detail rows, 3,926 active products). Display/filter only: no schema change,
no migration, no writes.

**Files (MODIFIED, 4):** `modules/transactions/requisition_voucher_list.php`,
`api/products-search.php`, `lang/en.php`, `lang/sw.php`

- [x] Multi-select (`products[]`), AJAX-backed by the existing
      `api/products-search.php` (30/page, searches name + barcode). NOT a
      server-side `<option>` dump like `stock_report.php` — that would push
      ~3,900 options into a list page on every load. Pattern copied from
      `modules/reports/stock_taking.php:787`.
- [x] **EXISTS, not JOIN** — joining `requisition_voucher_details` returns one
      row per matching LINE, so a voucher holding two selected products (or the
      same product twice) would be listed twice. The DB really has such rows:
      requisition_id 2 carries product 3122 on TWO lines. Verified that voucher
      appears exactly once. `idx_rvd_product` already exists.
- [x] Preselected products are re-rendered server-side as `<option selected>`
      (names hydrated in one query), so chips show instantly on reload rather
      than flashing raw ids.
- [x] Select2 init sits in `DOMContentLoaded` guarded by
      `window.jQuery && $.fn.select2` — jQuery/Select2 load from CDN in
      `includes/footer.php`, i.e. AFTER this page's inline script. Without the
      guard an unreachable CDN would break the whole filter form; with it the
      field degrades to a plain multi-select.
- [x] Empty multi-select is `disabled` on submit so `products[]=` never
      pollutes the URL.
- [x] Filter row was already exactly 12 columns (6 x col-md-2) — re-flowed to a
      second row rather than squeezed.

## PERMISSION BUG this would have hit
`api/products-search.php` admitted `stock_taking.view` /
`requisition_vouchers.CREATE` / `boq.create` / `bom.create` — but NOT
`requisition_vouchers.view`. A view-only user could open the RV list and the
product dropdown would 403 and silently return nothing. Added
`requisition_vouchers.view` to the guard (the endpoint only returns product
names, so read access is the right bar). Verified: view-only user gets 200;
strip that permission and it is still 403, so the guard still bites.

## Verification (SQL ground truth vs rendered rows)
| filter | expected | rendered |
|---|---|---|
| none | 109 | 109 rows, 109 distinct |
| product 271 | 6 | 6 |
| product 3346 | 6 | 6 |
| 271 + 3346 (union) | 12 | 12 |
| 3122 (dup lines on RV 2) | 2 | 2 — no duplicate row |
| nonexistent 999999 | 0 | empty state, HTTP 200, no PHP notices |
| junk `products[]=abc` | ignored | 109 (intval + array_filter drops it) |

NOTE when counting rows in a scraped page: each RV row links to
`requisition_voucher_view.php?id=` TWICE (the voucher-number link and the view
button), so a naive regex count doubles every figure.

## Left alone deliberately
The Items badge still shows the voucher's TOTAL line count, not the matched
count. There is still no pagination (109 vouchers, no count query on the page).

---
---

# FEATURE: Idle logout (configurable session timeout) [x]
**Status: COMPLETED** — verified via curl by editing the PHP session file
directly (no waiting for real minutes). No schema change, no migration:
`settings` is key/value.

## There was NO timeout before this
No idle timeout, no auto-logout, no last-activity tracking anywhere.
`isLoggedIn()` was a bare `isset($_SESSION['user_id'])`. Effective lifetime was
XAMPP's `session.gc_maxlifetime=1440` with `gc_probability=1/1000`, i.e. GC on
0.1% of requests, expiring by file mtime — non-deterministic and far longer
than 24 minutes in practice.

**Setting:** `session_timeout_minutes`, **default 15**, 0 = never. Edited on
Masters -> Company Settings. Applies to everyone including Admins.

**Files (MODIFIED, 8):** `includes/functions.php`, `includes/header.php`,
`ajax/notifications.php`, `modules/admin/company_settings.php`,
`modules/auth/login.php`, `modules/auth/logout.php`, `lang/en.php`, `lang/sw.php`

## THE thing to understand: the 60s poll had made sessions immortal
`includes/header.php` polls `ajax/notifications.php` every 60 seconds whether or
not anyone is at the keyboard. That calls `session_start()`, which touches the
session file's mtime — which is exactly what PHP's GC expires on. **An open tab
could never be logged out**, and a naive `last_activity` written on every
request would have been refreshed by that same poll forever.

So the poll is now activity-aware:
- `ajax/notifications.php` defines `SESSION_ACTIVITY_PASSIVE` **before**
  requiring functions.php (functions.php enforces the timeout on include, so the
  constant has to exist first). Passive by default.
- The poller appends `&active=1` only when the browser saw real interaction
  (mousedown/keydown/touchstart/scroll/wheel) since the previous tick.
- A passive request still CHECKS expiry; it just doesn't postpone it.

## No API or page gate was touched
`enforceSessionTimeout()` clears `user_id`/`user_data` and sets a one-shot
`session_expired` flag. It deliberately does NOT `session_destroy()` — clearing
`user_id` is enough for `isLoggedIn()` to go false, so `header.php` already
redirects pages and the ~50 `api/` endpoints already return their 401. Keeping
the session alive carries the flag to login.php and preserves the language.

- [x] `getSessionTimeoutMinutes()` catches **Throwable**, not Exception — a null
      `$conn` raises an Error, and this runs on every single request.
- [x] Poll interval is now `min(60s, timeout/3)` (floor 5s). A 1-minute timeout
      with a fixed 60-second keep-alive is a coin flip — the ping can land after
      the server already expired the session. 15 min -> still 60s, unchanged.
- [x] Client-side: warning modal at `min(60s, timeout/3)` before expiry with
      "Stay signed in" (which fires a REAL request so the server clock resets
      too, not just the browser's), then auto-redirect to
      `logout.php?expired=1`. Without this the logout is invisible until the
      next click.
- [x] Modal markup is wrapped in `if (getSessionTimeoutMinutes() > 0)` so a
      disabled tenant renders none of it.
- [x] The modal warns when `offlineDB.getQueue()` is non-empty, so nobody walks
      away with unsynced writes. (They are NOT lost — `removeFromQueue()` only
      runs on success — but the user was never told.)
- [x] **Global 401 -> login**: `window.fetch` is wrapped once in header.php.
      Page loads were already covered by the header gate, but every api/
      endpoint answered an expired session with a bare 401 that **nothing in the
      app recognised** — a user returning after the timeout got opaque failures.
      Guarded by a `redirecting` flag and an own-origin check so a third-party
      401 can't boot anyone.

## logout.php was broken; fixed because auto-logout routes through it
- It called `session_start()` a SECOND time (functions.php already started it),
  emitting "A session had already been started". With `display_errors=1`
  (config/database.php) that output precedes the `Location:` header and can kill
  the redirect outright.
- It never cleared the `PHPSESSID` cookie, so the browser kept presenting the
  dead id. Now expires the cookie via `session_get_cookie_params()` and
  `session_regenerate_id(true)` on the fresh notice-carrying session.

## Verification (timeout set to 1 minute, session file aged directly)
- Passive poll: `last_activity` UNCHANGED. With `&active=1`: advanced.
- Ordinary page request: advanced.
- Idle past timeout: `user_id` gone, page 302 -> login.php, login shows
  "You were signed out because you were inactive."
- Expired session on `api/purchases.php`: HTTP 401 (drives the JS redirect).
- Active user (3 x 40s idle + active ping at a 1-min timeout): still signed in.
- `session_timeout_minutes = 0`: 11 days of simulated idle, still signed in,
  and no modal markup rendered — byte-identical behaviour to before the feature.
- Settings form saves 25, rejects 99999 with the DB value left unchanged.

---
---

# FIX: Five more lists showed the wrong currency (same class as payments) [x]
**Status: COMPLETED** — verified via curl + node against temporary USD data
(restored byte-for-byte afterwards). Display-only: no schema change, no
migration, no writes.

## Scope
The payments/receipts fix left the same defect on five more lists. All five
carry `currency_id` + `exchange_rate_to_base` on the base table and ignored it.

| page | was | now |
|---|---|---|
| `purchase_order_list.php` | `formatNumber()` — **no symbol at all** | per-PO currency + FX tooltip |
| `consumption_voucher_list.php` | default symbol | per-voucher currency |
| `stock_adjustment_list.php` | default symbol | per-adjustment currency |
| `sales_list.php` | default symbol + cross-currency total | per-sale currency + grouped total |
| `purchases.php` (list) | default symbol + cross-currency total | per-purchase currency + grouped total |

Already correct, left alone: `purchase_imports.php`, `journal_vouchers.php`.
No money column at all: RV / BOQ / BOM / budget / transfers / stock-taking lists.

**Files (MODIFIED, 7):** `api/sales.php`, `api/purchases.php`,
`modules/transactions/sales_list.php`, `purchases.php`,
`purchase_order_list.php`, `consumption_voucher_list.php`,
`stock_adjustment_list.php`. No new lang key — `converted_to_base` already
existed from the payments fix.

## Two different fixes, because two different architectures
- **Server-rendered** (PO / CV / adjustment lists): `getCurrency($row['currency_id'])`
  passed as formatCurrency()'s 3rd arg. **The working SQL was NOT touched** —
  no `LEFT JOIN currencies` added, same approach as the payments fix.
- **API-fed** (`sales_list.php`, `purchases.php`): the browser never received a
  symbol, so `LEFT JOIN currencies` + `code / symbol / decimal_places` WAS added
  to `api/sales.php` and `api/purchases.php`. Strictly ADDITIVE — no existing
  key renamed or removed, so the offline cache keeps working.

## The offline/PWA trap on the two API-fed lists
Rows come from IndexedDB via `assets/js/sync-manager.js`, not straight from PHP,
so records cached BEFORE the join was added carry no `currency_symbol`.
`rowCurrencySymbol()` therefore falls back to `window.DEFAULT_CURRENCY_SYMBOL`
— i.e. exactly the old behaviour — instead of rendering blank. Those rows
correct themselves on the next sync. Verified with a synthetic stale row.

## Dead code removed (this is why the pages were stuck on the default symbol)
BOTH `sales_list.php:495` and `purchases.php:1988` defined a LOCAL
`formatCurrency()`. Both were **already dead**: `includes/footer.php:59` loads
`assets/js/main.js` AFTER the inline blocks, and main.js declares a global
`formatCurrency(amount, decimals, symbol)` that overwrote them. So what actually
ran was main.js's version with no symbol argument — always the tenant default.
- `purchases.php`'s local copy was `formatCurrency(amount, symbol)` — position 2
  is **symbol** in the local and **decimals** in main.js. Every call site was
  single-argument so nothing broke, but a 2-arg call would have passed a symbol
  where a decimal count was expected. Both locals deleted; call sites now pass a
  symbol explicitly to the one surviving implementation.
- Fallout worth knowing: `purchases.php`'s ENTRY FORM totals (modalTotalAmount /
  updatePayable / the item rate+amount cells) were reaching for
  `selectedCurrencySymbol` through that dead local, so they had been showing the
  tenant default while you typed a foreign-currency purchase. New
  `formatEntryCurrency()` restores the intent. NOTE the distinction on that page:
  `formatEntryCurrency()` = the form's dropdown, `formatRowCurrency()` = a saved
  row's own currency.

## Totals — same pattern as payments/receipts
Group by currency, convert each with its own `exchange_rate_to_base`, show the
base total plus a per-currency breakdown ONLY when a foreign currency is
present. On an all-base-currency set every rate is 1, so the figure is
**bit-identical** to the old `reduce()` and the note never appears — proved in
node (old 1,500,000.00 == new 1,500,000.00, hasForeign false).

Row-level FX detail goes in a `title` tooltip, matching payments/receipts. None
of these five has a CSV export today, so visible sub-text would have been safe —
tooltips keep them uniform with payments and stay safe if an export is added.

## Verification
- Temporarily flipped ONE purchase + ONE PO to USD @ 2450.60.
  PO list rendered `$ 13,000.06` with tooltip `USD @ 2,450.60 = TZS 31,857,947.04`;
  neighbouring TZS rows unchanged. `api/purchases.php` shipped
  `currency_code=USD symbol=$ decimal_places=2` on the USD row and the new keys
  on every row.
- Client-side helpers lifted verbatim out of the RENDERED page and run in node:
  per-row symbols, FX tooltip, grouped totals, all-TZS regression, stale-cache
  fallback. Arithmetic checked by hand (1,215,000.00 x 2450.6 = 2,977,479,000.00).
- **Restore discipline:** full `SELECT *` snapshots were taken BEFORE mutating,
  and the restore set `updated_at` explicitly (it is `ON UPDATE
  CURRENT_TIMESTAMP`). `diff` against the snapshots confirms both rows are
  byte-for-byte identical. Do it this way — an earlier session restored only
  `status` on PO000003 and silently lost its original `updated_at`.

---
---

# FEATURE: Upload a signature instead of drawing it [x]
**Status: COMPLETED** — verified via curl with temporary Admin + non-Admin test
logins (both removed). No schema change and NO migration: `users.signature`
(0041) and every `*_signature` column already existed.

## The problem
`users.signature` could only be set by someone holding the `users` module edit
permission, i.e. an admin, via `modules/masters/users_form.php` — there is no
owner-self-edit exception in that gate. `modules/auth/` held only login, logout
and change_password, so **a normal user had no way whatsoever to set their own
signature** and had to draw it freehand on every document. Painful without a
touchscreen. (A user who HAS one never draws: the pad isn't rendered at all and
the server applies the stored image.)

**Files (NEW, 3):** `modules/auth/my_signature.php`, `api/my-signature-sync.php`,
`includes/signature_upload_control.php`
**Files (MODIFIED, 15):** `assets/js/signature_pad.js`, `includes/functions.php`,
`includes/header.php`, `modules/masters/users_form.php`, `lang/en.php`,
`lang/sw.php`, + the 9 signing screens listed below.

## Why this needed no API or DB work at all
The pad already exposed `fromDataURL()`, and every signing screen already reads
its signature with `pad.toDataURL()`. An uploaded image is therefore drawn INTO
the existing canvas — after which every submit path, every per-file signature
sanitiser and every `*_signature` column behaves exactly as it did for a drawn
signature. Nothing downstream can tell the difference.

- [x] **`modules/auth/my_signature.php`** — self-service, any logged-in user,
      no `users` permission. Upload OR draw, preview, remove. Every write is
      `WHERE id = getUserId()`; there is no id parameter, so it can never reach
      another user's row (same shape as change_password.php). Linked in the
      user dropdown.
- [x] **`includes/signature_upload_control.php`** — one shared partial giving a
      pad an "Upload image" button + "Save to my profile" checkbox. Included on
      one line at each of the 10 pads. Placed INSIDE the existing
      `if ($myMasterSig): <img> else: <canvas> endif` else-branch, so it only
      ever appears for signers with no signature on file and users who already
      have one see nothing new (verified: canvas=0, upload=0 on all 9 screens).
- [x] `signature_pad.js` additive only: **`loadImageFit()`** (centred,
      aspect-preserving, never enlarged past 1:1) and a canvas->instance
      registry `SignaturePad.forCanvas()`. **`fromDataURL()` was NOT changed** —
      it stretches to fill, which is correct for its 3 existing callers
      (transfers / requisition_voucher / stock_taking reloading a signature
      captured on a canvas of that shape); a photo of a signature has an
      arbitrary aspect ratio and would be distorted by it.
      The registry exists because the requisition + stock-taking modals
      RE-CREATE their pad on every open — a cached reference goes stale, so the
      shared helper looks the pad up lazily on click.
- [x] `usersig_processUpload()` MOVED from users_form.php into functions.php as
      **`processSignatureUpload()`** (+ new `saveUserSignature()`), so the admin
      form and the self-service page share one validator. Behaviour unchanged.
- [x] `api/my-signature-sync.php` backs the checkbox (signing modals post JSON
      and cannot send multipart). Target is ALWAYS `getUserId()`. **It checks a
      CSRF token, unlike the app's other JSON sync endpoints** — this writes to
      an identity record, and a planted signature would be stamped on documents
      in that person's name. `checkCSRF()` itself is unusable here: it reads
      `$_POST` and `die()`s with plain text, breaking the JSON contract.

## The 10 pads (9 files) — all verified rendering the control
`requisition_voucher_view` (approve modal), `purchase_order_view` (approve
modal), `budget_view` (approve modal), `stock_taking_view` (sign modal),
`transfers` (**2 pads**), `transfers_receive`, `transfer_reverse`,
`requisition_voucher`, `stock_taking`.

NOTE: on `transfers.php` both pads belong to the SAME user, so
`$sigUploadOfferSave = false` on the dispatched pad — the checkbox is offered
once. Verified: 2 canvases, 2 upload buttons, 1 checkbox.

## Verification
- Non-admin set their own signature (6 KB) — previously impossible — while
  still getting a 302 from `users_form.php`.
- Admin upload through `users_form.php` still works after the function move.
- API guards: no token 403, bad token 403, non-image payload 400, valid 200,
  logged out 401.
- Every `initSignatureUpload()` call site resolves to ids that exist in the
  rendered DOM (5 args x 6 call sites); all rewritten inline `<script>` blocks
  pass `node --check`.
- `signature_pad.js` unit-tested against a DOM stub, 11 assertions: registry
  lookup by element and by id, `isEmpty()` false after upload (**this is what
  `stock_taking_view`'s submit gate tests**), aspect ratio preserved
  (900x300 -> 400x133 in a 400x150 pad), centred, fits, `clear()` resets.

## Gotchas hit while building (recorded so they aren't repeated)
- `$pageTitle = __('my_signature')` placed ABOVE the `require` of functions.php
  is a fatal — `__()` is not defined yet. change_password.php gets away with a
  literal there. Set `$pageTitle` AFTER the requires.
- `modules/reports/stock_taking.php` renders its form (and pad) only when
  `!empty($products)` — which needs BOTH `?location=` (not `location_id`) AND a
  category/product filter. `transfer_reverse.php` takes `?from=`, not `?id=`.
- `includes/header.php` links `modules/auth/profile.php`, which **does not
  exist** — a pre-existing dead link, left alone.

---
---

# FIX: Payments / Receipts lists showed every amount as the default currency [x]
**Status: COMPLETED** — verified via curl with a temporary Admin test login
(removed afterwards). Display-only change: no schema change, no migration, no
writes.

## The bug
`modules/payments/payments.php` printed `formatCurrency($payment['amount'])`
with ONE argument. `formatCurrency($amount, $decimals = 2, $currency = null)`
in `includes/functions.php:233` falls back to `getDefaultCurrency()` when the
3rd argument is null — so a USD payment rendered as "TZS 138,502.00". The data
was never wrong: `payments.currency_id` + `exchange_rate_to_base` (migration
0010) are written correctly by `payments_form.php`, which also shows the right
symbol on the voucher. **Only the LIST lost it.**

`modules/payments/receipts.php` had the identical defect and is fixed the same
way (8 payments + 2 receipts on this tenant are USD; everything else is TZS).

**Files (MODIFIED, 4):** `modules/payments/payments.php`,
`modules/payments/receipts.php`, `lang/en.php`, `lang/sw.php`

- [x] Row amount now `formatCurrency($amount, 2, getCurrency($row['currency_id']))`.
      `getCurrency()` already existed in functions.php:275 (statically cached,
      NULL id -> default currency) and had **zero callers app-wide** — it is
      exactly the helper this needed, so the working list QUERY was not touched
      at all. No `LEFT JOIN currencies` was added.
- [x] Second defect in the same place: the card + footer total was a raw
      `SUM(amount)` that ADDED USD to TZS and labelled the result TZS. Now
      `GROUP BY currency_id` with
      `SUM(amount * COALESCE(NULLIF(exchange_rate_to_base,0),1))`.
      **On an all-base-currency set every rate is exactly 1, so the number is
      bit-identical to the old formula** — proved in SQL for a TZS-only filter
      (diff = 0.0000000000). Only sets containing a non-base row change, which
      is the whole point. The `NULLIF(...,0)` guards a legacy 0 rate.
- [x] The summary card lists the per-currency subtotals in their OWN currency
      under a "Converted to base currency" note, but ONLY when the filtered set
      actually contains a foreign currency — a single-currency tenant sees the
      card exactly as before.

## CSV EXPORT — the trap in this page (do not undo this)
`exportTableToCSV()` in `assets/js/main.js:191` builds each field from
`cols[j].innerText` and joins on "," **with no quoting**, and it walks `tfoot`
rows too. A first pass put the FX rate + base equivalent in the amount cell as
a visible `<small>` sub-line; that injects commas AND a newline into the Amount
field and corrupts the export. Both the per-row FX detail and the footer's
"converted" note therefore live in a **`title` tooltip** — `innerText` ignores
attributes, so the CSV is unchanged. Verified by replaying the exporter's exact
logic over the rendered table: no stray newline in any field.

NOTE (pre-existing, NOT introduced and NOT fixed): that exporter is already
comma-broken regardless — `formatNumber()` emits thousands separators and
account names contain commas (e.g. "Zhuhai Dalong Electrical Equipment Co.,
Ltd"), so header/body field counts already disagreed. Fixing it means quoting
fields in the shared `main.js` helper, which affects every list page.

## Verification
- `payments.php?search=ZHUHAI` — PAY000201 now **$ 138,502.00** (was
  "TZS 138,502.00"), PAY000202 **$ 59,358.00**; tooltips read
  "USD @ 2,450.60 = TZS 339,413,001.20". Total **TZS 484,875,716.00** =
  (138,502 + 59,358) x 2,450.60, arithmetic checked by hand.
- TZS-only filter (`payment_mode=cheque`, 34 rows): total unchanged at
  TZS 76,661,680.00, zero tooltips, no "converted" note — no visible change.
- Receipts list: mixed set renders TZS rows as TZS and USD rows as $, with the
  per-currency breakdown on the card.

## SAME BUG CLASS ELSEWHERE (found, NOT fixed — separate task)
- `modules/transactions/sales_list.php:495` hardcodes
  `window.DEFAULT_CURRENCY_SYMBOL` for every row.
- `modules/transactions/purchase_order_list.php` shows no currency at all
  (zero matches for `currency` in the file) even though POs are multi-currency.

---
---

# FEATURE: Company Print Banner (letterhead) [x]
**Status: COMPLETED** — verified end-to-end via curl with temporary Admin +
non-Admin test logins (both removed afterwards). The tenant's real banner is
uploaded and left in place.

## What was added
A **Company Settings** page (Masters -> Company Settings, Admin only) where the
letterhead banner is uploaded, plus `company_name` / `company_address` /
`company_phone` — which the voucher printables had always READ but which had no
UI anywhere and were not even seeded, so they could only be changed with SQL.

The banner is printed on documents that go OUT to suppliers/customers. Where a
page already had a text letterhead the banner **replaces** those lines (the
artwork carries the address and phone itself); no banner = the old text
letterhead, byte-for-byte.

**Files (NEW, 3):** `sql/migrations/0042_widen_settings_value_for_banner.sql`,
`modules/admin/company_settings.php`, `includes/print_letterhead.php`
**Files (MODIFIED, 12):** `includes/functions.php`, `includes/header.php`,
`assets/css/style.css`, `lang/en.php`, `lang/sw.php`,
`modules/transactions/purchase_order_view.php`, `transfers_view.php`,
`requisition_voucher_dn.php`, `consumption_voucher_dn.php`, `sales_view.php`,
`sales_management.php`, `modules/masters/customers.php`, `suppliers.php`,
`modules/reports/ledger_report.php`

## Why a base64 data URL and not a file in `uploads/`
Same reason migration 0041 gives for `users.signature`: browsers routinely skip
externally-referenced images when printing, and this app runs offline as a PWA
— `src="uploads/banner.png"` would print BLANK in exactly the case the feature
exists for. (`UPLOAD_PATH` has no directory on disk anyway.)

- [x] Migration 0042 widens `settings.setting_value` TEXT -> **LONGTEXT** (TEXT
      caps at 65,535 bytes). Widening only — no row can be truncated. Also
      `INSERT IGNORE`s the four `company_*` keys, so a tenant's existing values
      are never overwritten (verified: the live values survived the migration).
- [x] **`company_banner` is deliberately NOT readable via `getSetting()`** —
      that helper slurps the WHOLE settings table into a static on first call,
      so the banner would load on every page request app-wide. Its query now
      excludes the key and `getCompanyBanner()` fetches it on its own, so only
      the 9 pages that print a letterhead pay for it.
- [x] Upload processing mirrors `usersig_processUpload()` (trust the bytes via
      `getimagesize()`, not the extension; GD downscale; 5 MB in / 2 MB out).
      Output format is decided by **`companyBanner_hasTransparency()` scanning
      the actual pixels**, NOT by "the file is a PNG": a full-colour letterhead
      saved as an opaque PNG is ~6x bigger. Measured on the real banner:
      631 KB as PNG -> **100 KB as JPEG q85**, whole upload 0.76s. A genuinely
      transparent PNG still takes the PNG path (verified with a cut-out test
      image — stayed PNG, 8 KB).
- [x] KNOWN COST: that ~100 KB base64 is inlined in the HTML of each of the 9
      pages (customers.php 173 KB, sales_management.php 208 KB). Inherent to
      the data-URL choice above. The knobs are `$maxWidth` (1200) and the
      JPEG quality (85) in `companyBanner_processUpload()`.

## Three rendering paths (they are NOT interchangeable)
1. **`renderPrintBanner()`** — pages that already own a printed-document
   layout. Renders the `<img>` and returns true, or renders NOTHING and returns
   false, so each caller reads `if (!renderPrintBanner()): ...original
   letterhead... endif`. That is why the no-banner case is unchanged.
   Used by: `purchase_order_view.php`, and the three delivery notes
   (`transfers_view.php`, `requisition_voucher_dn.php`,
   `consumption_voucher_dn.php` — those three blocks are byte-identical, md5
   verified).
2. **`includes/print_letterhead.php`** — screen-first pages that get handed out
   when printed. Hidden on screen, `display:block` in print; optional
   `$printLetterheadTitle`. Used by `ledger_report.php` (a ledger filtered to
   one account IS the statement sent to that customer/supplier) and
   `sales_view.php`.
3. **`getPrintLetterheadHtml()` + `getPrintLetterheadCss()`** — print windows
   JS builds with `document.write()` into a blank window, which never load
   `style.css`, so markup AND css must both be injected. Passed in via
   `json_encode()` into a `const companyLetterhead` (NOT echoed raw into the
   template literal — a company name containing a backtick would break the JS).
   Used by the customer + supplier statement popups and the 80mm POS receipt.
   Unlike path 1 this ALWAYS emits a letterhead: those three documents had no
   company identity of ANY kind before, only a Fabonic credit line. The POS
   receipt previously printed `APP_NAME`; that is now the last-resort fallback.

## NOT given the banner (internal documents — verified 0 hits)
`purchases_view`, `budget_view`, `requisition_voucher_view`,
`consumption_voucher_view`, `stock_adjustment_view`, `stock_taking_view`,
`import_costing`, `product_analysis`, `stock_report`.

## Verification
- All 9 target pages HTTP 200 with the banner present; the 3 internal spot
  checks have 0 hits. Banner removed -> `<img>` gone and the original text
  letterhead back on every page (regression check).
- Admin gate: non-Admin gets 302 and the menu item is hidden. CSRF-less POST
  hits `checkCSRF()`'s `die()` and changed nothing.
- All three rewritten inline `<script>` blocks pass `node --check` (the POS
  receipt nests a template literal inside `${...}`).

## KNOWN GAPS found while doing this (NOT fixed — separate tasks)
- There is **no payment/receipt voucher print page at all** under
  `modules/payments/` — the one document a customer expects on handing over
  cash does not exist.
- The 80mm POS receipt prints Item + Qty only: **no rates, no amounts, no
  total** on a customer-facing receipt.
- `ledger_report.php`'s `?standalone=1` deep-link view has no Print button,
  though it is the single-account view most likely to be sent out.

---
---

# FIX: Customer / Supplier balances read the General Ledger [x]
**Status: COMPLETED** — verified end-to-end via curl with a temporary test
login (removed afterwards). Read-only change: no schema change, no migration,
no writes. Confirmed nothing was written during testing.

## The bug
JV000233 (a `manual_journal` debiting CRDB BANK PIL) did not appear anywhere in
masters -> customers. Root cause: the customer/supplier balance was summed from
the SOURCE TABLES (`sales`+`receipts`, `purchases`+`payments`) and never touched
`transaction_details`. Journal vouchers post ONLY to the GL, so they were
structurally invisible. Two further defects fell out of the same query:
- **Imports missing from supplier balances**: `purchase_import` has no row in
  `purchases`, so 3.92bn of import credits + 770m of import-cost `journal`
  credits never reached the supplier list. Strat-Elec/TRA/BVM showed 0.00 while
  owing 1.13bn/692m/689m.
- **FX**: the LIST apis summed raw `amount` with no exchange rate while the
  BREAKUP apis multiplied by `exchange_rate_to_base` — the two screens
  disagreed by ~1.12bn on CRDB BANK PIL alone.

## The fix — GL is the single source of truth
`opening_balance` + `SUM(DR) - SUM(CR)` over `transaction_details`, filtered to
`t.status='active'`. Chosen over "add journals on top of the existing sums"
because it also fixes imports and FX, and makes these screens agree with
`modules/reports/ledger_report.php` by construction.

**Files (MODIFIED, 6):** `api/customers.php`, `api/suppliers.php`,
`api/customer-balance-breakup.php`, `api/supplier-balance-breakup.php`,
`modules/masters/customers.php`, `modules/masters/suppliers.php`

- [x] Safe to add `opening_balance` to the GL delta: `customers-sync.php` /
      `suppliers-sync.php` write it ONLY to `ledger_accounts`, never as a GL
      entry — so there is no double count. VERIFIED before changing anything.
- [x] Sign conventions UNCHANGED — customers DR-positive (`SUM(DR-CR)`),
      suppliers CR-positive (`SUM(CR-DR)`). No list column flipped sign.
- [x] JSON response shape unchanged, so the offline cache
      (`assets/js/sync-manager.js`), the `purchases.php` supplier dropdown and
      the `sales_management.php` customer dropdown are untouched.
      `total_sales`/`total_receipts` and `total_purchases`/`total_payments` keep
      their key names but now carry total DR / total CR (see file headers).
- [x] `sale_count`/`receipt_count`/`purchase_count`/`payment_count` still count
      SOURCE DOCUMENTS, so the list's Sales/Receipts/Purchases/Payments columns
      are unchanged.
- [x] Breakup modal now renders any voucher type (`voucherBadge()` /
      `voucherLabel()` + `VOUCHER_TYPES` map added to both master pages) —
      previously a hardcoded 2-way ternary that would have labelled a JV
      "Payment"/"Receipt".
- [x] Supplier modal Dr/Cr columns made accounting-true (a purchase CREDITS the
      supplier). Column placement now follows the new `dr_cr` field; the running
      balance still follows the payable convention, so NO FIGURE CHANGED — only
      which column a row prints in. Opening-balance row moved to the Credit
      column for the same reason. Colours kept semantic (increase=green).

## Verification
- 117 of 141 suppliers have only purchase/payment GL activity — all 117 match
  the old FX-correct formula to the cent (**0 mismatches**). Only the 24 with
  imports/journals change, exactly as predicted before coding.
- CRDB BANK PIL: was -1,458,544,507.49 (list) / -2,579,870,206.69 (modal),
  now **-339,413,000.69** on both, equal to the ledger report. JV000233 and
  JV000253 now show in the breakup with correct running balances.
- Supplier list values equal `ledger_report.php` closing balance negated
  (payable convention) — exact agreement on all spot-checked accounts.

## NOTE — sales are unaffected here but the pattern is worth knowing
`sales` was empty on this tenant, so the customer path was exercised via
receipts + journals only. The GL query is voucher-type agnostic, so sales flow
through it the same way.

---
---

# FEATURE: Consumption Voucher Edit [x]
**Status: COMPLETED** — verified end-to-end via curl with a temporary test
login + a temporary 2-batch test product (all test data removed afterwards)

## Why it needed a migration
The FIFO loop deducted `stock_batches.quantity_remaining` without recording
WHICH batches it drew from — only the resulting weighted `rate_per_unit` was
kept on the detail row. Precise reversal was therefore impossible, which is why
the module was delete-only (delete inserted a NEW batch dated today at the
weighted rate — quantity restored, FIFO order and cost layering distorted).
NOTE: `api/sales-sync.php` still reverses the loose way (dumps the whole qty
onto the OLDEST batch) — untouched, out of scope.

**Files:** `sql/migrations/0040_add_consumption_voucher_batches.sql` (NEW),
`modules/transactions/consumption_voucher.php`, `consumption_voucher_view.php`,
`consumption_voucher_list.php`, `includes/functions.php`, `lang/en.php`, `lang/sw.php`

- [x] `consumption_voucher_batches` (voucher_id, detail_id, batch_id, quantity,
      rate_per_unit) written inside the FIFO loop at create/edit
- [x] `cvBatchTrackingAvailable()` + `cvRestoreStock()` in functions.php (shared
      by the form's edit path and the view's delete path). Precise restore when
      link rows exist; ORIGINAL insert-a-new-batch fallback otherwise — covers
      both pre-0040 vouchers AND tenants that have not run the migration
      (verified by renaming the table away: create/edit/delete all still work)
- [x] Edit mode is `consumption_voucher.php?id=`, gated on `can_edit`. One
      transaction: restore → delete old details (links cascade) → validate stock
      → update header → re-run FIFO. Any shortfall throws → full rollback, so a
      failed edit leaves the voucher exactly as it was. Voucher number preserved.
- [x] Stock validation on edit runs INSIDE the transaction after the reversal —
      deliberately, so `getProductStockOnFly()` / `stock-check.php` did NOT need
      an "exclude this voucher" parameter (shared helpers stay untouched)
- [x] Edit buttons on list + view; errors now render inline on the form
      (`setAlert()` is dead app-wide — `getAlert()` is never called anywhere)
- [x] Current-stock column adds back the voucher's own consumption
      (`OWN_CONSUMPTION`), but only at its original location, since that is where
      the reversal lands
- [x] Select2 on product / location / cost-centre dropdowns (client-side, same
      pattern as `stock_report.php`). NOTE: Select2 fires `change` via jQuery,
      which reaches inline `onchange` attributes but NOT `addEventListener`
      handlers — the location handler is rebound with jQuery when Select2 loads,
      with the native listener kept as the no-Select2 fallback.

## KNOWN GAP (not fixed — separate task)
`api/stock-check.php` and `getProductStockOnFly()` never count
`purchase_import_details`, though imports DO write `stock_batches` and the stock
card report DOES count them. Any imported product therefore shows an understated
"Current stock" in consumption + sales. Also note reports aggregate child
locations (PART 4 of the hierarchy feature) while transaction pages are strictly
single-location — a parent location legitimately shows 0 when the stock sits in
a child.

---
---

# FEATURE: Purchase Orders (ACTIVE)

## Overview
New Purchase Order (PO) document under Transactions. A PO is a commitment
document — it NEVER touches stock (`stock_batches`) or GL (`transactions`).
Only the Purchase created from it does, exactly as today.

**Two entry paths:**
1. **Direct PO**: add/edit (draft) → send for approval → approve-with-edit /
   reject (like Requisition Voucher) → convert to Purchase(s) → auto-close.
2. **Budget PO**: "Create PO" button on approved budget (beside the existing
   "Create Purchase" button) → PO saved directly in `approved` status
   (budget approval covers it), carries `budget_id`.

## Aligned Decisions (user-confirmed)
- Strictly additive — NO existing feature may be disturbed.
- Approval workflow mirrors Requisition Voucher, **approve-with-edit**:
  approver may adjust per-line quantity/rate (`approved_quantity`,
  `approved_rate`), or reject with remarks.
- **Partial receiving**: one PO → multiple Purchases over time; per-line
  `purchased_quantity` accrues; PO auto-closes when fully received.
  Conversion qty defaults to remaining = COALESCE(approved, ordered) − purchased.
- **Budget remaining math**: budget's remaining-to-create must also subtract
  quantities on OPEN (not yet fully converted) POs, so the same budget line
  can't be double-procured via PO + direct purchase. Avoid double counting:
  remaining = budgeted − purchased(budget_id) − outstanding-on-open-POs
  (outstanding = PO qty − PO purchased_quantity).
- **Local purchases only** for v1 — imports keep their current direct flow.
- Purchases created from a PO store `po_id` and inherit `budget_id` when the
  PO came from a budget (keeps budget linked-purchases list + math working).
- Numbering via `getNextNumber()`, configurable prefix `getSetting('po_prefix','PO')`.
  NOTE: `purchases.po_number` (free-text external PO ref) stays as-is — separate thing.
- New permission module `purchase_orders` (can_view/create/edit/delete/approve).

## Key patterns to mirror
- Requisition Voucher module = template: `modules/transactions/requisition_voucher*.php`,
  `api/requisition-vouchers*.php`, migration `0011`, status enum + approval audit cols.
- Purchase prefill deep-links in `purchases.php` (`?from_requisition`, `?from_budget`)
  → add `?from_po` the same way.
- Sync API pattern: `api/purchases-sync.php` (action dispatch, DB transaction,
  `isLoggedIn()` + `hasPermission()` + `canAccessLocation()`, `logAudit()`).

---

## PARTS & PROGRESS

### PART 1: Database Migration & Permissions [x]
**Status: COMPLETED** — applied + verified on local `cei` DB (no local
`migrations` tracking table; production tracks per-tenant via run-migrations.php)

**Files:** `sql/migrations/0038_add_purchase_orders.sql` (NEW, done)

- [x] `purchase_orders` header table (status enum incl. rejected/closed/cancelled,
      budget_id, approval/rejection/close audit cols, signatures like budgets)
- [x] `purchase_order_details`: quantity, rate_per_unit, approved_quantity,
      approved_rate, purchased_quantity, remarks
- [x] `purchases.purchase_order_id` column (NULL, FK SET NULL, idempotent guard)
      — NAMED `purchase_order_id` NOT `po_id`, to avoid confusion with the
      existing free-text `purchases.po_number` (external supplier PO ref)
- [x] Seeded `user_permissions` module `purchase_orders` for Admin + Manager
      (view/create/edit/delete/approve); `po_prefix` setting = 'PO'

### PART 2: PO Module Pages + APIs [x]
**Status: COMPLETED** — tested end-to-end via curl with a local test login

**Files (NEW):** `modules/transactions/purchase_order_list.php`,
`purchase_order.php` (form), `purchase_order_view.php` (view + print),
`api/purchase-orders-sync.php` (lifecycle mutations)

- [x] Architecture follows the BUDGET module (newest pattern): create/edit is
      a server-rendered POST-to-self form with CSRF; lifecycle actions go
      through the sync API from the view page. NO plain GET api needed
      (budgets don't have one either) — api/purchase-orders.php NOT created.
- [x] List page: filters (supplier, location, status, date), status badges,
      draft-only delete via POST + CSRF
- [x] Form: supplier (select2), location tree, cost centre tree, po_date,
      expected_date, currency + auto-fetched exchange rate (hidden for
      default currency), remarks, product select2 rows (qty/rate/remarks),
      VAT flat/% toggle, totals; total_amount computed SERVER-side from lines
- [x] View page: doc header, print heading, effective (approved) line values,
      purchased/remaining columns post-approval, linked purchases badges,
      status actions, approve-with-edit modal w/ signature pad, reject modal,
      cancel modal, revise button
- [x] Menu item in header.php (+ module in the OR-check), module row in
      modules/masters/users_form.php, lang keys in en.php + sw.php
      (NOTE: 'cancel_reason' + 'po_number' already existed — reused)

### PART 3: Approval Workflow [x]
**Status: COMPLETED** — all transitions + guards verified via curl

- [x] Sync API actions: send_for_approval, approve (per-line approved_quantity
      + approved_rate, qty 0 = line rejected, header total recomputed from
      approved lines), reject (reason), revise (rejected -> draft, clears
      approval metadata), cancel (approved + nothing purchased -> cancelled,
      uses closed_by/close_reason cols), delete (draft only)
- [x] Guards verified: approve on non-pending fails; delete on non-draft fails
- [x] Notifications: notifyApprovers('purchase_orders', ...) on send;
      createNotification to creator on approve/reject (best-effort try/catch)
- [x] Verified PO lifecycle writes ZERO stock_batches / transactions rows

### PART 4: Convert PO → Purchase [x]
**Status: COMPLETED** — full conversion cycle verified via curl (partial,
over-receipt block, auto-close, reopen-on-delete, recalc-on-update)

**Files (MODIFIED, additive branches only):**
`modules/transactions/purchases.php`, `api/purchases-sync.php`

- [x] "Create Purchase" button on approved PO view (shown while remaining > 0)
      → `purchases.php?from_po=<id>`
- [x] `purchases.php`: `$poPrefill` PHP block + `window.__poPrefill` +
      `applyPoPrefill()` JS (presets supplier/location/cc/currency + PO's
      exchange rate; items = remaining at approved rate) + save payload sends
      `purchase_order_id`; existing prefill branches untouched
- [x] `api/purchases-sync.php`: create accepts `purchase_order_id` (validates
      PO approved + supplier match + over-receipt); the link is written via a
      separate UPDATE after insert (keeps existing INSERT branches untouched);
      update/delete of a linked purchase re-validate + recalc
- [x] Helpers appended to purchases-sync.php: `po_link_hasColumn` (guards
      pre-migration tenants), `po_link_fetch`, `po_link_checkOverReceipt`
      (per-product, excludes the purchase being updated), `po_link_recalc`
      (sequential per-line distribution, excess absorbed by product's last
      line; flips ONLY approved<->closed, sets/clears closed_by/at)
- [x] Purchase inherits `budget_id` + budget's `requisition_id` from the PO
      server-side (JS also passes them) — budget tracking keeps working
- [x] Over-receipt guard verified on create AND update
- [x] PO view lists linked purchases (badges)

### PART 5: Budget Integration [x]
**Status: COMPLETED** — full RV → budget → PO → purchase chain verified via curl

**Files (MODIFIED, additive):** `modules/transactions/budget_view.php`,
`modules/transactions/purchase_order.php`, `modules/transactions/purchases.php`,
`includes/functions.php`

- [x] `getBudgetPoOutstanding()` helper in functions.php (open-PO outstanding
      per supplier|product for a budget; try/catch so pre-0038 tenants get [])
      + `budgetHasLinkedPurchases()` now also counts open POs (locks budget
      edit while a PO is outstanding — protective, guarded)
- [x] "Create PO" button per purchase-type supplier group in budget_view
      (NOT import groups), gated by `hasPermission('purchase_orders','create')`
- [x] `purchase_order.php?from_budget=<id>&supplier=<sid>`: supplier locked,
      header from budget, items prefill = alloc − purchased − other-PO
      outstanding at budgeted rate; per-product cap enforced SERVER-side on
      POST + client-side submit guard (setAlert messages don't render anywhere
      app-wide — pre-existing quirk — so client guard mirrors budget.php UX)
- [x] Budget PO saved directly as 'approved' (approved_by/at = creator/now,
      budget_id set) — handled in the FORM (not sync API; create lives there)
- [x] Budget remaining math subtracts open-PO outstanding in: budget_view
      groups (new "On PO" column, purchase groups only) AND purchases.php
      `?from_budget` prefill — no double counting after conversion (verified:
      20 alloc, PO 12 → remaining 8; converted → procured 12, on PO 0, rem 8)
- [x] Budget view lists linked POs (badges with status, before purchases)

### PART 6: Testing & Edge Cases [x]
**Status: COMPLETED** — all verified via curl with local test logins
(test users + all test documents removed from dev DB afterwards)

- [x] Direct PO: draft → approval → approve-with-edit (total recomputed from
      approved lines) → convert → auto-close
- [x] Reject → revise → re-submit cycle (revise clears approval metadata)
- [x] Partial receiving: 2 purchases from one PO, remaining decrements,
      auto-close on full receipt; over-receipt rejected on create AND update
- [x] Purchase update recomputes purchased_quantity; delete reopens closed PO
- [x] Budget PO: created pre-approved; budget remaining reflects open PO;
      converting doesn't double-count (purchase inherits budget_id +
      requisition_id so it moves qty from "On PO" to "Procured")
- [x] Direct purchase from budget unchanged (prefill still works, now also
      subtracts PO outstanding)
- [x] RV flows untouched (RV create/approve exercised during Part 5 chain)
- [x] Permissions: non-admin without module row → menu hidden, page 302,
      sync API "No permission"; cancel/approve/delete guards verified
- [x] PO lifecycle + conversion writes ZERO extra stock_batches/transactions
      rows beyond the purchase's own (verified today's counts = 0 after
      cleanup)
- [x] View page renders with print heading (print = CSS @media like budgets)

---
---

# FEATURE: Hierarchical Locations & Cost Centres

## Overview
Add unlimited parent-child nesting to both Locations and Cost Centres.
- Dropdowns everywhere show indented tree structure
- Reports aggregate data from parent + all descendants
- Report style: Aggregated totals (not per-sub breakdown)

## Architecture Decisions
- **Adjacency List** pattern: add `parent_id` (nullable, self-ref FK) to both tables
- **Tree built in PHP** (not MySQL recursive CTE) for compatibility
- `ON DELETE SET NULL` — deleting parent promotes children to root level
- Circular reference protection on save

---

## PARTS & PROGRESS

### PART 1: Database Migration & Helper Functions [x]
**Status: COMPLETED**

**Files to create/modify:**
- `sql/migrations/XXXX_add_hierarchy_to_locations_and_cost_centres.sql` (NEW)
- `includes/functions.php` (MODIFY)

**Tasks:**
- [ ] Create migration SQL: add `parent_id` to `locations` table
- [ ] Create migration SQL: add `parent_id` to `cost_centres` table
- [ ] Add helper: `buildTree($flatItems)` — converts flat DB rows to nested tree array
- [ ] Add helper: `renderTreeOptions($tree, $selectedId, $depth, $excludeId)` — renders indented `<option>` tags with "— — " prefixes
- [ ] Add helper: `getDescendantLocationIds($locationId, $conn)` — returns array of ALL descendant location IDs
- [ ] Add helper: `getDescendantCostCentreIds($costCentreId, $conn)` — returns array of ALL descendant cost centre IDs
- [ ] Add helper: `getLocationAncestorIds($locationId, $conn)` — for circular ref protection
- [ ] Add helper: `getCostCentreAncestorIds($costCentreId, $conn)` — for circular ref protection
- [ ] Update `getUserLocations()` to also return `parent_id`
- [ ] Update `getUserCostCentres()` to also return `parent_id`

---

### PART 2: Master Pages UI (Locations & Cost Centres CRUD) [x]
**Status: COMPLETED**

**Files to modify:**
- `modules/masters/locations.php` (MODIFY)
- `modules/masters/cost_centres.php` (MODIFY)
- `api/locations.php` (MODIFY — return parent_id)
- `api/locations-sync.php` (MODIFY — accept parent_id in create/update)
- `api/cost_centres.php` (MODIFY — return parent_id)
- `api/cost_centres-sync.php` (MODIFY — accept parent_id in create/update)

**Tasks:**
- [ ] Locations modal: add "Parent Location" dropdown (tree-based, exclude self+descendants when editing)
- [ ] Locations list: show indented/tree structure in table
- [ ] Locations API: return `parent_id` in GET response
- [ ] Locations sync API: accept `parent_id` in create/update, validate no circular ref
- [ ] Cost Centres modal: add "Parent Cost Centre" dropdown
- [ ] Cost Centres list: show indented/tree structure in table
- [ ] Cost Centres API: return `parent_id` in GET response
- [ ] Cost Centres sync API: accept `parent_id` in create/update, validate no circular ref

---

### PART 3: Replace All Dropdowns Across App [x]
**Status: COMPLETED**

Every place that renders a location or cost centre `<select>` needs to use the tree-based indented options.

**Files to modify (Locations dropdown):**
- `modules/transactions/purchases.php`
- `modules/transactions/sales.php`
- `modules/transactions/stock_transfer.php`
- `modules/transactions/consumption_voucher.php`
- `modules/transactions/stock_adjustment.php`
- `modules/transactions/stock_taking.php`
- `modules/payments/payments.php`
- `modules/payments/receipts.php`
- `modules/reports/stock_report.php`
- `modules/reports/stock_taking_list.php`
- `modules/reports/cost_centre_report.php`
- `modules/reports/cost_centre_comparison.php`
- Any other file with location `<select>`

**Files to modify (Cost Centre dropdown):**
- Same transaction files above that also have cost centre dropdowns
- Report files above
- Any other file with cost centre `<select>`

**Approach:**
- Use `buildTree(getUserLocations())` then `renderTreeOptions($tree, ...)`
- Use `buildTree(getUserCostCentres())` then `renderTreeOptions($tree, ...)`
- Replace the `<?php foreach ... ?>` loops with calls to `renderTreeOptions()`

---

### PART 4: Report Aggregation [x]
**Status: COMPLETED**

When a parent location/cost centre is selected in a report, aggregate data from parent + ALL descendants.

**Approach used:**
- `getDescendantLocationIds()` / `getDescendantCostCentreIds()` return parent + all descendant IDs
- Build safe IN clause: `$locInClause = implode(',', array_map('intval', $locationIds))`
- Embed IN clause directly in SQL (safe because all values are intval'd)
- Remove location/cost-centre bind params from execute arrays

**Files modified:**
- `modules/reports/stock_report.php` — 24x `location_id = ?` → `location_id IN ($locInClause)`
- `modules/reports/cost_centre_report.php` — cost_centre + location aggregation via IN clauses
- `modules/reports/cost_centre_comparison.php` — each cost centre row aggregates descendants
- `modules/reports/profit_loss.php` — location + cost centre aggregation (including expenses)
- `modules/reports/product_stock_card.php` — all opening balance + period transaction queries
- `modules/reports/stock_taking_list.php` — location filter aggregates descendants
- `modules/reports/product_analysis.php` — location filter aggregates descendants
- `modules/reports/import_costing.php` — location filter aggregates descendants

**NOT modified (by design):**
- `modules/reports/stock_taking.php` — physical stock counting must be per single location
- `includes/functions.php` `getLocationStock()` / `getProductStockOnFly()` — operational helpers that should remain single-location for stock validation

---

### PART 5: Testing & Edge Cases [ ]
**Status: NOT STARTED**

- [ ] Test creating root-level location/cost centre (parent_id = NULL)
- [ ] Test creating child under a parent
- [ ] Test creating grandchild (3+ levels deep)
- [ ] Test dropdown indentation shows correctly at all levels
- [ ] Test editing a location to change its parent
- [ ] Test that circular reference is rejected (A -> B -> A)
- [ ] Test deleting a parent (children should become root-level)
- [ ] Test stock report with parent location selected (should aggregate)
- [ ] Test cost centre report with parent selected (should aggregate)
- [ ] Test user access control still works (non-admin only sees assigned locations/cost centres)
- [ ] Test "All Locations" option still works correctly in reports

---

## NOTES
- MySQL version assumed to support standard SQL (no recursive CTE used — tree built in PHP)
- All tree operations are done in PHP for maximum DB compatibility
- The `renderTreeOptions()` function uses "— " repeated by depth level for indentation
- parent_id is NULLABLE — NULL means root level
- Foreign key ON DELETE SET NULL so deleting parent doesn't delete children
