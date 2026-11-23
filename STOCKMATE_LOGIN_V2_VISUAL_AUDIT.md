# StockMate — Login Page V2 Visual Refinement Audit

## 1. Identified V1 Visual Deficiencies

Reviewing the V1 login layout against commercial SaaS standards (Stripe / Linear / Shopify):
1. **Left Panel Whitespace & Scale**: Left column used `flex: 1 1 50%` with a narrow `540px` inner block and small `54px` logo, leaving noticeable unused space at `1920×1080` and `1440×900`.
2. **Basic Dashboard Preview**: V1's CSS preview card lacked an application sidebar rail, realistic multi-panel workspace hierarchy, POS transaction rows, and stock level indicator.
3. **Login Card Proportions**: V1 card was `440px` wide with `46px` inputs and `16px` radius, feeling slightly compact next to a full-viewport desktop screen.

## 2. V2 Proportions & Visual System Applied

| Element | V1 | V2 Target & Applied Value |
|---|---|---|
| Desktop Column Split | ~50% / 50% | **`52%` Left Brand Panel / `48%` Right Auth Panel**, full `100vh` height |
| Left Inner Max Width | `540px` | **`660px`** with coherent `28px` vertical rhythm (`22px` on `<=1440px`) |
| StockMate Logo Width | `height: 54px` | **`172px` wide** (`150–180px` target) + subtle `"Inventory & POS Platform"` pill |
| Left Headline | `36px` | **`38px`** (`33px` laptop, `27px` tablet) — *"Take Control of **Your Business.**"* (`#092B48` + `#F47716`) |
| Product Dashboard Preview | Single-pane basic bars | **Realistic StockMate Workspace**: Deep Navy `#092B48` topbar (`POS Live Sync`), mini sidebar (`Overview`, `POS`, `Stock`, `Stores`), 3 KPI cards (`Daily Sales $14,820`, `Total Products 1,248`, `Active Stores 4`), 7-day Sales Performance bar chart (`$48.2k / 7d`), and Live POS & Stock panel (`POS #1048`, `POS #1047`, `82%` Healthy Stock bar) |
| Feature Cards | Variable height | 3 equal-height (`min-height: 58px`) cards (*Smart Inventory Management*, *Powerful Point of Sale*, *Multi-Store Business Control*) |
| Login Card | `440px`, `16px` radius | **`472px` max-width** (`460–480px` target), **`20px` radius** (`18–22px` target), `42px 40px` padding |
| Inputs & Sign In Button | `46px` height | **`50px` height** (`48–52px` target), `11px` radius, `#F47716` focus border/ring & primary orange gradient |
| Developer Quick Access | `40px` height | **`44px` height**, Navy `#092B48` Super Admin + Orange `#F47716` Store Admin, gated on `local_quick_login_enabled === true` |
| Language Selector | Neutral pill | Top-right white pill, `#E2E8F0` border, `#092B48` navy text, `#F47716` orange hover/active accent |
