<?php

namespace Webkul\PartInventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\PartInventory\Models\PartCategory;

class PartCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $categories = [
            'Filtros',
            'Lubrificantes',
            'Peças Motor',
            'Peças Transmissão',
            'Sistema Elétrico',
            'Freios',
            'Suspensão',
        ];

        foreach ($categories as $category) {
            PartCategory::firstOrCreate(['name' => $category]);
        }
    }
}
