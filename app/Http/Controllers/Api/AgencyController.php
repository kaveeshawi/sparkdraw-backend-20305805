<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AgencyController extends Controller
{
    use ApiResponse;

    // GET /api/v1/agency
    public function show(Request $request): JsonResponse
    {
        $agency = $request->user()->agency;

        return $this->success([
            'id'           => $agency->id,
            'name'         => $agency->name,
            'domain_slug'  => $agency->domain_slug,
            'logo_path'    => $agency->logo_path,
            'brand_colors' => $agency->brand_colors,
            'created_at'   => $agency->created_at,
        ]);
    }

    // PUT /api/v1/agency  (admin only — enforced at route level)
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                    => ['sometimes', 'string', 'min:2', 'max:100'],
            'brand_colors'            => ['sometimes', 'array'],
            'brand_colors.primary'    => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'brand_colors.light'      => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $agency = $request->user()->agency;
        $agency->update($validated);

        return $this->success([
            'id'           => $agency->id,
            'name'         => $agency->name,
            'brand_colors' => $agency->brand_colors,
        ], 'Agency updated successfully.');
    }

    // POST /api/v1/agency/upload-logo  (admin only — enforced at route level)
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,svg', 'max:2048'],
        ]);

        $agency = $request->user()->agency;

        if ($agency->logo_path) {
            Storage::disk('public')->delete($agency->logo_path);
        }

        $path = $request->file('logo')->store("agencies/{$agency->id}/logo", 'public');
        $agency->update(['logo_path' => $path]);

        return $this->success(['logo_path' => $path], 'Logo uploaded successfully.');
    }
}
