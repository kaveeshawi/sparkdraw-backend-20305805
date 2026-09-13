<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AgencyController extends Controller
{
    use ApiResponse;

    private const CURRENCIES = [
        'USD', 'LKR', 'EUR', 'GBP', 'INR', 'AUD', 'CAD', 'SGD', 'AED', 'JPY', 'NZD', 'CHF',
    ];

    private const SOCIAL_KEYS = [
        'facebook', 'instagram', 'linkedin', 'twitter', 'youtube', 'tiktok',
    ];

    // GET /api/v1/agency
    public function show(Request $request): JsonResponse
    {
        $this->ensureAgencyProfileColumns();
        $agency = $request->user()->agency->fresh();

        return $this->success($this->payload($agency));
    }

    // PUT /api/v1/agency  (admin only — enforced at route level)
    public function update(Request $request): JsonResponse
    {
        $this->ensureAgencyProfileColumns();

        $validated = $request->validate([
            'name'                    => ['sometimes', 'string', 'min:2', 'max:100'],
            'brand_colors'            => ['sometimes', 'array'],
            'brand_colors.primary'    => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'brand_colors.light'      => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'currency'                => ['sometimes', 'string', 'size:3', Rule::in(self::CURRENCIES)],
            'email'                   => ['sometimes', 'nullable', 'email', 'max:150'],
            'phone'                   => ['sometimes', 'nullable', 'string', 'max:40'],
            'website'                 => ['sometimes', 'nullable', 'string', 'max:255'],
            'address'                 => ['sometimes', 'nullable', 'string', 'max:1000'],
            'social_links'            => ['sometimes', 'nullable', 'array'],
            'social_links.facebook'   => ['sometimes', 'nullable', 'string', 'max:255'],
            'social_links.instagram'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'social_links.linkedin'   => ['sometimes', 'nullable', 'string', 'max:255'],
            'social_links.twitter'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'social_links.youtube'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'social_links.tiktok'     => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if (isset($validated['currency'])) {
            $validated['currency'] = strtoupper($validated['currency']);
        }

        if (array_key_exists('social_links', $validated)) {
            $validated['social_links'] = $this->normalizeSocialLinks($validated['social_links'] ?? []);
        }

        if (isset($validated['website'])) {
            $validated['website'] = $this->normalizeUrl($validated['website']);
        }

        $agency = $request->user()->agency;
        $agency->update($validated);
        $agency->refresh();

        return $this->success($this->payload($agency), 'Agency updated successfully.');
    }

    // POST /api/v1/agency/upload-logo  (admin only)
    // Optional body field: variant = light|dark (default light)
    public function uploadLogo(Request $request): JsonResponse
    {
        $this->ensureAgencyProfileColumns();

        $request->validate([
            'logo'    => ['required', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'variant' => ['sometimes', Rule::in(['light', 'dark'])],
        ]);

        $agency = $request->user()->agency;
        $variant = $request->input('variant', 'light') === 'dark' ? 'dark' : 'light';
        $column = $variant === 'dark' ? 'logo_dark_path' : 'logo_path';
        $folder = $variant === 'dark' ? 'logo-dark' : 'logo';

        if ($agency->{$column}) {
            Storage::disk('public')->delete($agency->{$column});
        }

        $path = $request->file('logo')->store("agencies/{$agency->id}/{$folder}", 'public');
        $agency->update([$column => $path]);
        $agency->refresh();

        return $this->success([
            'logo_path'      => $agency->logo_path,
            'logo_dark_path' => $agency->logo_dark_path,
            'logo_url'       => $this->publicUrl($agency->logo_path),
            'logo_dark_url'  => $this->publicUrl($agency->logo_dark_path),
            'variant'        => $variant,
        ], 'Logo uploaded successfully.');
    }

    private function payload($agency): array
    {
        $social = $this->normalizeSocialLinks($agency->social_links ?? []);

        return [
            'id'             => $agency->id,
            'name'           => $agency->name,
            'domain_slug'    => $agency->domain_slug,
            'logo_path'      => $agency->logo_path,
            'logo_dark_path' => $agency->logo_dark_path ?? null,
            'logo_url'       => $this->publicUrl($agency->logo_path),
            'logo_dark_url'  => $this->publicUrl($agency->logo_dark_path ?? null),
            'brand_colors'   => $agency->brand_colors,
            'currency'       => strtoupper($agency->currency ?? 'USD'),
            'email'          => $agency->email,
            'phone'          => $agency->phone,
            'website'        => $agency->website,
            'address'        => $agency->address,
            'social_links'   => $social,
            'created_at'     => $agency->created_at,
        ];
    }

    private function publicUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    private function normalizeSocialLinks(?array $links): array
    {
        $out = [];
        foreach (self::SOCIAL_KEYS as $key) {
            $value = trim((string) ($links[$key] ?? ''));
            $out[$key] = $value !== '' ? $this->normalizeUrl($value) : null;
        }

        return $out;
    }

    private function normalizeUrl(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $value)) {
            $value = 'https://' . $value;
        }

        return $value;
    }

    private function ensureAgencyProfileColumns(): void
    {
        if (! Schema::hasColumn('agencies', 'currency')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->string('currency', 3)->default('USD')->after('brand_colors');
            });
        }

        $columns = [
            'logo_dark_path' => fn (Blueprint $table) => $table->string('logo_dark_path')->nullable()->after('logo_path'),
            'email'          => fn (Blueprint $table) => $table->string('email')->nullable()->after('domain_slug'),
            'phone'          => fn (Blueprint $table) => $table->string('phone', 40)->nullable()->after('email'),
            'website'        => fn (Blueprint $table) => $table->string('website')->nullable()->after('phone'),
            'address'        => fn (Blueprint $table) => $table->text('address')->nullable()->after('website'),
            'social_links'   => fn (Blueprint $table) => $table->json('social_links')->nullable()->after('address'),
        ];

        foreach ($columns as $name => $definition) {
            if (Schema::hasColumn('agencies', $name)) {
                continue;
            }
            Schema::table('agencies', function (Blueprint $table) use ($definition) {
                $definition($table);
            });
        }
    }
}
