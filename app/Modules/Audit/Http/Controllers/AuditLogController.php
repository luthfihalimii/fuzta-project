<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(): Response
    {
        $logs = AuditLog::query()
            ->with(['actor', 'branch'])
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor_name' => $log->actor?->name,
                'branch_code' => $log->branch?->code,
                'target_type' => class_basename($log->target_type),
                'target_id' => $log->target_id,
                'reason' => $log->reason,
                'before' => $log->before ?? [],
                'after' => $log->after ?? [],
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/audit/Index', [
            'logs' => $logs,
        ]);
    }
}
