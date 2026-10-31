<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Services\Customer\CustomerPortalService;
use App\Services\CustomerSlaOverviewService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, CustomerPortalService $portal, CustomerSlaOverviewService $slaOverview): View
    {
        $customer = $request->user();

        return view('customer.dashboard', [
            'customer' => $customer,
            'overview' => $portal->dashboard($customer),
            'slaOverview' => $slaOverview->summary(),
        ]);
    }
}
