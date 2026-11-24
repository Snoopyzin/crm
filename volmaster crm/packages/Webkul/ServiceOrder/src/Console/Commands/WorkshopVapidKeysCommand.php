<?php

namespace Webkul\ServiceOrder\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\ServiceOrder\Services\WebPush;
use Webkul\ServiceOrder\Services\WorkshopSettings;

/**
 * Gera as chaves VAPID desta instalação (alternativa à tela de configuração).
 */
class WorkshopVapidKeysCommand extends Command
{
    protected $signature = 'workshop:vapid-keys {--force : Substitui chaves existentes (desfaz as inscrições)}';

    protected $description = 'Gera as chaves das notificações no celular (Web Push)';

    public function handle(): int
    {
        if (WorkshopSettings::get('push_vapid_public') && ! $this->option('force')) {
            $this->warn('Já existem chaves. Use --force para substituir (as inscrições dos aparelhos serão desfeitas).');

            return self::FAILURE;
        }

        $keys = WebPush::generateVapidKeys();

        WorkshopSettings::set('push_vapid_public', $keys['public']);
        WorkshopSettings::set('push_vapid_private', $keys['private']);

        DB::table('push_subscriptions')->delete();

        $this->info('Chaves geradas.');

        return self::SUCCESS;
    }
}
