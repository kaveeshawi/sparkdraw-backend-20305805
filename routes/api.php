<?php

use App\Http\Controllers\Api\AgencyController;
use App\Http\Controllers\Api\AgencyIntegrationController;
use App\Http\Controllers\Api\AgencyServiceController;
use App\Http\Controllers\Api\AIBridgeController;
use App\Http\Controllers\Api\AiCreditController;
use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomRoleController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DriveController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\HealthScoreController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MeAvailabilityController;
use App\Http\Controllers\Api\MeetingLinkController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\PasswordSetupController;
use App\Http\Controllers\Api\MilestoneController;
use App\Http\Controllers\Api\PortalController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectEventController;
use App\Http\Controllers\Api\RevisionController;
use App\Http\Controllers\Api\RisksController;
use App\Http\Controllers\Api\SentimentController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\CalendarEventController;
use App\Http\Controllers\Api\TimeLogController;
use App\Http\Controllers\Api\TimeOverviewController;
use App\Http\Controllers\Api\UpsellSuggestionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WorkSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sparkdraw API Routes  —  /api/v1/
|--------------------------------------------------------------------------
| Middleware layers (applied innermost to outermost):
|   auth:sanctum    — require valid Sanctum Bearer token
|   agency.scope    — verify user has a non-null agency_id (tenant guard)
|   role:admin      — restrict to admin role only
|   role:admin,pm   — restrict to admin or pm roles
|
| Client-role users are BLOCKED from all internal routes.
| They can ONLY access /portal/* routes (role:client middleware).
*/

Route::prefix('v1')->group(function () {

    // ── PUBLIC (no auth) ────────────────────────────────────────────
    Route::get('portal/{slug}/branding', [PortalController::class, 'publicBranding']);
    Route::get('invoices/{invoice}/payment-success', [InvoiceController::class, 'capturePayment']);
    Route::get('invoices/{invoice}/payment-cancel', [InvoiceController::class, 'paymentCancelled']);
    Route::get('integrations/oauth/{provider}/callback', [AgencyIntegrationController::class, 'oauthCallback']);

    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login',    [AuthController::class, 'login']);
    });

    Route::get('password-setup/validate', [PasswordSetupController::class, 'validate']);
    Route::post('password-setup', [PasswordSetupController::class, 'store']);

    // ── AUTHENTICATED (all roles that have agency context) ──────────
    Route::middleware(['auth:sanctum', 'agency.scope', 'access.active'])->group(function () {

        // Auth utilities
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me',      [AuthController::class, 'me']);

        // Revenue must be registered before invoices/{invoice} to avoid route conflict
        Route::middleware('role:admin')->group(function () {
            Route::get('invoices/revenue', [InvoiceController::class, 'revenue']);
        });

        // ── SHARED: invoices view + pay — admin, pm, client ──
        // `permission:financials.view` further restricts pm (per the configurable roles
        // system) — admin and client bypass it untouched.
        Route::middleware(['role:admin,pm,client', 'permission:financials.view'])->group(function () {
            Route::get('invoices', [InvoiceController::class, 'index']);
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
            Route::post('invoices/{invoice}/pay', [InvoiceController::class, 'createPayment']);
            Route::post('invoices/{invoice}/pay-card', [InvoiceController::class, 'processCardPayment']);
            Route::post('invoices/{invoice}/pay-wise', [InvoiceController::class, 'processWisePayment']);
        });

        // ── SHARED: approval + revision + messages — accessible to admin, pm, member, AND client ──
        // Must be declared BEFORE the role-specific groups to avoid middleware conflict.
        // Controller handles per-role data filtering (clients see only their own).
        Route::middleware('role:admin,pm,member,client')->group(function () {
            Route::get('projects/{project}/approvals', [ApprovalController::class, 'index']);
            Route::get('projects/{project}/revisions',  [RevisionController::class, 'index']);
            Route::get('messages/unread',                 [MessageController::class, 'unreadSummary']);
            Route::get('projects/{project}/messages',     [MessageController::class, 'index']);
            Route::post('projects/{project}/messages',    [MessageController::class, 'store']);
            Route::post('projects/{project}/messages/read', [MessageController::class, 'markRead']);
        });

        // ── CLIENT PORTAL (client role only) ────────────────────────
        // Clients cannot access any route outside this group.
        Route::middleware('role:client')->group(function () {
            Route::prefix('portal')->group(function () {
                // Placeholder — portal controllers added in Session 2 (Client Portal module)
                Route::get('/', fn () => response()->json(['success' => true, 'data' => ['portal' => 'active']]));
                Route::get('{slug}/branding', [PortalController::class, 'branding']);
                Route::get('{slug}/projects', [PortalController::class, 'projects']);
                Route::get('{slug}/projects/{project}/progress', [PortalController::class, 'progress']);
                Route::get('{slug}/projects/{project}/team', [PortalController::class, 'team']);
                Route::get('{slug}/projects/{project}/upsells', [PortalController::class, 'upsells']);
                Route::patch('{slug}/projects/{project}/upsells/{upsell}/accept', [PortalController::class, 'acceptUpsell']);
                Route::patch('{slug}/projects/{project}/upsells/{upsell}/decline', [PortalController::class, 'declineUpsell']);
                Route::get('{slug}/invoices', [PortalController::class, 'invoices']);
                Route::get('{slug}/assets', [PortalController::class, 'assets']);
                Route::post('{slug}/assets', [PortalController::class, 'uploadAsset']);
                Route::get('{slug}/assets/files/{file}/download', [PortalController::class, 'downloadAsset']);
                Route::delete('{slug}/assets/files/{file}', [PortalController::class, 'destroyAsset']);
            });

            // Client: submit feedback (revision) to their project
            Route::post('projects/{project}/revisions', [RevisionController::class, 'store']);

            // Client: approve or reject pending approvals on their project
            Route::patch('projects/{project}/approvals/{approval}/approve', [ApprovalController::class, 'approve']);
            Route::patch('projects/{project}/approvals/{approval}/reject',  [ApprovalController::class, 'reject']);
        });

        // ── INTERNAL AGENCY ROUTES (blocks client role) ─────────────
        // All routes below are inaccessible to client-role users.
        Route::middleware('role:admin,pm,member')->group(function () {

            // ── All internal roles (admin + pm + member) ────────────
            Route::get('projects',           [ProjectController::class, 'index']);
            Route::get('projects/time-summary', [TimeLogController::class, 'agencyWorkload']);
            Route::get('time-overview', [TimeOverviewController::class, 'index']);
            Route::get('time/team-presence', [TimeOverviewController::class, 'teamPresence']);
            Route::get('team', [UserController::class, 'index']);
            Route::get('team/{user}', [UserController::class, 'show']);

            Route::middleware('project.visible')->group(function () {
                Route::get('projects/{project}', [ProjectController::class, 'show']);
                Route::get('projects/{project}/risks', [RisksController::class, 'index']);

                // Milestone reads
                Route::get('projects/{project}/milestones', [MilestoneController::class, 'index']);

                // Task reads
                Route::get('projects/{project}/tasks',        [TaskController::class, 'index']);
                Route::get('projects/{project}/tasks/{task}', [TaskController::class, 'show']);

                // Status update — members can move their own tasks
                Route::patch('projects/{project}/tasks/{task}/status', [TaskController::class, 'updateStatus']);

                // Time log — all internal roles can log and view
                Route::post('projects/{project}/tasks/{task}/time-logs', [TimeLogController::class, 'store']);
                Route::get('projects/{project}/tasks/{task}/time-logs',  [TimeLogController::class, 'index']);

                // Assets — all internal roles
                Route::get('projects/{project}/assets', [AssetController::class, 'index']);
                Route::post('projects/{project}/assets', [AssetController::class, 'store']);

                // Comments — internal only (admin, pm, member)
                Route::get('projects/{project}/tasks/{task}/comments',  [CommentController::class, 'index']);
                Route::post('projects/{project}/tasks/{task}/comments', [CommentController::class, 'store']);
            });

            // Revisions with AI tickets — dashboard AI feedback panel (agency-wide, not project-scoped)
            Route::get('revisions/with-tickets', [RevisionController::class, 'withTickets']);
            // Revisions — agency-wide feed across all projects (Revisions nav page)
            Route::get('revisions', [RevisionController::class, 'all']);

            // Tasks — agency-wide, across all projects (Tasks nav page)
            Route::get('tasks/productivity', [TaskController::class, 'productivity']);
            Route::get('tasks', [TaskController::class, 'mine']);

            // Calendar events
            Route::get('calendar/events', [CalendarEventController::class, 'index']);
            Route::post('calendar/events', [CalendarEventController::class, 'store']);
            Route::put('calendar/events/{calendarEvent}', [CalendarEventController::class, 'update']);
            Route::delete('calendar/events/{calendarEvent}', [CalendarEventController::class, 'destroy']);

            // Work sessions — clock in/out + availability overrides (all internal roles)
            Route::get('work-sessions/current', [WorkSessionController::class, 'current']);
            Route::post('work-sessions/clock-in', [WorkSessionController::class, 'clockIn']);
            Route::post('work-sessions/clock-out', [WorkSessionController::class, 'clockOut']);
            Route::patch('me/availability', [MeAvailabilityController::class, 'update']);

            // Assets — agency-wide, across all projects (Assets Library nav page)
            Route::get('assets', [AssetController::class, 'all']);

            // Agency Drive (Assets → Project files) — server disk storage
            Route::get('drive', [DriveController::class, 'index']);
            Route::get('drive/files/{file}/download', [DriveController::class, 'download']);
            Route::post('drive/files', [DriveController::class, 'storeFile']);
            Route::put('drive/files/{file}', [DriveController::class, 'updateFile']);
            Route::post('drive/files/{file}/copy', [DriveController::class, 'copyFile']);
            Route::put('drive/files/{file}/content', [DriveController::class, 'replaceContent']);
            Route::post('drive/files/{file}/restore', [DriveController::class, 'restoreFile']);
            Route::delete('drive/files/{file}/force', [DriveController::class, 'forceDestroyFile']);
            Route::delete('drive/files/{file}', [DriveController::class, 'destroyFile']);
            Route::post('drive/folders', [DriveController::class, 'storeFolder']);
            Route::put('drive/folders/{folder}', [DriveController::class, 'updateFolder']);
            Route::post('drive/folders/{folder}/trash', [DriveController::class, 'trashFolder']);
            Route::post('drive/folders/{folder}/restore', [DriveController::class, 'restoreFolder']);
            Route::delete('drive/folders/{folder}', [DriveController::class, 'destroyFolder']);

            // AI bridge — Laravel → FastAPI, never called directly from the frontend to OpenAI
            Route::prefix('ai')->group(function () {
                Route::get('credits',           [AiCreditController::class, 'index']);
                Route::post('analyze-feedback', [AIBridgeController::class, 'analyzeFeedback']);
                Route::post('sentiment',        [AIBridgeController::class, 'getSentiment']);
                Route::post('health-score',     [AIBridgeController::class, 'getHealthScore']);
                Route::post('upsell',           [AIBridgeController::class, 'getUpsell']);
                Route::post('estimate-hours',   [AIBridgeController::class, 'estimateHours']);
                Route::post('brief-generator',  [AIBridgeController::class, 'generateBrief']);
                Route::post('digest',           [AIBridgeController::class, 'generateDigest']);
                Route::post('invoice-reminder', [AIBridgeController::class, 'invoiceReminder']);
            });

            // Clients — read for all internal roles (member sees limited payload)
            Route::get('clients/count', [ClientController::class, 'count']);
            Route::get('clients', [ClientController::class, 'index']);
            // Must be registered before clients/{client} — otherwise "sentiment" is treated as an id
            Route::get('clients/sentiment', [SentimentController::class, 'clientSentiment'])
                ->middleware('role:admin,pm');
            Route::get('clients/{client}', [ClientController::class, 'show']);

            // ── Admin + PM ───────────────────────────────────────────
            Route::middleware('role:admin,pm')->group(function () {

                // Team management (list is available to all internal roles above; mutations admin only below)
                Route::get('departments', [DepartmentController::class, 'index']);
                Route::get('agency-services', [AgencyServiceController::class, 'index']);
                Route::get('roles', [CustomRoleController::class, 'index']);

                // Agency branding read
                Route::get('agency', [AgencyController::class, 'show']);

                // Project mutations
                Route::post('projects',              [ProjectController::class, 'store']);
                Route::put('projects/{project}',     [ProjectController::class, 'update']);

                // Milestone mutations
                Route::post('projects/{project}/milestones',                          [MilestoneController::class, 'store']);
                Route::put('projects/{project}/milestones/{milestone}',               [MilestoneController::class, 'update']);
                Route::patch('projects/{project}/milestones/{milestone}/complete',    [MilestoneController::class, 'complete']);

                // Task mutations (admin + pm only)
                Route::post('projects/{project}/tasks',              [TaskController::class, 'store']);
                Route::put('projects/{project}/tasks/{task}',        [TaskController::class, 'update']);
                Route::delete('projects/{project}/tasks/{task}',     [TaskController::class, 'destroy']);
                Route::patch('projects/{project}/tasks/{task}/assign', [TaskController::class, 'assign']);

                // Time summary (admin + pm — workload view)
                Route::get('projects/{project}/time-summary', [TimeLogController::class, 'summary']);

                // Revisions — PM updates status; accepts AI ticket (GET is in shared section above)
                Route::put('projects/{project}/revisions/{revision}',                             [RevisionController::class, 'update']);
                Route::post('projects/{project}/revisions/{revision}/accept-ticket',              [RevisionController::class, 'acceptTicket']);

                // Approvals — admin + pm POST new approval request (GET is in shared section above)
                Route::post('projects/{project}/approvals', [ApprovalController::class, 'store']);

                // Health scores — admin + pm
                Route::get('health-scores/agency-average', [HealthScoreController::class, 'agencyAverage']);
                Route::get('health-scores',                [HealthScoreController::class, 'index']);
                Route::get('health-scores/{project}',      [HealthScoreController::class, 'show']);

                // Sentiment summaries — admin + pm
                Route::get('projects/{project}/sentiment', [SentimentController::class, 'projectSentiment']);

                // Client mutations — admin + pm (pm further gated by `clients.manage` permission)
                Route::middleware('permission:clients.manage')->group(function () {
                    Route::put('clients/{client}', [ClientController::class, 'update']);
                    Route::post('clients/{client}/avatar', [ClientController::class, 'uploadAvatar']);
                });

                // Upsell suggestions — admin + pm (read)
                Route::get('upsell-suggestions', [UpsellSuggestionController::class, 'index']);

                // AI alerts feed — admin + pm
                Route::get('project-events/alerts', [ProjectEventController::class, 'alerts']);

                // Invoices — admin + pm
                Route::post('invoices',        [InvoiceController::class, 'store']);
                Route::patch('invoices/{invoice}/send', [InvoiceController::class, 'send']);

                // Asset deliverable toggle — admin + pm
                Route::patch('projects/{project}/assets/{asset}/deliverable', [AssetController::class, 'markDeliverable']);
                Route::delete('projects/{project}/assets/{asset}', [AssetController::class, 'destroy']);
            });

            // ── Admin only ───────────────────────────────────────────
            Route::middleware('role:admin')->group(function () {

                // Soft-delete / archive project — admin only
                Route::delete('projects/{project}', [ProjectController::class, 'destroy']);

                // Agency settings
                Route::put('agency',              [AgencyController::class, 'update']);
                Route::post('agency/upload-logo', [AgencyController::class, 'uploadLogo']);

                // Team mutations
                Route::post('team/invite',                  [UserController::class, 'invite']);
                Route::post('team/{user}/resend-invite',    [UserController::class, 'resendInvite']);
                Route::post('team/{user}/revoke-access',    [UserController::class, 'revokeAccess']);
                Route::post('team/{user}/restore-access',   [UserController::class, 'restoreAccess']);
                Route::put('team/{user}',                    [UserController::class, 'update']);
                Route::patch('team/{user}/availability',     [UserController::class, 'updateAvailability']);
                Route::post('team/{user}/avatar',            [UserController::class, 'uploadAvatar']);
                Route::delete('team/{user}',                [UserController::class, 'destroy']);

                // Client mutations — admin only
                Route::post('clients', [ClientController::class, 'store']);
                Route::delete('clients/{client}', [ClientController::class, 'destroy']);
                Route::post('clients/{client}/resend-invite', [ClientController::class, 'resendInvite']);

                // Departments (mutations)
                Route::post('departments', [DepartmentController::class, 'store']);
                Route::put('departments/{department}', [DepartmentController::class, 'update']);
                Route::delete('departments/{department}', [DepartmentController::class, 'destroy']);

                // Roles & permissions (mutations)
                Route::post('roles', [CustomRoleController::class, 'store']);
                Route::put('roles/{customRole}', [CustomRoleController::class, 'update']);
                Route::delete('roles/{customRole}', [CustomRoleController::class, 'destroy']);

                // Agency service catalog (mutations)
                Route::post('agency-services', [AgencyServiceController::class, 'store']);
                Route::put('agency-services/{agencyService}', [AgencyServiceController::class, 'update']);
                Route::delete('agency-services/{agencyService}', [AgencyServiceController::class, 'destroy']);

                // Manual health score recompute (demo)
                Route::post('health-scores/compute/{project}', [HealthScoreController::class, 'compute']);
                Route::post('health-scores/compute-all',      [HealthScoreController::class, 'computeAll']);

                // Upsell approval — admin only
                Route::patch('upsell-suggestions/{upsellSuggestion}/approve', [UpsellSuggestionController::class, 'approve']);
                Route::patch('upsell-suggestions/{upsellSuggestion}/reject',  [UpsellSuggestionController::class, 'reject']);
                Route::patch('upsell-suggestions/{upsellSuggestion}/send',    [UpsellSuggestionController::class, 'send']);
                Route::patch('upsell-suggestions/{upsellSuggestion}/undo',    [UpsellSuggestionController::class, 'undo']);

                // Invoice update — admin only
                Route::put('invoices/{invoice}', [InvoiceController::class, 'update']);

                // Agency integrations — admin only
                Route::get('integrations', [AgencyIntegrationController::class, 'index']);
                Route::post('integrations/{provider}/connect', [AgencyIntegrationController::class, 'connect']);
                Route::post('integrations/{provider}/disconnect', [AgencyIntegrationController::class, 'disconnect']);
                Route::get('integrations/{provider}/oauth/start', [AgencyIntegrationController::class, 'oauthStart']);
                Route::post('integrations/{provider}/oauth/demo', [AgencyIntegrationController::class, 'oauthDemo']);
                Route::post('meetings', [MeetingLinkController::class, 'store']);
            });
        });
    });
});
