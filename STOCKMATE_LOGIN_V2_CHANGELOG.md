# StockMate — Login Page V2 Changelog

## Modified Files (UI Only — Zero Auth / Backend Changes)

1. **`resources/pos/src/components/auth/Login.css`**:
   - Rebuilt split-screen proportions to `52%` left / `48%` right at full `100vh` viewport height with zero unnecessary scrolling on `1920×1080` and `1440×900`.
   - Expanded left brand inner container to `660px`, sized supplied `stockmate-logo.webp` to `172px` width, and styled the realistic multi-pane StockMate workspace preview (`sm-preview-shell`, `sm-preview-sidebar`, `sm-kpi-grid`, `sm-preview-lower`, `sm-bars-wrap`, `sm-activity-list`, `sm-stock-bar-bg`).
   - Refined right authentication card to `472px` max-width, `20px` border-radius, `42px 40px` padding, `50px` inputs, `50px` orange **Sign In** button, and `44px` Navy/Orange **Developer Quick Access** buttons.
   - Refined responsive breakpoints for Laptop (`<= 1440px`), Tablet (`<= 1199px`, e.g. `1024×768`), Mobile (`<= 991px`, e.g. `390×844`), and Narrow Mobile (`<= 440px`).
2. **`resources/pos/src/components/auth/Login.js`**:
   - Upgraded left brand column with top logo + platform pill, highlighted *"Take Control of Your Business."* headline, realistic StockMate Workspace preview (Daily Sales, Total Products, Active Stores, 7-day Sales Performance, Live POS transactions & Healthy Stock Level bar), and 3 equal-height feature cards.
   - Preserved 100% of existing `loginAction`, `quickLoginAction`, `logoutAction`, validation, Forgot Password, Register, and `LanguageLayout` behavior.
3. **`public/js/app.js` & `public/mix-manifest.json`**:
   - Rebuilt via `npm run prod`.
