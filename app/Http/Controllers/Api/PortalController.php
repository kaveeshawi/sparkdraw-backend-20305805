<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\ProjectResource;
use App\Http\Traits\ApiResponse;
use App\Helpers\ProjectProgress;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PortalController extends Controller
{
    use ApiResponse;

    // GET /api/v1/portal/{slug}/branding — public, no auth required
    public function publicBranding(string $slug): JsonResponse
    {
        $agency = Agency::where('domain_slug', $slug)->first();

        if (!$agency) {
            return $this->notFound('Portal not found.');
        }

        return $this->success($this->formatBranding($agency));
    }

    // GET /api/v1/portal/{slug}/branding — authenticated client (legacy)
    public function branding(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();

        if ($user->agency->domain_slug !== $slug) {
            return $this->notFound('Portal not found for this agency.');
        }

        return $this->success($this->formatBranding($user->agency));
    }

    private function formatBranding(Agency $agency): array
    {
        $colors = $agency->brand_colors ?? [];

        $logoUrl = null;
        if ($agency->logo_path) {
            $logoUrl = str_starts_with($agency->logo_path, 'http')
                ? $agency->logo_path
                : Storage::disk('public')->url($agency->logo_path);
        }

        return [
            'agency_name'   => $agency->name,
            'logo_url'      => $logoUrl,
            'primary_color' => $colors['primary'] ?? '#802AEE',
            'primary_light' => $colors['light'] ?? '#f3e8ff',
        ];
    }

    // GET /api/v1/portal/{slug}/projects — the authenticated client's own linked project(s)
    public function projects(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();

        if ($user->agency->domain_slug !== $slug) {
            return $this->notFound('Portal not found for this agency.');
        }

        $client = Client::where('contact_user_id', $user->id)->first();

        if (!$client) {
            return $this->notFound('No client profile found for your user account.');
        }

        $projects = $client->projects()
            ->with(['client:id,company_name', 'latestHealthScore'])
            ->orderByDesc('created_at')
            ->get();

        return $this->success(ProjectResource::collection($projects));
    }

    // GET /api/v1/portal/{slug}/projects/{project}/progress — milestone progress for ProgressView
    public function progress(Request $request, string $slug, Project $project): JsonResponse
    {
        $user = $request->user();

        if ($user->agency->domain_slug !== $slug) {
            return $this->notFound('Portal not found for this agency.');
        }

        $client = Client::where('contact_user_id', $user->id)->first();

        if (!$client || $project->client_id !== $client->id) {
            return $this->forbidden('You do not have access to this project.');
        }

        $project->load('tasks');
        $milestones = $project->milestones()->with('tasks')->orderBy('due_date')->get();

        return $this->success([
            'project' => [
                'name'             => $project->name,
                'progress_percent' => ProjectProgress::calculate($project),
                'status'           => $project->status,
                'start_date'       => $project->start_date?->toDateString(),
                'end_date'         => $project->end_date?->toDateString(),
            ],
            'milestones' => $milestones->map(function ($m) {
                $total = $m->tasks->count();
                $done  = $m->tasks->where('status', 'done')->count();

                return [
                    'id'       => $m->id,
                    'title'    => $m->title,
                    'status'   => $m->status === 'completed' ? 'completed' : ($total > 0 && $done > 0 ? 'active' : 'pending'),
                    'progress' => $total > 0 ? (int) round(($done / $total) * 100) : ($m->status === 'completed' ? 100 : 0),
                    'due_date' => $m->due_date?->toDateString(),
                ];
            })->values(),
        ]);
    }

    // GET /api/v1/portal/{slug}/invoices
    public function invoices(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();

        if ($user->agency->domain_slug !== $slug) {
            return $this->notFound('Portal not found.');
        }

        $client = Client::where('contact_user_id', $user->id)->first();
        if (!$client) {
            return $this->success([]);
        }

        $invoices = Invoice::with(['project:id,name'])
            ->where('client_id', $client->id)
            ->whereIn('status', ['sent', 'paid'])
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn ($inv) => $inv->displayStatus() !== 'draft');

        return $this->success(InvoiceResource::collection($invoices->values()));
    }
}
