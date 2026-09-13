<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_service_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_service_id')->constrained('agency_services')->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('includes')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->unsignedInteger('duration_hours')->nullable();
            $table->json('suggested_roles')->nullable();
            $table->timestamps();

            $table->unique(['agency_service_id', 'name']);
        });

        if (Schema::hasColumn('agency_services', 'default_hours')) {
            $services = DB::table('agency_services')->get();
            foreach ($services as $service) {
                $roles = null;
                if (! empty($service->suggested_roles)) {
                    $decoded = json_decode($service->suggested_roles, true);
                    $roles = is_array($decoded) ? json_encode(array_values($decoded)) : $service->suggested_roles;
                }

                DB::table('agency_service_packages')->insert([
                    'agency_id'         => $service->agency_id,
                    'agency_service_id' => $service->id,
                    'name'              => 'Standard',
                    'includes'          => $service->description ?? null,
                    'price'             => $service->default_budget,
                    'duration_hours'    => $service->default_hours,
                    'suggested_roles'   => $roles,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
            }

            Schema::table('agency_services', function (Blueprint $table) {
                $table->dropColumn(['default_hours', 'default_budget', 'suggested_roles']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('agency_services', 'default_hours')) {
            Schema::table('agency_services', function (Blueprint $table) {
                $table->unsignedInteger('default_hours')->nullable()->after('description');
                $table->decimal('default_budget', 12, 2)->nullable()->after('default_hours');
                $table->json('suggested_roles')->nullable()->after('default_budget');
            });

            $packages = DB::table('agency_service_packages')
                ->where('name', 'Standard')
                ->orderBy('id')
                ->get()
                ->groupBy('agency_service_id');

            foreach ($packages as $serviceId => $rows) {
                $pkg = $rows->first();
                DB::table('agency_services')->where('id', $serviceId)->update([
                    'default_hours'   => $pkg->duration_hours,
                    'default_budget'  => $pkg->price,
                    'suggested_roles' => $pkg->suggested_roles,
                ]);
            }
        }

        Schema::dropIfExists('agency_service_packages');
    }
};
