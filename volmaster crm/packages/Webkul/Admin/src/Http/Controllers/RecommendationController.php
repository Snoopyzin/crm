<?php

namespace Webkul\Admin\Http\Controllers;

use Illuminate\View\View;

/**
 * Stage 10, "Recomendações" -- a future partner-network directory (workshops
 * Volmaster recommends). Placeholder only, by the user's own request.
 */
class RecommendationController extends Controller
{
    public function index(): View
    {
        return view('admin::recommendations.index');
    }
}
