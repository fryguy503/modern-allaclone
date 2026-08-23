<?php

namespace App\Console\Commands;

use App\Models\Zone;
use App\ViewModels\ZoneViewModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CacheZones extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cache-zones';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cache all the zones @show logic';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $currentExpansion = config('everquest.current_expansion');
        $zones = Zone::getExpansionZones($currentExpansion)->flatten(1);

        $this->info("Starting zone cache warming for {$zones->count()} zones...");

        foreach ($zones as $zone) {
            $version = $zone->version;
            $cacheKey = "zones.show.v2.{$zone->id}_v{$version}";

            // forget any previous cache we may have
            Cache::forget($cacheKey);

            // cache forever since this data rarely changes.
            Cache::rememberForever($cacheKey, function () use ($zone, $version) {
                $zone = Zone::where('id', $zone->id)
                    ->with('zonepoints', function ($q) use ($version) {
                        $q->where('version', $version)
                            ->with('targetZones:id,zoneidnumber,short_name,long_name');
                    })
                    ->where('version', $version)
                    ->firstOrFail();

                $vm = new ZoneViewModel($zone, $version);

                return [
                    'zone' => $zone,
                    'npcs' => $vm->npcs(),
                    'drops' => $vm->drops(),
                    'spawnGroups' => $vm->spawnGroups(),
                    'foraged' => $vm->foraged(),
                    'fished' => $vm->fished(),
                    'connectedZones' => $vm->connectedZones(),
                    'tasks' => $vm->tasks(),
                ];
            });

            $this->line("Cached: {$cacheKey} -- {$zone->short_name} / {$zone->id}-{$zone->version}");
        }

        $this->info('All zones cached successfully.');

        return Command::SUCCESS;
    }
}
