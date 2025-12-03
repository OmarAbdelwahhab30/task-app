<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Jobs\CheckHoldExpiration;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class ProductController extends Controller
{
    public function show(Product $product)
    {
        $cached_product = Cache::remember("product:$product->id:static", 3600, function () use ($product) {
            return (object)[
                'id' => $product->id,
                'name' => $product->name,
                'price' => $product->price,
            ];
        });

        $stockKey = "product:$product->id:stock";

        Redis::set($stockKey, $product->stock);

        $stock = $product->stock;


        $cached_product->stock = $stock;

        return response()->json(
            [
                "product" => new ProductResource($cached_product)
            ]
        );
    }

    public function createHold(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $product = Product::findOrFail($request->product_id);

        // get the product stock from redis
        $stockKey = "product:$product->id:stock";

        // decrement the stock in redis and db
        if (Redis::get($stockKey) - $request->quantity < 0) {
            return response()->json([
                'message' => 'Insufficient quantity in stock',
            ], 400);
        }
        Redis::decrby($stockKey, $request->quantity);
        $product->decrement('stock', $request->quantity);

        // create a hold in redis with hold_id and expiration date of 2 minutes
        $holdId = uniqid('hold_', true);

        Cache::put($holdId, $request->product_id);

        // make a job delay it for 15 Seconds then return the stock to redis if the hold is not confirmed
        CheckHoldExpiration::dispatch(
            $holdId,
            $stockKey,
            $request->quantity,
            $product
        )->delay(now()->addSeconds(10));

        return response()->json([
            'message' => 'Hold created successfully',
            'hold_id' => $holdId,
            'new_quantity' => Redis::get($stockKey),
        ]);

    }

    public function createOrder(Request $request)
    {
        $request->validate([
            'hold_id' => 'required',
        ]);

        $holdId = $request->hold_id;

        if (!Cache::has($holdId)) {
            return response()->json([
                'message' => 'Hold not found or expired',
            ], 404);
        }

        Db::beginTransaction();
        try {
            $order = Order::create([
                'payment_status' => 'created',
            ]);

            Cache::forget($holdId);

        } catch (\Exception $e) {
            Db::rollBack();
            return response()->json([
                'message' => 'Failed to create order',
            ], 500);
        }

        return response()->json([
            'message' => 'Order created successfully',
            'order_id' => $order->id,
        ]);
    }

    public function handleWebhook(Request $request)
    {
        $payload = $request->all();

        if (Cache::has("webhook_{$payload['idempotency_key']}")) {
            return response()->json(['status' => 'duplicate_ignored']);
        }

        $order = Order::where('id', $payload['order_id'])->first();

        if ($order) {
            $order->update([
                'payment_status' => $payload['payment_status'],
            ]);
        }

        Cache::put("webhook_{$payload['idempotency_key']}", true, now()->addHours(24));

        return response()->json(['status' => 'ok']);
    }
}
