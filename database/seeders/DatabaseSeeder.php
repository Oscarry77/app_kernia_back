<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Sin seeders por ahora -- el primer LandlordAdmin se crea con
     * `php artisan landlord:crear-admin`, no por seeder.
     */
    public function run(): void
    {
    }
}
