<?php

namespace Webkul\Admin\Helpers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Webkul\Admin\Helpers\Reporting\Activity;
use Webkul\Admin\Helpers\Reporting\Lead;
use Webkul\Admin\Helpers\Reporting\Organization;
use Webkul\Admin\Helpers\Reporting\Person;
use Webkul\Admin\Helpers\Reporting\Product;
use Webkul\Admin\Helpers\Reporting\Quote;
use Webkul\Admin\Helpers\Reporting\ServiceOrder as ServiceOrderReporting;
use Webkul\RemoteService\Models\RemoteService;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Services\PainelComissao;
use Webkul\ServiceOrder\Services\PrazoOs;

class Dashboard
{
    /**
     * Create a controller instance.
     *
     * @return void
     */
    public function __construct(
        protected Lead $leadReporting,
        protected Activity $activityReporting,
        protected Product $productReporting,
        protected Person $personReporting,
        protected Organization $organizationReporting,
        protected Quote $quoteReporting,
        protected ServiceOrderReporting $serviceOrderReporting,
    ) {}

    /**
     * Returns the overall revenue statistics.
     */
    public function getRevenueStats(): array
    {
        return [
            'total_won_revenue' => $this->leadReporting->getTotalWonLeadValueProgress(),
            'total_lost_revenue' => $this->leadReporting->getTotalLostLeadValueProgress(),
        ];
    }

    /**
     * Returns the overall statistics.
     */
    public function getOverAllStats(): array
    {
        return [
            'total_leads' => $this->leadReporting->getTotalLeadsProgress(),
            'average_lead_value' => $this->leadReporting->getAverageLeadValueProgress(),
            'average_leads_per_day' => $this->leadReporting->getAverageLeadsPerDayProgress(),
            'total_quotations' => $this->quoteReporting->getTotalQuotesProgress(),
            'total_persons' => $this->personReporting->getTotalPersonsProgress(),
            'total_organizations' => $this->organizationReporting->getTotalOrganizationsProgress(),
        ];
    }

    /**
     * Returns leads statistics.
     */
    public function getTotalLeadsStats(): array
    {
        return [
            'all' => [
                'over_time' => $this->leadReporting->getTotalLeadsOverTime(),
            ],

            'won' => [
                'over_time' => $this->leadReporting->getTotalWonLeadsOverTime(),
            ],
            'lost' => [
                'over_time' => $this->leadReporting->getTotalLostLeadsOverTime(),
            ],
        ];
    }

    /**
     * Returns leads revenue statistics by sources.
     */
    public function getLeadsStatsBySources(): mixed
    {
        return $this->leadReporting->getTotalWonLeadValueBySources();
    }

    /**
     * Returns leads revenue statistics by types.
     */
    public function getLeadsStatsByTypes(): mixed
    {
        return $this->leadReporting->getTotalWonLeadValueByTypes();
    }

    /**
     * Returns open leads statistics by states.
     */
    public function getOpenLeadsByStates(): mixed
    {
        return $this->leadReporting->getOpenLeadsByStates();
    }

    /**
     * Returns top selling products statistics.
     */
    public function getTopSellingProducts(): Collection
    {
        return $this->productReporting->getTopSellingProductsByRevenue(5);
    }

    /**
     * Returns top selling products statistics.
     */
    public function getTopPersons(): Collection
    {
        return $this->personReporting->getTopCustomersByRevenue(5);
    }

    /**
     * Returns Service Order revenue statistics (concluded/cancelled) --
     * separate from the CRM Leads revenue in getRevenueStats().
     */
    public function getServiceOrderRevenueStats(): array
    {
        return [
            'total_concluded_revenue' => $this->serviceOrderReporting->getTotalConcludedServiceOrderValueProgress(),
            'total_cancelled_revenue' => $this->serviceOrderReporting->getTotalCancelledServiceOrderValueProgress(),
        ];
    }

    /**
     * Returns Service Orders that are still open (not concluded/cancelled),
     * oldest first, so the ones waiting longest show up at the top.
     */
    public function getPendingServiceOrders(): Collection
    {
        $ordens = ServiceOrder::whereNotIn('workshop_status', [
            ServiceOrder::STATUS_CONCLUIDA,
            ServiceOrder::STATUS_CANCELADA,
        ])
            ->whereNotIn('id', RemoteService::whereNotNull('service_order_id')->pluck('service_order_id'))
            ->whereDoesntHave('vehicle', function ($query) {
                $query->where('placa', 'like', 'REMOTO-%');
            })
            ->with(['person', 'vehicle'])
            ->orderBy('created_at')
            ->limit(8)
            ->get();

        // Cor do prazo de cada OS: verde no prazo, vermelho estourado.
        $ordens = PrazoOs::aplicar($ordens);

        // Quem ganha comissão não vê valores da OS: só os campos da lista.
        if (PainelComissao::somenteComissao()) {
            return $ordens->map(fn ($os) => array_merge(
                $os->only(PainelComissao::CAMPOS_OS),
                [
                    'person' => $os->person ? ['id' => $os->person->id, 'name' => $os->person->name] : null,
                    'vehicle' => $os->vehicle ? $os->vehicle->only(['id', 'placa', 'marca', 'modelo']) : null,
                ]
            ))->values();
        }

        return $ordens;
    }

    /**
     * A comissão do próprio usuário no período do filtro do Início.
     */
    public function getMinhasComissoes(): array
    {
        return PainelComissao::minhasComissoes(
            auth()->guard('user')->user(),
            $this->getStartDate(),
            $this->getEndDate()
        );
    }

    /**
     * Returns Remote Service occurrences that are released and in progress
     * ("Aberto"), oldest-started first -- these have a live running timer,
     * so the whole team can see how long each one has been open.
     */
    public function getUrgentRemoteServices(): Collection
    {
        $query = RemoteService::where('status', RemoteService::STATUS_ABERTO)
            ->with('person')
            ->orderBy('iniciado_em');

        $user = auth()->guard('user')->user();

        if ($user->view_permission !== 'global') {
            $query->whereIn('grupo_id', $user->groups->pluck('id')->toArray());
        }

        return $query->limit(8)->get();
    }

    /**
     * Get the start date.
     *
     * @return \Carbon\Carbon
     */
    public function getStartDate(): Carbon
    {
        return $this->leadReporting->getStartDate();
    }

    /**
     * Get the end date.
     *
     * @return \Carbon\Carbon
     */
    public function getEndDate(): Carbon
    {
        return $this->leadReporting->getEndDate();
    }

    /**
     * Returns date range
     */
    public function getDateRange(): string
    {
        return $this->getStartDate()->format('d/m').' - '.$this->getEndDate()->format('d/m');
    }
}
