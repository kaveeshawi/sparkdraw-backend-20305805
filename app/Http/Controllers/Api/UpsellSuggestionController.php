<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\ProjectEvent;
use App\Models\UpsellSuggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpsellSuggestionController extends Controller
{
    use ApiResponse;

    // GET /api/v1/upsell-suggestions
    public function index(Request $request): JsonResponse
    {
        $query = UpsellSuggestion::with([
            'project:id,name,client_id',
            'project.client:id,company_name',
        ])->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('admin_status', $status);
        }

        $data = $query->get()->map(fn (UpsellSuggestion $s) => [
            'id'            => $s->id,
            'project_id'    => $s->project_id,
            'project_name'  => $s->project?->name,
            'client_name'   => $s->project?->client?->company_name,
            'service_type'  => $s->service_type,
            'confidence'    => $s->confidence,
            'admin_status'  => $s->admin_status,
            'client_status' => $s->client_status,
            'created_at'    => $s->created_at,
        ]);

        return $this->success($data);
    }

    // PATCH /api/v1/upsell-suggestions/{upsellSuggestion}/approve
    public function approve(Request $request, UpsellSuggestion $upsellSuggestion): JsonResponse
    {
        $upsellSuggestion->update(['admin_status' => 'approved']);

        ProjectEvent::log(
            $upsellSuggestion->agency_id,
            $upsellSuggestion->project_id,
            'upsell_approved',
            [
                'upsell_id'    => $upsellSuggestion->id,
                'service_type' => $upsellSuggestion->service_type,
                'confidence'   => $upsellSuggestion->confidence,
                'approved_by'  => $request->user()->id,
            ]
        );

        return $this->success([
            'id'           => $upsellSuggestion->id,
            'admin_status' => 'approved',
        ], 'Upsell suggestion approved.');
    }

    // PATCH /api/v1/upsell-suggestions/{upsellSuggestion}/reject
    public function reject(Request $request, UpsellSuggestion $upsellSuggestion): JsonResponse
    {
        $upsellSuggestion->update(['admin_status' => 'rejected']);

        ProjectEvent::log(
            $upsellSuggestion->agency_id,
            $upsellSuggestion->project_id,
            'upsell_rejected',
            [
                'upsell_id'    => $upsellSuggestion->id,
                'service_type' => $upsellSuggestion->service_type,
                'rejected_by'  => $request->user()->id,
            ]
        );

        return $this->success([
            'id'           => $upsellSuggestion->id,
            'admin_status' => 'rejected',
        ], 'Upsell suggestion rejected.');
    }

    // PATCH /api/v1/upsell-suggestions/{upsellSuggestion}/send
    public function send(Request $request, UpsellSuggestion $upsellSuggestion): JsonResponse
    {
        $upsellSuggestion->update([
            'admin_status'  => 'approved',
            'client_status' => 'shown',
        ]);

        ProjectEvent::log(
            $upsellSuggestion->agency_id,
            $upsellSuggestion->project_id,
            'upsell_sent',
            [
                'upsell_id'    => $upsellSuggestion->id,
                'service_type' => $upsellSuggestion->service_type,
                'confidence'   => $upsellSuggestion->confidence,
                'sent_by'      => $request->user()->id,
            ]
        );

        return $this->success([
            'id'            => $upsellSuggestion->id,
            'admin_status'  => 'approved',
            'client_status' => 'shown',
        ], 'Upsell suggestion sent to client.');
    }

    // PATCH /api/v1/upsell-suggestions/{upsellSuggestion}/undo
    public function undo(Request $request, UpsellSuggestion $upsellSuggestion): JsonResponse
    {
        $previous = [
            'admin_status'  => $upsellSuggestion->admin_status,
            'client_status' => $upsellSuggestion->client_status,
        ];

        $upsellSuggestion->update([
            'admin_status'  => 'pending',
            'client_status' => 'hidden',
        ]);

        ProjectEvent::log(
            $upsellSuggestion->agency_id,
            $upsellSuggestion->project_id,
            'upsell_undone',
            [
                'upsell_id'    => $upsellSuggestion->id,
                'service_type' => $upsellSuggestion->service_type,
                'previous'     => $previous,
                'undone_by'    => $request->user()->id,
            ]
        );

        return $this->success([
            'id'            => $upsellSuggestion->id,
            'admin_status'  => 'pending',
            'client_status' => 'hidden',
        ], 'Upsell action undone.');
    }
}
