<?php
namespace App\Console\Commands;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReleaseExpiredHolds extends Command
{
    protected $signature = 'holds:release-expired';
    protected $description = 'Release expired holds and restore product availability';

    public function handle()
    {
        $expiredHolds = Hold::where('expire_at', '<', now())->get();

        $count = $expiredHolds->count();

        foreach ($expiredHolds as $hold) {
            DB::transaction(function () use ($hold) {
                Product::where('id', $hold->product_id)
                    ->increment('available_qty', $hold->qty);
                
                $hold->delete();
            });
        }

        $this->info("Released {$count} expired holds.");

        return Command::SUCCESS;
    }
}