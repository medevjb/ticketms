<?php

namespace App\Services\Authorization;

use App\Enums\LifecycleBulkAction;
use App\Models\Role;
use App\Models\User;
use App\Services\BulkOperations\BulkOperationService;

class AccessLifecycleBulkService
{
    public function __construct(
        private readonly BulkOperationService $operations,
        private readonly UserLifecycleService $users,
        private readonly RoleLifecycleService $roles,
    ) {}

    /**
     * @param  list<int>  $ids
     * @return array{succeeded: int, failed: list<array{id: int, message: string}>}
     */
    public function users(User $actor, LifecycleBulkAction $action, string $operationKey, array $ids): array
    {
        return $this->operations->execute(
            $actor,
            'users.manage',
            'users',
            User::class,
            $action->value,
            $operationKey,
            $ids,
            function (int $id) use ($actor, $action): void {
                $target = User::withTrashed()->findOrFail($id);
                match ($action) {
                    LifecycleBulkAction::Enable => $this->users->setActive($target, true, $actor),
                    LifecycleBulkAction::Disable => $this->users->setActive($target, false, $actor),
                    LifecycleBulkAction::Archive => $this->users->archive($target, $actor),
                    LifecycleBulkAction::Restore => $this->users->restore($target, $actor),
                };
            },
        );
    }

    /**
     * @param  list<int>  $ids
     * @return array{succeeded: int, failed: list<array{id: int, message: string}>}
     */
    public function roles(User $actor, LifecycleBulkAction $action, string $operationKey, array $ids): array
    {
        return $this->operations->execute(
            $actor,
            'roles.manage',
            'roles',
            Role::class,
            $action->value,
            $operationKey,
            $ids,
            function (int $id) use ($actor, $action): void {
                $target = Role::withTrashed()->findOrFail($id);
                match ($action) {
                    LifecycleBulkAction::Enable => $this->roles->setActive($target, true, $actor),
                    LifecycleBulkAction::Disable => $this->roles->setActive($target, false, $actor),
                    LifecycleBulkAction::Archive => $this->roles->archive($target, $actor),
                    LifecycleBulkAction::Restore => $this->roles->restore($target, $actor),
                };
            },
        );
    }
}
