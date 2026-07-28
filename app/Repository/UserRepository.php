<?php

namespace App\Repository;

use App\Models\User;

class UserRepository
{
    public function paginate(int $perPage = 15)
    {
        return User::with('roles')->latest()->paginate($perPage);
    }

    public function create(array $payload)
    {
        return User::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return User::with('roles')->where('uuid', $uuid)->firstOrFail();
    }

    public function findByField(string $field, $value)
    {
        return User::where($field, $value)->firstOrFail();
    }

    public function update(string $uuid, array $payload)
    {
        $model = $this->findByUuid($uuid);
        $model->update($payload);

        return $model;
    }

    public function delete(string $uuid)
    {
        $model = $this->findByUuid($uuid);

        return $model->delete();
    }

    public function restore(string $uuid)
    {
        $model = User::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $model->restore();

        return $model;
    }

    /** @param array<int, int> $roleIds */
    public function syncRoles(User $user, array $roleIds): User
    {
        $user->roles()->sync($roleIds);

        return $user->load('roles');
    }
}
