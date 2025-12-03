<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class CheckHoldExpiration implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string  $holdId,
        public string  $stockKey,
        public int     $stockQuantity,
        public Product $product
    )
    {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $stockKeyExists = Redis::get(($this->stockKey));
        $holdIdExists = Cache::has($this->holdId);

        if ($stockKeyExists && $holdIdExists) {

            // increment the stock in redis
            Redis::incrby($this->stockKey, $this->stockQuantity);
            $this->product->increment('stock', $this->stockQuantity);
            Log::info("Product {$this->product->id} stock updated. New stock: {$this->product->stock}");

            // remove the hold from cache
            Cache::forget($this->holdId);
        }

    }
}
