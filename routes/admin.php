<?php

use App\Http\Controllers\Admin\AccessManagementController;
use App\Http\Controllers\Admin\AgentManagementController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\CalendarController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\PriorityController;
use App\Http\Controllers\Admin\SlaPolicyController;
use App\Http\Controllers\Admin\TicketAssignmentController;
use App\Http\Controllers\Admin\TicketAttachmentController;
use App\Http\Controllers\Admin\TicketController;
use App\Http\Controllers\Admin\TicketConversationController;
use App\Http\Controllers\Admin\TimezoneSettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Middleware\EnsureInternalDashboardAccess;
use HasinHayder\TyroDashboard\Support\DashboardRoute;
use Illuminate\Support\Facades\Route;

Route::get('/'.trim((string) config('tyro-dashboard.routes.prefix', 'dashboard'), '/'), [DashboardController::class, 'index'])
    ->middleware(['auth', EnsureInternalDashboardAccess::class])
    ->name(DashboardRoute::name('index'));

Route::prefix(trim((string) config('tyro-dashboard.routes.prefix', 'dashboard'), '/'))
    ->middleware(['auth', EnsureInternalDashboardAccess::class])
    ->name('dashboard.')
    ->group(function (): void {
        Route::middleware('can:tickets.view')->group(function (): void {
            Route::get('/people', [UserController::class, 'index'])->name('users.index');
            Route::middleware(['tyro-dashboard.admin', 'can:users.manage'])->group(function (): void {
                Route::get('/people/create', [UserController::class, 'create'])->name('users.create');
                Route::post('/people', [UserController::class, 'store'])->name('users.store');
                Route::post('/people/bulk', [AccessManagementController::class, 'bulkUsers'])->name('users.bulk');
                Route::get('/people/{user}/edit', [UserController::class, 'edit'])->whereNumber('user')->name('users.edit');
                Route::put('/people/{user}', [UserController::class, 'update'])->whereNumber('user')->name('users.update');
            });
            Route::get('/people/{user}', [UserController::class, 'show'])->whereNumber('user')->name('users.show');
        });
        Route::middleware('can:notifications.view')->group(function (): void {
            Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
            Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
                ->whereUuid('notification')->name('notifications.read');
        });
        Route::get('/analytics', [AnalyticsController::class, 'index'])
            ->middleware('can:analytics.view')->name('analytics.index');
        Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
        Route::get('/tickets/data', [TicketController::class, 'data'])->name('tickets.data');
        Route::get('/tickets/create', [TicketController::class, 'create'])->name('tickets.create');
        Route::post('/tickets', [TicketController::class, 'store'])
            ->middleware('throttle:ticket-create')->name('tickets.store');
        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->whereNumber('ticket')->name('tickets.show');
        Route::get('/tickets/{ticket}/sla', [TicketController::class, 'sla'])->whereNumber('ticket')->name('tickets.sla');
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
        Route::get('/ticket-attachments/{attachment}/preview', [TicketAttachmentController::class, 'preview'])
            ->whereNumber('attachment')->middleware('throttle:ticket-downloads')->name('ticket-attachments.preview');
        Route::get('/ticket-attachments/{attachment}/download', [TicketAttachmentController::class, 'download'])
            ->whereNumber('attachment')->middleware('throttle:ticket-downloads')->name('ticket-attachments.download');
        Route::post('/tickets/{ticket}/attachments/{attachment}/moderate', [TicketAttachmentController::class, 'moderate'])
            ->whereNumber('ticket')->whereNumber('attachment')->name('tickets.attachments.moderate');
    });

Route::prefix(trim((string) config('tyro-dashboard.routes.prefix', 'dashboard'), '/'))
    ->middleware(['auth', 'tyro-dashboard.admin'])
    ->name('dashboard.')
    ->group(function (): void {
        Route::put('/settings/timezone', [TimezoneSettingsController::class, 'update'])->middleware('can:users.manage')->name('settings.timezone');

        Route::middleware('can:agents.manage')->group(function (): void {
            Route::get('/agents', [AgentManagementController::class, 'index'])->name('agents.index');
            Route::get('/agents/create', [AgentManagementController::class, 'create'])->name('agents.create');
            Route::post('/agents', [AgentManagementController::class, 'store'])->name('agents.store');
            Route::post('/agents/{agent}/profile', [AgentManagementController::class, 'createProfile'])
                ->whereNumber('agent')->name('agents.profile.create');
            Route::post('/agents/{agent}/invitation', [AgentManagementController::class, 'resendInvitation'])
                ->whereNumber('agent')->name('agents.invitation.resend');
            Route::patch('/agents/{agent}/availability', [AgentManagementController::class, 'setActive'])
                ->whereNumber('agent')->name('agents.availability');
        });

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
            Route::get('/business-calendar', [CalendarController::class, 'index'])->name('calendars.index');
            Route::put('/business-calendar', [CalendarController::class, 'update'])->name('calendars.update');
        });

        Route::middleware('can:sla.manage')->group(function (): void {
            Route::get('/sla-policy', [SlaPolicyController::class, 'index'])->name('sla-policies.index');
            Route::put('/sla-policy', [SlaPolicyController::class, 'update'])->name('sla-policies.update');
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
