<?php

namespace App\Service;

use App\Models\Role;
use App\Repository\UserRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserService
{
    public function __construct(private readonly UserRepository $userRepository) {}

    public function listUser(int $perPage): LengthAwarePaginator
    {
        return $this->userRepository->paginate($perPage);
    }

    /** @param array<string, mixed> $payload */
    public function createUser(array $payload): array
    {
        return DB::transaction(function () use ($payload): array {
            $roles = $this->rolesFromPayload($payload);
            unset($payload['roles']);
            $payload['uuid'] = (string) Str::uuid();

            $user = $this->userRepository->create($payload);
            $this->userRepository->syncRoles($user, $roles->pluck('id')->all());

            return $this->formatUser($user);
        });
    }

    public function getUser(string $uuid): array
    {
        return $this->formatUser($this->userRepository->findByUuid($uuid));
    }

    /** @param array<string, mixed> $payload */
    public function updateUser(string $uuid, array $payload): array
    {
        return DB::transaction(function () use ($uuid, $payload): array {
            $roles = array_key_exists('roles', $payload) ? $this->rolesFromPayload($payload) : null;
            unset($payload['roles']);

            $user = $this->userRepository->update($uuid, $payload);
            if ($roles !== null) {
                $this->userRepository->syncRoles($user, $roles->pluck('id')->all());
            }

            return $this->formatUser($user->load('roles'));
        });
    }

    public function deleteUser(string $uuid): void
    {
        $this->userRepository->delete($uuid);
    }

    public function restoreUser(string $uuid): array
    {
        return $this->formatUser($this->userRepository->restore($uuid)->load('roles'));
    }

    /** @param array<string, mixed> $payload */
    private function rolesFromPayload(array $payload)
    {
        return Role::query()->whereIn('name', $payload['roles'] ?? [])->get();
    }

    private function formatUser($user): array
    {
        return [
            'uuid' => $user->uuid,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'username' => $user->username,
            'account_status' => $user->account_status,
            'activated_at' => $user->activated_at?->toISOString(),
            'roles' => $user->roles->pluck('name')->values()->all(),
        ];
    }
}
