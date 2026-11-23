# StockMate — Final Security Regression Report

**Date:** 2026-11-23  
**Test Command:** `docker exec digital_inventory_pos_app php artisan test`  
**Total Result:** **31 passed, 0 failed** (`1.25s`)

---

## Complete PHPUnit Test Execution Breakdown

### 1. `Tests\Feature\FinalSecurityHardeningTest` (4 / 4 Passed)
- `✓ post01 deactivation revokes sanctum tokens and blocks reused bearer tokens`
- `✓ post02 role self escalation and permission ceiling enforced`
- `✓ post03 admin update rolls back atomically on store update failure`
- `✓ post04 store status requires post and enforces ownership`

### 2. `Tests\Feature\UserManagementSecurityTest` (8 / 8 Passed)
- `✓ super admin can create store admin and another super admin`
- `✓ store admin cannot create super admin or assign global admin roles`
- `✓ staff with manage reports or view users only cannot mutate users`
- `✓ public registration always assigns store admin and ignores escalation fields`
- `✓ user cannot change own role or tenant id`
- `✓ cross tenant isolation for passwords status stores and roles`
- `✓ last active super admin cannot be deleted or deactivated`
- `✓ authorized store admin retains full own store user management`

### 3. `Tests\Feature\SecurityAndPosAuditTest` (17 / 17 Passed)
- `✓ upgrade route is blocked when upgrade mode is false`
- `✓ cache clear requires authentication`
- `✓ validate auth token returns 401 without bearer token`
- `✓ login requires email and password`
- `✓ user cannot switch to or delete another tenants store`
- `✓ permission restriction blocks unauthorized user from reports and superadmin translations`
- `✓ purchase received increments stock and records movement while pending does not`
- `✓ pos checkout split payment overpayment stock deduction and negative stock prevention`
- `✓ stock transfer blocks same warehouse and moves stock between warehouses`
- `✓ stock valuation and tenant isolation on manage stocks`
- `✓ sales return restores stock and purchase return deducts stock`
- `✓ secure file controller enforces auth permissions tenant isolation and anti traversal`
- `✓ stockmate admin accounts authenticate and reject invalid credentials`
- `✓ quick login is disabled and unregistered outside local environment`
- `✓ logout revokes bearer token and blocks subsequent protected requests`
- `✓ stockmate contact information and whatsapp links render on landing page`
- `✓ usd is default currency and bdt is available without duplicates`

### 4. Base Framework Tests (2 / 2 Passed)
- `✓ Tests\Unit\ExampleTest :: that true is true`
- `✓ Tests\Feature\ExampleTest :: example`
