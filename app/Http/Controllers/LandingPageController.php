<?php

namespace App\Http\Controllers;

use App\Services\CustomerSlaOverviewService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LandingPageController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, CustomerSlaOverviewService $slaOverview): Response
    {
        $user = $request->user();
        $roles = $user?->tyroRoleSlugs() ?? [];

        return Inertia::render('welcome', [
            'dashboardUrl' => $user === null
                ? null
                : (in_array('customer', $roles, true) && array_intersect(['admin', 'agent'], $roles) === []
                    ? route('customer.dashboard')
                    : route('tyro-dashboard.index')),
            'isCustomerAccount' => in_array('customer', $roles, true)
                && array_intersect(['admin', 'agent'], $roles) === [],
            'slaOverview' => $slaOverview->summary(),
        ]);
    }
}
