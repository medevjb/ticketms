<?php

namespace App\Services\BulkOperations;

use App\Enums\ActorKind;
use App\Enums\BulkItemResult;
use App\Enums\MessageVisibility;
use App\Models\ActivityEntry;
use App\Models\BulkOperation;
use App\Models\BulkOperationItem;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BulkOperationService
{
    public function __construct(private readonly EffectivePermissionService $permissions) {}

    /**
     * @param  list<int>  $ids
     * @param  Closure(int): void  $apply
     * @param  array<int, int>  $expectedVersions
     * @return array{succeeded: int, failed: list<array{id: int, message: string}>}
     */
    public function execute(User $actor, string $permission, string $module, string $subjectType, string $action, string $operationKey, array $ids, Closure $apply, array $expectedVersions = []): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if ($ids === [] || count($ids) > 100) {
            throw ValidationException::withMessages(['selected_ids' => 'Select between 1 and 100 distinct records.']);
        }

        ksort($expectedVersions);
        $requestHash = hash('sha256', json_encode([$module, $subjectType, $action, $ids, $expectedVersions], JSON_THROW_ON_ERROR));
        $operation = DB::transaction(function () use ($actor, $permission, $module, $subjectType, $action, $operationKey, $ids, $expectedVersions, $requestHash): BulkOperation {
            $lockedActor = $this->lockAndAuthorize($actor, $permission);
            $existing = BulkOperation::query()->where('operation_key', $operationKey)->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->actor_id !== (int) $lockedActor->getKey()
                    || $existing->module !== $module
                    || $existing->action !== $action
                    || ($existing->summary['request_hash'] ?? null) !== $requestHash) {
                    abort(409, 'This bulk operation key was already used for a different request.');
                }

                return $existing;
            }

            $operation = BulkOperation::query()->forceCreate([
                'operation_key' => $operationKey,
                'actor_id' => $lockedActor->getKey(),
                'module' => $module,
                'action' => $action,
                'submitted_count' => count($ids),
                'succeeded_count' => 0,
                'failed_count' => 0,
                'started_at' => CarbonImmutable::now('UTC'),
                'summary' => ['request_hash' => $requestHash],
            ]);
            foreach ($ids as $id) {
                BulkOperationItem::query()->forceCreate([
                    'bulk_operation_id' => $operation->getKey(),
                    'subject_type' => $subjectType,
                    'subject_id' => $id,
                    'expected_version' => $expectedVersions[$id] ?? null,
                    'result' => BulkItemResult::Pending,
                ]);
            }
            $this->recordActivity($operation, $lockedActor, 'bulk_operation.started', ['module' => $module, 'action' => $action, 'submitted_count' => count($ids)]);

            return $operation;
        }, attempts: 3);

        foreach ($operation->items()->where('result', BulkItemResult::Pending)->orderBy('subject_id')->get() as $item) {
            try {
                DB::transaction(function () use ($actor, $permission, $operation, $item, $apply): void {
                    $lockedActor = $this->lockAndAuthorize($actor, $permission);
                    BulkOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
                    $lockedItem = BulkOperationItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
                    if ($lockedItem->result !== BulkItemResult::Pending) {
                        return;
                    }

                    $apply((int) $lockedItem->subject_id);
                    $lockedItem->forceFill(['result' => BulkItemResult::Succeeded, 'error_code' => null])->save();
                    $this->refreshCounts($operation, $lockedActor);
                }, attempts: 3);
            } catch (ModelNotFoundException) {
                $this->finishItem($operation, $item, BulkItemResult::Unavailable, 'unavailable', $actor, $permission);
            } catch (ValidationException) {
                $this->finishItem($operation, $item, BulkItemResult::Failed, 'business_rule', $actor, $permission);
            } catch (HttpExceptionInterface $exception) {
                if ($exception->getStatusCode() !== 409) {
                    throw $exception;
                }
                $this->finishItem($operation, $item, BulkItemResult::Conflict, 'conflict', $actor, $permission);
            } catch (AuthorizationException $exception) {
                throw $exception;
            }
        }

        $operation->refresh();
        $results = $operation->items()->orderBy('subject_id')->get(['subject_id', 'result', 'error_code']);
        $failed = [];
        foreach ($results as $result) {
            if ($result->result === BulkItemResult::Succeeded) {
                continue;
            }
            $failed[] = [
                'id' => (int) $result->subject_id,
                'message' => match ($result->error_code) {
                    'unavailable' => 'The record is unavailable.',
                    'business_rule' => 'A current business rule prevented this action.',
                    'conflict' => 'The record changed or conflicted with another operation.',
                    default => 'The action has not completed and may be retried with the same operation key.',
                },
            ];
        }

        return ['succeeded' => (int) $operation->succeeded_count, 'failed' => $failed];
    }

    private function lockAndAuthorize(User $actor, string $permission): User
    {
        $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
        if (! in_array('admin', $lockedActor->tyroRoleSlugs(), true) || ! $this->permissions->allows($lockedActor, $permission)) {
            throw new AuthorizationException;
        }

        return $lockedActor;
    }

    private function finishItem(BulkOperation $operation, BulkOperationItem $item, BulkItemResult $result, string $errorCode, User $actor, string $permission): void
    {
        DB::transaction(function () use ($operation, $item, $result, $errorCode, $actor, $permission): void {
            $lockedActor = $this->lockAndAuthorize($actor, $permission);
            BulkOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            $lockedItem = BulkOperationItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedItem->result !== BulkItemResult::Pending) {
                return;
            }
            $lockedItem->forceFill(['result' => $result, 'error_code' => $errorCode])->save();
            $this->refreshCounts($operation, $lockedActor);
        }, attempts: 3);
    }

    private function refreshCounts(BulkOperation $operation, User $actor): void
    {
        $lockedOperation = BulkOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
        $succeeded = $lockedOperation->items()->where('result', BulkItemResult::Succeeded)->count();
        $failed = $lockedOperation->items()->whereIn('result', [BulkItemResult::Failed, BulkItemResult::Conflict, BulkItemResult::Unavailable])->count();
        $finished = $succeeded + $failed === (int) $lockedOperation->submitted_count;
        $lockedOperation->forceFill([
            'succeeded_count' => $succeeded,
            'failed_count' => $failed,
            'completed_at' => $finished ? CarbonImmutable::now('UTC') : null,
            'summary' => array_merge($lockedOperation->summary ?? [], ['succeeded' => $succeeded, 'failed' => $failed]),
        ])->save();
        if ($finished) {
            $this->recordActivity($lockedOperation, $actor, 'bulk_operation.completed', ['succeeded' => $succeeded, 'failed' => $failed]);
        }
    }

    /** @param array<string, mixed> $changes */
    private function recordActivity(BulkOperation $operation, User $actor, string $action, array $changes): void
    {
        ActivityEntry::query()->forceCreate([
            'ticket_id' => null,
            'actor_id' => $actor->getKey(),
            'actor_kind' => ActorKind::User,
            'action' => $action,
            'visibility' => MessageVisibility::Internal,
            'subject_type' => BulkOperation::class,
            'subject_id' => $operation->getKey(),
            'operation_key' => $operation->operation_key,
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => CarbonImmutable::now('UTC'),
            'changes' => $changes,
            'metadata' => null,
        ]);
    }
}
