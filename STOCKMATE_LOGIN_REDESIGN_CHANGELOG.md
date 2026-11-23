# StockMate — Login Page Redesign Changelog

## Files Created & Modified

1. **Created `resources/pos/src/components/auth/Login.css`**:
   - Scoped strictly under `.sm-login-page` using the official StockMate palette: Primary Orange (`#F47716`), Orange Accent (`#FF8A00`), Deep Navy (`#092B48`), Dark Text (`#17212B`), Secondary Text (`#64748B`), White (`#FFFFFF`), Soft Background (`#F8FAFC`), Border (`#E2E8F0`).
   - Two-column desktop layout (`sm-login-brand` left panel + `sm-login-panel` right panel), tablet compaction (`<= 1199px`), and full mobile layout (`<= 991px` & `<= 440px`) with zero horizontal overflow.
   - Styled CSS Inventory & POS workspace preview card (`sm-preview-card` with `#092B48` top bar, KPI stat tiles, orange/navy bar chart, live sync footer) and 3 compact feature highlights.
   - Redesigned top-right Language Selector (`sm-login-lang`) to white neutral pill with `#E2E8F0` border and `#F47716` orange hover/active accents (removing Bootstrap bright blue).
   - Styled inputs with `46px` height, `#F47716` focus border & subtle focus ring, accessible password visibility toggle, `#F47716 -> #FF8A00` primary **Sign In** button, and compact **Developer Quick Access** buttons (Deep Navy `#092B48` for Super Admin, Orange `#F47716` for Store Admin).
2. **Updated `resources/pos/src/components/auth/Login.js`**:
   - Replaced legacy purple single-column layout with the two-column StockMate brand experience + premium authentication card.
   - Left column displays prominent supplied StockMate logo (`stockmate-logo.webp` at `54px` height), headline *"Take Control of Your Business."*, subtitle, CSS workspace preview, and 3 feature highlights (*Smart Inventory Management*, *Powerful Point of Sale*, *Multi-Store Business Control*).
   - Right column displays *"Welcome back"* / *"Sign in to continue to your StockMate workspace."*, accessible Email Address & Password fields, Forgot Password link, Orange **Sign In** button, Registration link, and local-only **Developer Quick Access** buttons (`faUserShield` Super Admin, `faStore` Store Admin) gated on `frontCms?.value?.local_quick_login_enabled === true`.
3. **Rebuilt `public/js/app.js` & `public/mix-manifest.json`** via `npm run prod`.
