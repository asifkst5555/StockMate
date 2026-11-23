<?php

namespace App\Repositories;

use App\Models\MultiTenant;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStore;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Class UserRepository
 */
class UserRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'created_at',
        //        'roles.name',
    ];

    /**
     * Return searchable fields
     */
    public function getFieldsSearchable(): array
    {
        return $this->fieldSearchable;
    }

    /**
     * Configure the Model
     **/
    public function model()
    {
        return User::class;
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Support\Collection|mixed
     */
    public function storeUser($input)
    {
        try {
            DB::beginTransaction();
            $safeUserData = [
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                'email' => $input['email'],
                'phone' => $input['phone'],
                'region' => $input['region'] ?? null,
                'password' => Hash::make($input['password']),
                'email_verified_at' => now(),
                'status' => 1,
            ];
            $user = $this->create($safeUserData);
            if (isset($input['role_id'])) {
                $user->assignRole((int) $input['role_id']);
            }
            if (isset($input['image']) && ! empty($input['image'])) {
                $user->addMedia($input['image'])->toMediaCollection(
                    User::PATH,
                    config('app.media_disc')
                );
            }

            $storeIds = $input['stores'] ?? [];
            if (is_string($storeIds)) {
                $storeIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $storeIds)))));
            }
            if (! empty($storeIds)) {
                UserStore::where('user_id', $user->id)->delete();
                foreach ($storeIds as $storeId) {
                    UserStore::create([
                        'user_id' => $user->id,
                        'store_id' => (int) $storeId,
                    ]);
                }

                $store = Store::find($storeIds[0]);
                if ($store) {
                    $user->update([
                        'tenant_id' => $store->tenant_id,
                    ]);
                }
            }

            DB::commit();

            return $user;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Support\Collection|mixed
     */
    public function updateUser($input, $id)
    {
        try {
            DB::beginTransaction();
            $user = User::withoutGlobalScope('tenant')->findOrFail($id);
            $safeUserData = array_intersect_key($input, array_flip([
                'first_name',
                'last_name',
                'email',
                'phone',
                'region',
            ]));
            $user->update($safeUserData);

            if (isset($input['role_id'])) {
                $user->syncRoles([(int) $input['role_id']]);
            }
            if (isset($input['image']) && $input['image']) {
                $user->clearMediaCollection(User::PATH);
                $user['image_url'] = $user->addMedia($input['image'])->toMediaCollection(
                    User::PATH,
                    config('app.media_disc')
                );
            }

            $storeIds = $input['stores'] ?? [];
            if (is_string($storeIds)) {
                $storeIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $storeIds)))));
            }
            if (! empty($storeIds)) {
                UserStore::where('user_id', $user->id)->delete();
                foreach ($storeIds as $storeId) {
                    UserStore::create([
                        'user_id' => $user->id,
                        'store_id' => (int) $storeId,
                    ]);
                }

                $currentStoreId = $user->tenant?->store_id;
                if (! $currentStoreId || ! in_array((int) $currentStoreId, $storeIds, true)) {
                    $tenant = MultiTenant::where('store_id', $storeIds[0])->first();
                    if ($tenant) {
                        $user->update([
                            'tenant_id' => $tenant->id,
                        ]);
                    }
                }
            }

            DB::commit();

            return $user;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * @return User|\Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function updateUserProfile($input)
    {
        try {
            DB::beginTransaction();
            // SEC-04: Strictly allowlist safe profile fields only
            $safeProfileData = array_intersect_key($input, array_flip([
                'first_name',
                'last_name',
                'email',
                'phone',
            ]));

            $user = Auth::user();
            $user->update($safeProfileData);

            if (! empty($input['image']) && $input['image'] instanceof UploadedFile && $input['image']->isValid()) {
                $existingMedia = $user->getMedia(User::PATH);
                $extension = strtolower($input['image']->guessExtension() ?: $input['image']->getClientOriginalExtension() ?: 'jpg');
                if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $extension = 'jpg';
                }
                $safeFileName = 'avatar_' . $user->id . '_' . Str::uuid()->toString() . '.' . $extension;

                $newMedia = $user->addMedia($input['image'])
                    ->usingName('user_avatar_' . $user->id)
                    ->usingFileName($safeFileName)
                    ->toMediaCollection(User::PATH, config('app.media_disc'));

                foreach ($existingMedia as $oldMedia) {
                    if ($oldMedia->id !== $newMedia->id) {
                        $oldMedia->delete();
                    }
                }
            }
            DB::commit();

            $user->unsetRelation('media');
            $user->load('media');

            return $user;
        } catch (\Exception $e) {
            DB::rollBack();

            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Support\Collection|mixed
     */
    public function getUsers($perPage)
    {
        $loginUserId = Auth::id();
        $returnAll = request()->get('returnAll') == 'true';

        if (Auth::user()->hasRole(Role::ADMIN)) {
            $userStore = UserStore::where('user_id', $loginUserId)->first();
            $storeUserId = $userStore ? $userStore->store->user_id : $loginUserId;

            $allStoreTenants = Store::where('user_id', $storeUserId)->pluck('tenant_id')->toArray();

            if (!empty($allStoreTenants)) {
                $query = $this->withoutGlobalScope('tenant')
                    ->whereIn('tenant_id', $allStoreTenants);
            } else {
                $query = $this;
            }

            if (!$returnAll) {
                $query->where('id', '!=', $loginUserId);
            }

            if($perPage == 0){
                return $query->get();
            }

            return $query->paginate($perPage);
        }

        // Non-admin users
        $query = $this->whereHas('roles', function ($q) {
            $q->where('name', '!=', Role::ADMIN);
        });

        if (!$returnAll) {
            $query->where('id', '!=', Auth::id());
        }

        return $query->paginate($perPage);
    }

    public function updatePassword(array $input): User
    {
        /** @var User $user */
        $user = Auth::user();
        if (! Hash::check($input['current_password'], $user->password)) {
            throw new UnprocessableEntityHttpException(__('messages.error.current_password_not_match'));
        }
        $input['password'] = Hash::make($input['new_password']);
        $user->update($input);

        return $user;
    }
    public function updateUserPassword(array $input): User
    {
        /** @var User $user */
        $currentLoggedInUser = Auth::user();
        $user = $this->withoutGlobalScope('tenant')->find(id: $input['user_id']);

        if($user->roles[0]->name == Role::SUPER_ADMIN){
            throw new UnprocessableEntityHttpException(__('messages.error.user_cant_updated'));
        }
        if($user->id == $currentLoggedInUser->id){
            throw new UnprocessableEntityHttpException(__('messages.error.user_cant_updated'));
        }
        $user->update([
            'password' => Hash::make($input['password']),
        ]);

        return $user;
    }
}
