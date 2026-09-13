<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agency extends Model
{
    protected $fillable = [
        'name',
        'logo_path',
        'logo_dark_path',
        'brand_colors',
        'domain_slug',
        'currency',
        'email',
        'phone',
        'website',
        'address',
        'social_links',
    ];

    protected $attributes = [
        'currency' => 'USD',
    ];

    protected $casts = [
        'brand_colors' => 'array',
        'social_links' => 'array',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function projectEvents(): HasMany
    {
        return $this->hasMany(ProjectEvent::class);
    }
}
