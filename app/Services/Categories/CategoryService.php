<?php

namespace App\Services\Categories;

use App\Enums\ActorKind;
use App\Enums\CategoryBulkAction;
use App\Enums\MessageVisibility;
use App\Models\ActivityEntry;
use App\Models\Category;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionService;
use App\Services\BulkOperations\BulkOperationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategoryService
{
    public function __construct(
        private readonly EffectivePermissionService $permissions,
        private readonly BulkOperationService $bulkOperations,
    ) {}

    /** @param array{search?: string|null, status?: string|null} $filters
     * @return LengthAwarePaginator<int, Category>
     */
    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        $this->authorizeManagement($actor);

        $status = in_array($filters['status'] ?? 'active', ['all', 'active', 'inactive', 'archived'], true)
            ? ($filters['status'] ?? 'active')
            : 'active';
        $query = Category::query();

        if ($status === 'all' || $status === 'archived') {
            $query->withTrashed();
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        } elseif ($status === 'archived') {
            $query->onlyTrashed();
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('name')->orderBy('id')->paginate(15);
    }

    public function create(User $actor, string $name, string $slug, ?string $description): Category
    {
        $this->authorizeManagement($actor);

        return DB::transaction(function () use ($actor, $name, $slug, $description): Category {
            $this->lockAndAuthorizeActor($actor);
            $category = Category::query()->create([
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
            ]);
            $category->forceFill(['created_by' => $actor->getKey()])->save();
            $this->recordActivity($category, $actor, 'category.created', [
                'name' => $category->name,
                'slug' => $category->slug,
                'is_active' => $category->is_active,
            ]);

            return $category;
        }, attempts: 3);
    }

    public function update(Category $category, User $actor, string $name, ?string $description): Category
    {
        $this->authorizeManagement($actor);

        return DB::transaction(function () use ($category, $actor, $name, $description): Category {
            $this->lockAndAuthorizeActor($actor);
            $lockedCategory = Category::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();
            $before = ['name' => $lockedCategory->name, 'description' => $lockedCategory->description];
            $lockedCategory->fill(['name' => $name, 'description' => $description]);
            $lockedCategory->forceFill(['updated_by' => $actor->getKey()])->save();

            if ($lockedCategory->wasChanged(['name', 'description'])) {
                $this->recordActivity($lockedCategory, $actor, 'category.updated', [
                    'before' => $before,
                    'after' => ['name' => $lockedCategory->name, 'description' => $lockedCategory->description],
                ]);
            }

            return $lockedCategory;
        }, attempts: 3);
    }

    public function setActive(Category $category, bool $isActive, User $actor): void
    {
        $this->authorizeManagement($actor);

        DB::transaction(function () use ($category, $isActive, $actor): void {
            $this->lockAndAuthorizeActor($actor);
            $lockedCategory = Category::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedCategory->is_active === $isActive) {
                return;
            }

            $lockedCategory->forceFill(['is_active' => $isActive, 'updated_by' => $actor->getKey()])->save();
            $this->recordActivity($lockedCategory, $actor, $isActive ? 'category.enabled' : 'category.disabled', [
                'is_active' => $isActive,
            ]);
        }, attempts: 3);
    }

    public function archive(Category $category, User $actor): void
    {
        $this->authorizeManagement($actor);

        DB::transaction(function () use ($category, $actor): void {
            $this->lockAndAuthorizeActor($actor);
            $lockedCategory = Category::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();
            $lockedCategory->forceFill([
                'is_active' => false,
                'updated_by' => $actor->getKey(),
                'deleted_by' => $actor->getKey(),
            ])->save();
            $lockedCategory->delete();
            $this->recordActivity($lockedCategory, $actor, 'category.archived', ['is_active' => false]);
        }, attempts: 3);
    }

    public function restore(int $categoryId, User $actor): void
    {
        $this->authorizeManagement($actor);

        DB::transaction(function () use ($categoryId, $actor): void {
            $this->lockAndAuthorizeActor($actor);
            $category = Category::withTrashed()->whereKey($categoryId)->lockForUpdate()->firstOrFail();
            if (! $category->trashed()) {
                return;
            }

            $category->restore();
            $category->forceFill([
                'is_active' => false,
                'updated_by' => $actor->getKey(),
                'deleted_by' => null,
            ])->save();
            $this->recordActivity($category, $actor, 'category.restored', ['is_active' => false]);
        }, attempts: 3);
    }

    /** @param list<int> $categoryIds
     * @return array{succeeded: int, failed: list<array{id: int, message: string}>}
     */
    public function bulk(User $actor, CategoryBulkAction $action, array $categoryIds, string $operationKey): array
    {
        $this->authorizeManagement($actor);

        return $this->bulkOperations->execute(
            $actor,
            'categories.manage',
            'categories',
            Category::class,
            $action->value,
            $operationKey,
            $categoryIds,
            function (int $categoryId) use ($action, $actor): void {
                $category = Category::withTrashed()->findOrFail($categoryId);
                match ($action) {
                    CategoryBulkAction::Enable => $this->setActive($category, true, $actor),
                    CategoryBulkAction::Disable => $this->setActive($category, false, $actor),
                    CategoryBulkAction::Archive => $this->archive($category, $actor),
                    CategoryBulkAction::Restore => $this->restore($categoryId, $actor),
                };
            },
        );
    }

    private function lockAndAuthorizeActor(User $actor): User
    {
        $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
        $this->authorizeManagement($lockedActor);

        return $lockedActor;
    }

    private function authorizeManagement(User $actor): void
    {
        $roles = $actor->tyroRoleSlugs();
        $isAdministrator = in_array('admin', $roles, true);

        if (! $isAdministrator || ! $this->permissions->allows($actor, 'categories.manage')) {
            throw new AuthorizationException;
        }
    }

    /** @param array<string, mixed> $changes */
    private function recordActivity(Category $category, User $actor, string $action, array $changes): void
    {
        ActivityEntry::query()->forceCreate([
            'ticket_id' => null,
            'actor_id' => $actor->getKey(),
            'actor_kind' => ActorKind::User,
            'action' => $action,
            'visibility' => MessageVisibility::Internal,
            'subject_type' => Category::class,
            'subject_id' => $category->getKey(),
            'operation_key' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
            'changes' => $changes,
            'metadata' => null,
        ]);
    }
}
