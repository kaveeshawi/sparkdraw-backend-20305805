<?php

namespace App\Support;

class Permissions
{
    // Work on assigned projects / tasks / AI insights
    public const PROJECTS = 'projects.manage';

    // See every agency project — when false, only assigned (relevant) projects
    public const PROJECTS_VIEW_ALL = 'projects.view_all';

    // Budget, invoices, revenue visibility
    public const FINANCIALS = 'financials.view';

    // Access clients tied to this role's relevant projects
    public const CLIENTS = 'clients.manage';

    // See every agency client — when false, only relevant clients
    public const CLIENTS_VIEW_ALL = 'clients.view_all';

    // Contact-detail visibility on a client record (email, phone)
    public const CLIENT_CONTACT_DETAILS = 'clients.contact_details';

    // Team members, departments, agency settings, integrations
    public const TEAM = 'team.manage';

    // Agency-wide dashboard: team presence, activity feed, agency health widgets
    public const AGENCY_OVERVIEW = 'workspace.agency_overview';

    public const ALL = [
        self::PROJECTS,
        self::PROJECTS_VIEW_ALL,
        self::CLIENTS,
        self::CLIENTS_VIEW_ALL,
        self::CLIENT_CONTACT_DETAILS,
        self::FINANCIALS,
        self::TEAM,
        self::AGENCY_OVERVIEW,
    ];

    public const LABELS = [
        self::PROJECTS               => 'Relevant projects',
        self::PROJECTS_VIEW_ALL      => 'All projects',
        self::CLIENTS                => 'Relevant clients',
        self::CLIENTS_VIEW_ALL       => 'All clients',
        self::CLIENT_CONTACT_DETAILS => 'Contact details',
        self::FINANCIALS             => 'Financials',
        self::TEAM                   => 'Team',
        self::AGENCY_OVERVIEW        => 'Agency overview',
    ];

    public const DEFAULTS = [
        'pm' => [
            self::PROJECTS               => true,
            self::PROJECTS_VIEW_ALL      => true,
            self::CLIENTS                => true,
            self::CLIENTS_VIEW_ALL       => true,
            self::CLIENT_CONTACT_DETAILS => true,
            self::FINANCIALS             => false,
            self::TEAM                   => true,
            self::AGENCY_OVERVIEW        => true,
        ],
        'member' => [
            self::PROJECTS               => true,
            self::PROJECTS_VIEW_ALL      => false,
            self::CLIENTS                => false,
            self::CLIENTS_VIEW_ALL       => false,
            self::CLIENT_CONTACT_DETAILS => false,
            self::FINANCIALS             => false,
            self::TEAM                   => true,
            self::AGENCY_OVERVIEW        => false,
        ],
    ];

    public static function defaultsFor(string $baseRole): array
    {
        if ($baseRole === 'admin') {
            return array_fill_keys(self::ALL, true);
        }

        return self::DEFAULTS[$baseRole] ?? array_fill_keys(self::ALL, false);
    }
}
