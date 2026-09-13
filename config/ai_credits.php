<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Agency Starter — monthly AI credits (V1)
    |--------------------------------------------------------------------------
    | Header UI once showed "10" as a dummy placeholder. Real monthly pool is
    | ~1000-scale, with admin getting more than team members.
    | Period resets on the 1st of each month (UTC).
    */

    'plan_name' => env('AI_CREDITS_PLAN_NAME', 'Agency Starter'),

    'allowances' => [
        // Admin gets a larger pool; PM and member share the same team allowance.
        'admin'  => (int) env('AI_CREDITS_ADMIN', 1500),
        'pm'     => (int) env('AI_CREDITS_TEAM', 1000),
        'member' => (int) env('AI_CREDITS_TEAM', 1000),
    ],

    /*
    | Cost per AI bridge feature (credits deducted only after a successful call).
    */
    'costs' => [
        'analyze_feedback'  => 2,
        'sentiment'         => 1,
        'health_score'      => 1,
        'upsell'            => 2,
        'estimate_hours'    => 2,
        'brief_generator'   => 3,
        'digest'            => 2,
        'invoice_reminder'  => 1,
    ],

    'labels' => [
        'analyze_feedback'  => 'NLP Feedback Translator',
        'sentiment'         => 'Sentiment Analysis',
        'health_score'      => 'Project Health Score',
        'upsell'            => 'Upsell Suggestion',
        'estimate_hours'    => 'Task Hour Estimator',
        'brief_generator'   => 'Project Brief Generator',
        'digest'            => 'Weekly Digest',
        'invoice_reminder'  => 'Invoice Reminder Draft',
    ],
];
