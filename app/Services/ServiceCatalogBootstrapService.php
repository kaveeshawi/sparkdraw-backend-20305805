<?php

namespace App\Services;

use App\Models\AgencyService;

class ServiceCatalogBootstrapService
{
    /**
     * Descriptions give the AI brief generator context on what a service
     * type typically involves, so it can predict realistic milestones/tasks
     * when a project of that type is created.
     *
     * @var array<string, string>
     */
    public const DEFAULT_SERVICES = [
        'Web Development'        => 'Custom websites and web apps — front-end build, back-end integration, CMS setup, responsive QA, and deployment.',
        'Branding'                => 'Brand identity design — logo, color palette, typography, brand guidelines, and voice/positioning.',
        'Social Media Marketing'  => 'Ongoing social content — content calendar, post design/copy, scheduling, community management, and performance reporting.',
        'SEO Audit'               => 'Technical + on-page SEO review — site crawl, keyword research, competitor analysis, and a prioritized fix report.',
        'UI/UX Design'            => 'Product design work — user research, wireframes, interactive prototypes, and high-fidelity UI screens.',
        'App Development'        => 'Native or cross-platform mobile app build — UI implementation, API integration, device testing, and app store submission.',
        'E-Commerce'              => 'Online store setup — product catalog, cart/checkout, payment gateway integration, and launch QA.',
        'Content Strategy'        => 'Editorial planning — content audit, topic/keyword strategy, content calendar, and copywriting guidelines.',
        'Video Production'        => 'Video content — scripting/storyboarding, filming or motion design, editing, and final delivery in required formats.',
    ];

    public function ensureForAgency(int $agencyId): void
    {
        if (AgencyService::withoutGlobalScopes()->where('agency_id', $agencyId)->exists()) {
            return;
        }

        foreach (self::DEFAULT_SERVICES as $name => $description) {
            AgencyService::withoutGlobalScopes()->firstOrCreate(
                ['agency_id' => $agencyId, 'name' => $name],
                ['agency_id' => $agencyId, 'name' => $name, 'description' => $description],
            );
        }
    }
}
