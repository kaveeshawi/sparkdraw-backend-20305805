<?php

namespace App\Services;

use App\Models\AgencyService;
use App\Models\AgencyServicePackage;

class ServiceCatalogBootstrapService
{
    /**
     * Demo catalog: service category + multiple packages with includes / price / hours / roles.
     *
     * @var array<string, array{description: string, packages: list<array{name: string, includes: string, duration_hours: int, price: float, suggested_roles: list<string>}>}>
     */
    public const DEFAULT_SERVICES = [
        'Web Development' => [
            'description' => 'Custom websites and web apps — discovery, build, integrations, QA, and launch.',
            'packages'    => [
                [
                    'name'            => 'Starter',
                    'includes'        => "Up to 5 marketing pages\nResponsive layout\nContact forms + basic CMS\nStaging + production deploy",
                    'duration_hours'  => 80,
                    'price'           => 3500,
                    'suggested_roles' => ['Project Manager', 'Team Member'],
                ],
                [
                    'name'            => 'Pro',
                    'includes'        => "Custom web app / CMS\nUI polish + front-end build\nAPI integrations\nQA + launch support",
                    'duration_hours'  => 140,
                    'price'           => 6500,
                    'suggested_roles' => ['Project Manager', 'Team Member', 'Agency Admin'],
                ],
                [
                    'name'            => 'Enterprise',
                    'includes'        => "Complex product build\nDesign system + multi-role auth\nThird-party integrations\nPerformance hardening + handover",
                    'duration_hours'  => 240,
                    'price'           => 12000,
                    'suggested_roles' => ['Project Manager', 'Team Member', 'Agency Admin'],
                ],
            ],
        ],
        'Branding' => [
            'description' => 'Brand identity — logo, palette, typography, guidelines, and positioning.',
            'packages'    => [
                [
                    'name'            => 'Essential',
                    'includes'        => "2 logo concepts + final files\nColor palette\nPrimary type pairing",
                    'duration_hours'  => 40,
                    'price'           => 1800,
                    'suggested_roles' => ['Project Manager', 'Team Member'],
                ],
                [
                    'name'            => 'Full Identity',
                    'includes'        => "Logo system + variations\nFull brand guidelines PDF\nVoice & positioning notes\nSocial avatar kit",
                    'duration_hours'  => 80,
                    'price'           => 3200,
                    'suggested_roles' => ['Project Manager', 'Team Member'],
                ],
            ],
        ],
        'Social Media Marketing' => [
            'description' => 'Ongoing social content — calendar, design/copy, scheduling, and reporting.',
            'packages'    => [
                [
                    'name'            => 'Monthly Lite',
                    'includes'        => "8 posts / month\nCaption copy\nScheduling\nBasic insights summary",
                    'duration_hours'  => 24,
                    'price'           => 900,
                    'suggested_roles' => ['Team Member'],
                ],
                [
                    'name'            => 'Growth',
                    'includes'        => "16 posts / month\nContent calendar\nCommunity replies (light)\nMonthly performance report",
                    'duration_hours'  => 48,
                    'price'           => 1800,
                    'suggested_roles' => ['Project Manager', 'Team Member'],
                ],
            ],
        ],
        'SEO Audit' => [
            'description' => 'Technical + on-page SEO review with a prioritized fix roadmap.',
            'packages'    => [
                [
                    'name'            => 'Snapshot',
                    'includes'        => "Site crawl summary\nTop 10 issues\nQuick-win checklist",
                    'duration_hours'  => 16,
                    'price'           => 750,
                    'suggested_roles' => ['Team Member'],
                ],
                [
                    'name'            => 'Full Audit',
                    'includes'        => "Technical + on-page crawl\nKeyword & competitor snapshot\nPrioritized fix report\n30-min walkthrough",
                    'duration_hours'  => 36,
                    'price'           => 1400,
                    'suggested_roles' => ['Project Manager', 'Team Member'],
                ],
            ],
        ],
        'UI/UX Design' => [
            'description' => 'Product design — research, flows, wireframes, prototypes, and UI screens.',
            'packages'    => [
                [
                    'name'            => 'Wireframe Sprint',
                    'includes'        => "Key user flows\nLow-fi wireframes\nClickable prototype (core paths)",
                    'duration_hours'  => 48,
                    'price'           => 2200,
                    'suggested_roles' => ['Project Manager', 'Team Member'],
                ],
                [
                    'name'            => 'UI Complete',
                    'includes'        => "Research synthesis\nHi-fi UI screens\nInteractive prototype\nDesign handoff notes",
                    'duration_hours'  => 96,
                    'price'           => 4200,
                    'suggested_roles' => ['Project Manager', 'Team Member'],
                ],
            ],
        ],
        'App Development' => [
            'description' => 'Mobile app builds — UI implementation, APIs, device testing, and store submission.',
            'packages'    => [
                [
                    'name'            => 'MVP',
                    'includes'        => "Core feature set (iOS or Android / cross-platform)\nAPI integration\nDevice QA on key handsets\nInternal test build",
                    'duration_hours'  => 160,
                    'price'           => 9000,
                    'suggested_roles' => ['Project Manager', 'Team Member'],
                ],
                [
                    'name'            => 'Launch Ready',
                    'includes'        => "Full MVP scope + polish\nPush notifications / auth\nStore listing assets support\nApp store submission",
                    'duration_hours'  => 260,
                    'price'           => 15000,
                    'suggested_roles' => ['Project Manager', 'Team Member', 'Agency Admin'],
                ],
            ],
        ],
        'E-Commerce' => [
            'description' => 'Online store setup — catalog, cart/checkout, payments, and launch QA.',
            'packages'    => [
                [
                    'name'            => 'Store Setup',
                    'includes'        => "Theme / template setup\nUp to 25 products\nCart + checkout\nOne payment gateway",
                    'duration_hours'  => 70,
                    'price'           => 3200,
                    'suggested_roles' => ['Project Manager', 'Team Member'],
                ],
                [
                    'name'            => 'Growth Store',
                    'includes'        => "Custom theme tweaks\nCatalog + collections\nDiscounts / shipping rules\nLaunch QA + training",
                    'duration_hours'  => 120,
                    'price'           => 5500,
                    'suggested_roles' => ['Project Manager', 'Team Member'],
                ],
            ],
        ],
        'Content Strategy' => [
            'description' => 'Editorial planning — audit, topics/keywords, calendar, and copy guidelines.',
            'packages'    => [
                [
                    'name'            => 'Audit',
                    'includes'        => "Content inventory snapshot\nGap analysis\nTopic shortlist",
                    'duration_hours'  => 20,
                    'price'           => 900,
                    'suggested_roles' => ['Team Member'],
                ],
                [
                    'name'            => 'Strategy Pack',
                    'includes'        => "Full content audit\nKeyword / topic map\n90-day editorial calendar\nCopy guidelines",
                    'duration_hours'  => 44,
                    'price'           => 1800,
                    'suggested_roles' => ['Project Manager', 'Team Member'],
                ],
            ],
        ],
        'Video Production' => [
            'description' => 'Video content — scripting, production or motion, edit, and final delivery.',
            'packages'    => [
                [
                    'name'            => 'Short Form',
                    'includes'        => "1 short video (≤60s)\nScript / storyboard\nEdit + captions\n2 revision rounds",
                    'duration_hours'  => 28,
                    'price'           => 1600,
                    'suggested_roles' => ['Team Member'],
                ],
                [
                    'name'            => 'Brand Film',
                    'includes'        => "2–3 minute brand film\nScript + storyboard\nShoot or motion design\nColor + sound polish",
                    'duration_hours'  => 70,
                    'price'           => 4500,
                    'suggested_roles' => ['Project Manager', 'Team Member'],
                ],
            ],
        ],
    ];

    public function ensureForAgency(int $agencyId): void
    {
        if (! AgencyService::withoutGlobalScopes()->where('agency_id', $agencyId)->exists()) {
            $this->seedFullCatalog($agencyId);

            return;
        }

        // Upgrade migrated "Standard"-only packages to the multi-package demo catalog.
        $this->upgradeLegacyPackages($agencyId);
    }

    private function seedFullCatalog(int $agencyId): void
    {
        foreach (self::DEFAULT_SERVICES as $name => $def) {
            $service = AgencyService::withoutGlobalScopes()->create([
                'agency_id'   => $agencyId,
                'name'        => $name,
                'description' => $def['description'],
            ]);

            $this->createPackagesForService($agencyId, $service->id, $def['packages']);
        }
    }

    /**
     * Replace thin legacy catalogs (single "Standard" package) with rich demo packages.
     * Leaves user-customized multi-package services untouched.
     */
    private function upgradeLegacyPackages(int $agencyId): void
    {
        foreach (self::DEFAULT_SERVICES as $name => $def) {
            $service = AgencyService::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->where('name', $name)
                ->first();

            if (! $service) {
                continue;
            }

            $packages = AgencyServicePackage::withoutGlobalScopes()
                ->where('agency_service_id', $service->id)
                ->get();

            $isLegacy = $packages->isEmpty()
                || (
                    $packages->count() === 1
                    && strcasecmp((string) $packages->first()->name, 'Standard') === 0
                );

            if (! $isLegacy) {
                continue;
            }

            AgencyServicePackage::withoutGlobalScopes()
                ->where('agency_service_id', $service->id)
                ->delete();

            $service->update([
                'description' => $def['description'],
            ]);

            $this->createPackagesForService($agencyId, $service->id, $def['packages']);
        }
    }

    /**
     * @param  list<array{name: string, includes: string, duration_hours: int, price: float, suggested_roles: list<string>}>  $packages
     */
    private function createPackagesForService(int $agencyId, int $serviceId, array $packages): void
    {
        foreach ($packages as $pkg) {
            AgencyServicePackage::withoutGlobalScopes()->create([
                'agency_id'         => $agencyId,
                'agency_service_id' => $serviceId,
                'name'              => $pkg['name'],
                'includes'          => $pkg['includes'],
                'price'             => $pkg['price'],
                'duration_hours'    => $pkg['duration_hours'],
                'suggested_roles'   => $pkg['suggested_roles'],
            ]);
        }
    }
}
