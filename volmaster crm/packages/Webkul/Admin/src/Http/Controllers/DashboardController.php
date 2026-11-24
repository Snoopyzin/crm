<?php

namespace Webkul\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Webkul\Admin\Helpers\Dashboard;
use Webkul\Lead\Repositories\PipelineRepository;
use Webkul\ServiceOrder\Services\PainelComissao;

class DashboardController extends Controller
{
    /**
     * Request param functions
     *
     * @var array
     */
    protected $typeFunctions = [
        'over-all' => 'getOverAllStats',
        'revenue-stats' => 'getRevenueStats',
        'total-leads' => 'getTotalLeadsStats',
        'revenue-by-sources' => 'getLeadsStatsBySources',
        'revenue-by-types' => 'getLeadsStatsByTypes',
        'top-selling-products' => 'getTopSellingProducts',
        'top-persons' => 'getTopPersons',
        'open-leads-by-states' => 'getOpenLeadsByStates',
        'pending-service-orders' => 'getPendingServiceOrders',
        'service-order-revenue-stats' => 'getServiceOrderRevenueStats',
        'urgent-remote-services' => 'getUrgentRemoteServices',
        'minhas-comissoes' => 'getMinhasComissoes',
    ];

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected Dashboard $dashboardHelper,
        protected PipelineRepository $pipelineRepository
    ) {}

    /**
     * Display a listing of the resource.
     *
     * @return View
     */
    public function index()
    {
        return view('admin::dashboard.index')->with([
            'startDate' => $this->dashboardHelper->getStartDate(),
            'endDate' => $this->dashboardHelper->getEndDate(),
            'pipelines' => $this->pipelineRepository->all(),
            'defaultPipeline' => $this->pipelineRepository->getDefaultPipeline(),
            // Quem ganha comissão vê só a comissão dele, não o faturamento geral.
            'somenteComissao' => PainelComissao::somenteComissao(),
            'mostrarComissao' => PainelComissao::temComissao(),
        ]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function stats()
    {
        $type = request()->query('type');

        abort_unless(is_string($type) && isset($this->typeFunctions[$type]), 404);

        // Quem ganha comissão não pede os blocos de faturamento nem direto na API.
        if (PainelComissao::somenteComissao() && ! in_array($type, PainelComissao::BLOCOS_PERMITIDOS, true)) {
            abort(403, 'Seu perfil vê apenas a própria comissão no Início.');
        }

        $stats = $this->dashboardHelper->{$this->typeFunctions[$type]}();

        return response()->json([
            'statistics' => $stats,
            'date_range' => $this->dashboardHelper->getDateRange(),
        ]);
    }
}
