<?php
namespace App\Console\Commands;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReleaseExpiredHolds extends Command
{
    protected $signature = 'holds:release-expired';
    protected $description = 'Release expired holds and restore product availability';

    public function handle()
    {
        // Prevent concurrent runs of this command
        $lock = Cache::lock('holds:release-expired:running', 120);
        
        if (!$lock->get()) {
            Log::warning('Expired holds cleanup skipped - another instance is running');
            $this->warn('Another instance is already running. Skipping.');
            return Command::SUCCESS;
        }

        try {
            $expiredHolds = Hold::where('expire_at', '<', now())->get();

            $count = $expiredHolds->count();

            foreach ($expiredHolds as $hold) {
                DB::transaction(function () use ($hold) {
                    $hold = Hold::lockForUpdate()->find($hold->id);
                    
                    if (!$hold) {
                        return;
                    }
                    
                    Product::where('id', $hold->product_id)
                        ->increment('stock', $hold->qty);
                    
                    Cache::forget("product:{$hold->product_id}");
                    
                    Log::info('Expired hold released - stock restored', [
                        'hold_id' => $hold->id,
                        'product_id' => $hold->product_id,
                        'qty_restored' => $hold->qty,
                        'expired_at' => $hold->expire_at->toIso8601String(),
                    ]);
                    
                    $hold->delete();
                });
            }

            if ($count > 0) {
                Log::info('Expired holds cleanup completed', [
                    'total_released' => $count,
                ]);
            }
            
            $this->info("Released {$count} expired holds.");

            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}