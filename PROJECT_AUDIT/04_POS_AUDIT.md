# Phase 4: POS System Audit

## 1. End-to-End POS Workflow Verification

Inspected `resources/pos/src/frontend/components/PosMainPage.js`, `app/Http/Controllers/API/SaleAPIController.php`, `app/Repositories/SaleRepository.php`, `app/Http/Controllers/API/HoldAPIController.php`, and `app/Http/Controllers/API/POSRegisterAPIController.php`.

| # | POS Step | Status | Verified Implementation & Findings |
|---|---|---|---|
| 1 | Product search | Implemented | `ProductSearchbar` + `posSearchNameProduct` / `fetchBrandClickable` |
| 2 | Barcode scanning | Implemented | `posSearchCodeProduct` searches by product code/barcode |
| 3 | Product selection | Implemented | Category & Brand filter tabs (`Category.js`, `Brand.js`, `Product.js`) |
| 4 | Shopping cart | Implemented | `ProductCartList`, synced to Redux & `localStorage['cart-sync']` for dual-screen customer display |
| 5 | Quantity adjustment | Implemented | `updatedQty`, `ProductDetailsModel` |
| 6 | Item removal | Implemented | `onDeleteCartItem` |
| 7 | Discount calculation | Partially Broken | `PosMainPage.js:578` uses `parseInt(cartItemValue.discount_value)`, truncating decimal discounts (e.g. `12.5%` -> `12`) |
| 8 | Tax calculation | Implemented | Item-level & order-level exclusive/inclusive tax in `SaleRepository::calculationSaleItems` |
| 9 | Subtotal & grand total | Implemented | Recalculated server-side in `SaleRepository::storeSaleItems` (good: does not blindly trust client total) |
| 10 | Customer selection | Implemented | `PosHeader` dropdown + inline `CustomerForm` modal |
| 11 | Cash payment | Implemented (with edge-case bugs) | `CashPaymentModel` + `SaleRepository::storeSaleItems` |
| 12 | Other payment methods | Implemented | Dynamic `PaymentMethod` model + split payments (`payment_details[]`) |
| 13 | Change calculation | Partially Broken | Frontend caps single payment `amount` to `grandTotal` (`PosMainPage.js:586-593`), losing actual `received_amount`; backend hardcodes `received_amount = grand_total` (`SaleRepository.php:318`) and marks `totalAmount > grand_total` as `PARTIAL_PAID` instead of `PAID` (`SaleRepository.php:326`) |
| 14 | Order creation | Partially Broken | Wrapped in `DB::beginTransaction()`, but **mail & SMS calls run inside transaction** (SMTP failure rolls back valid sale), and **Hold is deleted before sale commits** (`SaleAPIController.php:106-111`) |
| 15 | Stock deduction | Partially Broken | Deducts in `SaleRepository.php:114-128` without `lockForUpdate()`, creating overselling race condition under concurrent checkouts |
| 16 | Invoice generation | Implemented | `SaleAPIController::pdfDownload` + barcode PNG generation |
| 17 | Receipt printing | Implemented (with crash risk) | `useReactToPrint` + `PrintData` + `PaymentSlipModal`; `PosMainPage.js:670,718` accesses `paymentValue.payment_type.label` without optional chaining (crashes if `paymentMethods` not loaded yet) |
| 18 | Sales history & Recent Sales | Implemented | `RecentSaleModal` + `SaleAPIController::index` |
| 19 | Refund & return workflow | Partially Broken | `SaleReturnAPIController` / `SaleReturnRepository`; allows returning more than sold across multiple returns (duplicate-return check commented out on lines 246-250) |

## 2. Critical POS Integrity Risks to Fix

1. **Prevent Negative Stock & Race Conditions**:
   - Use `ManageStock::whereWarehouseId(...)->whereProductId(...)->lockForUpdate()->first()` inside the sale transaction.
   - Reject `quantity <= 0` on sale items.
2. **Prevent Duplicate Order Submissions & Premature State Reset**:
   - `PosMainPage.js:601-643` resets cart/payment state synchronously before `posCashPaymentAction` resolves and has no `isSubmitting` guard.
3. **Fix Payment Status & Change Calculation**:
   - In `SaleRepository::storeSaleItems`, treat `round($totalAmount, 2) >= round($input['grand_total'], 2)` as `Sale::PAID` (not `PARTIAL_PAID`), and preserve `received_amount` when provided.
   - Reject `PAID` / `PARTIAL_PAID` orders without valid positive payment records.
4. **Move Hold Deletion Inside Transaction**:
   - Delete `Hold` only after `storeSale()` succeeds (or inside the same transaction).
5. **Isolate Non-Critical Notifications (Mail/SMS) From Sale Transaction**:
   - Wrap email/SMS dispatch after `DB::commit()` in try/catch + `Log::warning()`, so a misconfigured local SMTP server never aborts a POS checkout.
