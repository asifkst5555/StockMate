# StockMate — Final Role & Permission Matrix (Post-Remediation)

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`

---

## 1. Enforced Role Hierarchy Matrix

| Action / Endpoint | Super Admin (`superadmin`) | Store Admin (`admin`) | Staff / Cashier (Custom Role) | Public (Unauthenticated) |
|---|---|---|---|---|
| **Create Super Admin** (`POST /api/sadmin/super-admins`) | **ALLOWED** | **DENIED (403)** | **DENIED (403)** | **DENIED (401)** |
| **List Super Admins** (`GET /api/sadmin/super-admins`) | **ALLOWED** | **DENIED (403)** | **DENIED (403)** | **DENIED (401)** |
| **Delete / Deactivate Super Admin** (`DELETE` / `POST /api/sadmin/super-admins/{id}`) | **ALLOWED** (except self or **last active Super Admin** → 403) | **DENIED (403)** | **DENIED (403)** | **DENIED (401)** |
| **Create Store Admin** (`POST /api/sadmin/admin-users`) | **ALLOWED** | **DENIED (403)** | **DENIED (403)** | **DENIED (401)** |
| **Public SaaS Self-Signup** (`POST /api/register`, throttled `10/min`) | N/A | N/A | N/A | **ALLOWED** (strictly `Role::ADMIN` + isolated Store/Tenant; ignores `role_id`/`tenant_id`) |
| **List Store Users** (`GET /api/users`) | Scoped | **ALLOWED** (own stores only) | **ALLOWED** only with `view_users\|manage_users\|manage_reports` | **DENIED (401)** |
| **Create Store User** (`POST /api/users`) | N/A | **ALLOWED** (own stores & own non-admin roles only) | **ALLOWED** only with `create_users\|manage_users` (own stores & roles ≤ own permissions) | **DENIED (401)** |
| **Assign `superadmin` or `admin` via `/api/users`** | **DENIED (403)** | **DENIED (403)** | **DENIED (403)** | **DENIED (401)** |
| **Edit Store User** (`POST/PUT/PATCH /api/users/{id}`) | N/A | **ALLOWED** (own store non-admin users; never self) | **ALLOWED** only with `edit_users\|manage_users` (own store non-admin users; never self) | **DENIED (401)** |
| **Toggle User Status** (`POST /api/users/status/{id}`) | N/A | **ALLOWED** (own store non-admin users; never self) | **ALLOWED** only with `edit_users\|manage_users` (own store non-admin users) | **DENIED (401)** |
| **Reset User Password** (`POST /api/change-user-password`) | **ALLOWED** (non-Super-Admin) | **ALLOWED** (own store non-admin users only) | **ALLOWED** only with `edit_users\|manage_users` (own store non-admin users) | **DENIED (401)** |
| **Delete Store User** (`DELETE /api/users/{id}`) | N/A | **ALLOWED** (own store non-admin users; never self) | **ALLOWED** only with `delete_users\|manage_users` (own store non-admin users) | **DENIED (401)** |
| **Update Own Profile** (`POST /api/update-profile`) | **ALLOWED** (allowlisted fields only) | **ALLOWED** (allowlisted fields only; `tenant_id`/`status`/`role_id` ignored) | **ALLOWED** (allowlisted fields only; `tenant_id`/`status`/`role_id` ignored) | **DENIED (401)** |
| **Manage Custom Roles** (`GET/POST/PUT/DELETE /api/roles`) | N/A | **ALLOWED** (own tenant custom roles only; global roles blocked) | **ALLOWED** per fine-grained `view/create/edit/delete_roles\|manage_roles` (own tenant only) | **DENIED (401)** |
