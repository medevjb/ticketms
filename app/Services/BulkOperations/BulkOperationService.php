<?php

namespace App\Services\BulkOperations;

use App\Models\User;
use App\Services\Authorization\EffectivePermissionService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BulkOperationService
{
    public function __construct(private readonly EffectivePermissionService $permissions) {}

    /**
     * Process each selected record in its own transaction and return this request's results.
     * Bulk run state is intentionally not persisted.
     *
     * @param  list<int>  $ids
     * @param  Closure(int): void  $apply
     * @return array{succeeded: int, failed: list<array{id: int, message: string}>}
     */
    public function execute(User $actor, string $permission, array $ids, Closure $apply): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        if ($ids === [] || count($ids) > 100) {
            throw ValidationException::withMessages(['selected_ids' => 'Select between 1 and 100 distinct records.']);
        }

        $succeeded = 0;
        $failed = [];

        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($actor, $permission, $id, $apply): void {
                    $this->lockAndAuthorize($actor, $permission);
                    $apply($id);
                }, attempts: 3);

                $succeeded++;
            } catch (ModelNotFoundException) {
                $failed[] = ['id' => $id, 'message' => 'The record is unavailable.'];
            } catch (ValidationException) {
                $failed[] = ['id' => $id, 'message' => 'A current business rule prevented this action.'];
            } catch (HttpExceptionInterface $exception) {
                if ($exception->getStatusCode() !== 409) {
                    throw $exception;
                }

                $failed[] = ['id' => $id, 'message' => 'The record changed or conflicted with another operation.'];
            } catch (AuthorizationException $exception) {
                throw $exception;
            }
        }

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }

    private function lockAndAuthorize(User $actor, string $permission): void
    {
        try {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new AuthorizationException;
        }

        if (! in_array('admin', $lockedActor->tyroRoleSlugs(), true) || ! $this->permissions->allows($lockedActor, $permission)) {
            throw new AuthorizationException;
        }

    }
}
