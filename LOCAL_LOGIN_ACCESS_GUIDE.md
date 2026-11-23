# Local Login Access Guide (`http://127.0.0.1:8000`)

## 1. Verified Existing Local Accounts

Queried the local `digital_inventory_pos` MySQL database (`users` table) directly — both seeded accounts exist, are email-verified, and are active:

| Role | Email | Exists in Local DB | Verified | Active | Login URL | Post-Login Dashboard |
|---|---|---|---|---|---|---|
| **Store Admin** | `admin@infy-pos.com` | Yes | Yes | Yes | `http://127.0.0.1:8000/app/login` | `http://127.0.0.1:8000/app/user/dashboard` |
| **Super Admin** | `superadmin@infy-pos.com` | Yes | Yes | Yes | `http://127.0.0.1:8000/app/login` | `http://127.0.0.1:8000/app/admin/dashboard` |

- **Password Source**: Both accounts were created by the local seeder `database/seeders/DefaultUserSeeder.php` (lines 36 and 57) and have not been modified or reset. Refer to `database/seeders/DefaultUserSeeder.php` for the local development password.
- **Note**: These are local development seeder accounts only. Never use seeded passwords on staging or production.

## 2. Direct Browser URLs

- **Landing Page**: `http://127.0.0.1:8000/`
- **Login / React SPA**: `http://127.0.0.1:8000/app/login` (or `http://127.0.0.1:8000/app`)
- **POS Screen (after logging in as Store Admin)**: `http://127.0.0.1:8000/app/user/pos`

## 3. Creating an Additional Local Test Account Safely (Optional)

You can either register a new tenant directly at `http://127.0.0.1:8000/app/register`, or create a verified local Store Admin via Tinker inside the running Docker container without touching existing users:

```bash
docker exec -it digital_inventory_pos_app php artisan tinker
```

```php
$user = App\Models\User::create([
    'first_name' => 'QA',
    'last_name' => 'Tester',
    'email' => 'qa.local@example.com',
    'password' => Illuminate\Support\Facades\Hash::make('ChooseLocalTestPass123!'),
    'email_verified_at' => now(),
    'status' => 1,
]);
$user->assignRole(App\Models\Role::ADMIN);
$store = App\Models\Store::create(['name' => 'QA Store', 'user_id' => $user->id]);
$tenant = App\Models\MultiTenant::create(['store_id' => $store->id]);
$store->update(['tenant_id' => $tenant->id]);
$user->update(['tenant_id' => $tenant->id]);
```

## 4. Stopping or Restarting the Local Server Later

```bash
# Stop containers (preserves MySQL data volume)
docker compose -f docker-compose.local.yml stop

# Start again later
docker compose -f docker-compose.local.yml up -d
```
