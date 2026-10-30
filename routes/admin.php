<?php

use App\Http\Controllers\Admin\AccessManagementController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\CalendarController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\PriorityController;
use App\Http\Controllers\Admin\SlaPolicyController;
use App\Http\Controllers\Admin\TicketAssignmentController;
use App\Http\Controllers\Admin\TicketAttachmentController;
use App\Http\Controllers\Admin\TicketController;
use App\Http\Controllers\Admin\TicketConversationController;
use App\Http\Middleware\EnsureInternalDashboardAccess;
use Illuminate\Support\Facades\Route;

Route::prefix(trim((string) config('tyro-dashboard.routes.prefix', 'dashboard'), '/'))
    ->middleware(['auth', EnsureInternalDashboardAccess::class])
    ->name('dashboard.')
    ->group(function (): void {
        Route::middleware('can:notifications.view')->group(function (): void {
            Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
            Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
                ->whereUuid('notification')->name('notifications.read');
        });
        Route::get('/analytics', [AnalyticsController::class, 'index'])
            ->middleware('can:analytics.view')->name('analytics.index');
        Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
        Route::get('/tickets/create', [TicketController::class, 'create'])->name('tickets.create');
        Route::post('/tickets', [TicketController::class, 'store'])
            ->middleware('throttle:ticket-create')->name('tickets.store');
        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->whereNumber('ticket')->name('tickets.show');
        Route::get('/tickets/{ticket}/edit', [TicketController::class, 'edit'])->whereNumber('ticket')->name('tickets.edit');
        Route::put('/tickets/{ticket}', [TicketController::class, 'update'])->whereNumber('ticket')->name('tickets.update');
        Route::patch('/tickets/{ticket}/transition', [TicketController::class, 'transition'])->whereNumber('ticket')->name('tickets.transition');
        Route::patch('/tickets/{ticket}/priority', [TicketController::class, 'changePriority'])->whereNumber('ticket')->name('tickets.priority');
        Route::patch('/tickets/{ticket}/assignment', [TicketAssignmentController::class, 'assign'])->whereNumber('ticket')->name('tickets.assignment');
        Route::patch('/tickets/{ticket}/claim', [TicketAssignmentController::class, 'claim'])->whereNumber('ticket')->name('tickets.claim');
        Route::post('/tickets/{ticket}/messages', [TicketConversationController::class, 'store'])
            ->whereNumber('ticket')->middleware('throttle:ticket-replies')->name('tickets.messages.store');
        Route::post('/tickets/{ticket}/messages/{message}/moderate', [TicketConversationController::class, 'moderate'])
            ->whereNumber('ticket')->whereNumber('message')->name('tickets.messages.moderate');
        Route::get('/ticket-attachments/{attachment}/download', [TicketAttachmentController::class, 'download'])
            ->whereNumber('attachment')->middleware('throttle:ticket-downloads')->name('ticket-attachments.download');
        Route::post('/tickets/{ticket}/attachments/{attachment}/moderate', [TicketAttachmentController::class, 'moderate'])
            ->whereNumber('ticket')->whereNumber('attachment')->name('tickets.attachments.moderate');
    });

Route::prefix(trim((string) config('tyro-dashboard.routes.prefix', 'dashboard'), '/'))
    ->middleware(['auth', 'tyro-dashboard.admin'])
    ->name('dashboard.')
    ->group(function (): void {
        Route::middleware('can:agents.manage')->group(function (): void {
            Route::get('/ticket-agents', [TicketAssignmentController::class, 'agents'])->name('ticket-agents.index');
            Route::patch('/ticket-agents/{agent}/automatic-assignment', [TicketAssignmentController::class, 'setAutoAssignment'])
                ->whereNumber('agent')->name('ticket-agents.auto-assignment');
        });

        Route::middleware('can:tickets.bulk')->post('/tickets/bulk', [TicketController::class, 'bulk'])->name('tickets.bulk');

        Route::middleware('can:users.manage')->group(function (): void {
            Route::get('/access/users', [AccessManagementController::class, 'users'])->name('access.users');
            Route::post('/access/users/bulk', [AccessManagementController::class, 'bulkUsers'])->name('access.users.bulk');
        });

        Route::middleware('can:roles.manage')->group(function (): void {
            Route::get('/access/roles', [AccessManagementController::class, 'roles'])->name('access.roles');
            Route::post('/access/roles/bulk', [AccessManagementController::class, 'bulkRoles'])->name('access.roles.bulk');
        });

        Route::middleware('can:categories.manage')->group(function (): void {
            Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
            Route::get('/categories/create', [CategoryController::class, 'create'])->name('categories.create');
            Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
            Route::post('/categories/bulk', [CategoryController::class, 'bulk'])->name('categories.bulk');
            Route::patch('/categories/{category}/availability', [CategoryController::class, 'availability'])->name('categories.availability');
            Route::post('/categories/{category}/archive', [CategoryController::class, 'archive'])->name('categories.archive');
            Route::post('/categories/{category}/restore', [CategoryController::class, 'restore'])->name('categories.restore');
            Route::get('/categories/{category}/edit', [CategoryController::class, 'edit'])->name('categories.edit');
            Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        });

        Route::middleware('can:priorities.manage')->group(function (): void {
            Route::get('/priorities', [PriorityController::class, 'index'])->name('priorities.index');
            Route::get('/priorities/create', [PriorityController::class, 'create'])->name('priorities.create');
            Route::post('/priorities', [PriorityController::class, 'store'])->name('priorities.store');
            Route::post('/priorities/bulk', [PriorityController::class, 'bulk'])->name('priorities.bulk');
            Route::patch('/priorities/{priority}/availability', [PriorityController::class, 'availability'])->name('priorities.availability');
            Route::post('/priorities/{priority}/archive', [PriorityController::class, 'archive'])->name('priorities.archive');
            Route::post('/priorities/{priority}/restore', [PriorityController::class, 'restore'])->name('priorities.restore');
            Route::get('/priorities/{priority}/edit', [PriorityController::class, 'edit'])->name('priorities.edit');
            Route::put('/priorities/{priority}', [PriorityController::class, 'update'])->name('priorities.update');
        });

        Route::middleware('can:calendars.manage')->group(function (): void {
            Route::get('/calendars', [CalendarController::class, 'index'])->name('calendars.index');
            Route::post('/calendars/clone', [CalendarController::class, 'clone'])->name('calendars.clone');
            Route::post('/calendars/bulk', [CalendarController::class, 'bulk'])->name('calendars.bulk');
            Route::put('/calendars/{calendar}', [CalendarController::class, 'update'])->name('calendars.update');
            Route::get('/calendars/{calendar}/edit', [CalendarController::class, 'edit'])->name('calendars.edit');
            Route::post('/calendars/{calendar}/publish', [CalendarController::class, 'publish'])->name('calendars.publish');
            Route::patch('/calendars/{calendar}/availability', [CalendarController::class, 'availability'])->name('calendars.availability');
            Route::post('/calendars/{calendar}/archive', [CalendarController::class, 'archive'])->name('calendars.archive');
            Route::post('/calendars/{calendar}/restore', [CalendarController::class, 'restore'])->name('calendars.restore');
        });

        Route::middleware('can:sla.manage')->group(function (): void {
            Route::get('/sla-policies', [SlaPolicyController::class, 'index'])->name('sla-policies.index');
            Route::post('/sla-policies/clone', [SlaPolicyController::class, 'clone'])->name('sla-policies.clone');
            Route::post('/sla-policies/bulk', [SlaPolicyController::class, 'bulk'])->name('sla-policies.bulk');
            Route::put('/sla-policies/{policy}', [SlaPolicyController::class, 'update'])->name('sla-policies.update');
            Route::get('/sla-policies/{policy}/edit', [SlaPolicyController::class, 'edit'])->name('sla-policies.edit');
            Route::post('/sla-policies/{policy}/publish', [SlaPolicyController::class, 'publish'])->name('sla-policies.publish');
            Route::patch('/sla-policies/{policy}/availability', [SlaPolicyController::class, 'availability'])->name('sla-policies.availability');
            Route::post('/sla-policies/{policy}/archive', [SlaPolicyController::class, 'archive'])->name('sla-policies.archive');
            Route::post('/sla-policies/{policy}/restore', [SlaPolicyController::class, 'restore'])->name('sla-policies.restore');
        });

        Route::middleware('can:imports.manage')->group(function (): void {
            Route::get('/ticket-imports', [ImportController::class, 'index'])->name('ticket-imports.index');
            Route::post('/ticket-imports', [ImportController::class, 'store'])
                ->middleware('throttle:ticket-imports')->name('ticket-imports.store');
            Route::get('/ticket-imports/{run}', [ImportController::class, 'show'])->whereNumber('run')->name('ticket-imports.show');
            Route::post('/ticket-imports/{run}/control', [ImportController::class, 'control'])->whereNumber('run')->name('ticket-imports.control');
            Route::get('/ticket-imports/{run}/errors', [ImportController::class, 'errors'])
                ->whereNumber('run')->middleware(['can:imports.download_errors', 'throttle:ticket-downloads'])->name('ticket-imports.errors');
        });
    });
