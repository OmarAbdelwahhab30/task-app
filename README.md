# Product & Order Management API

## Overview

This API manages products, temporary stock holds, and order creation.  
It uses Redis for caching stock and Laravel queues for handling hold expiration.

---

## Assumptions and Invariants

- **Product Stock**:  
  - Stock is cached in Redis for fast reads.  
  - Redis stock must always reflect the database stock after any hold or order.  
  - Stock cannot go below zero; attempts to create a hold exceeding available stock are rejected.  

- **Hold System**:  
  - Holds are temporary reservations of product stock.  
  - Each hold has a unique `hold_id` and expires automatically (via queued job) if not confirmed.  
  - Expired holds return the stock to Redis.  

- **Order Creation**:  
  - Orders can only be created for existing holds.  
  - Order creation is transactional; database rollback occurs on failure.  
  - Payment status starts as `created` and can be updated via webhook.  

- **Webhook Idempotency**:  
  - Webhooks are idempotent using `idempotency_key` stored in cache for 24 hours.  
  - Repeated webhook calls do not alter order state more than once.  

---

## Setup and Running the Application

**Install dependencies**:  
   ```bash
   composer install
   php artisan migrate
   php artisan db:seed

   ## Running the Application

### Run local server

``` bash
php artisan serve
```

## Redis & Queue

Ensure Redis is running for stock caching.

Start Laravel queue worker for hold expiration jobs:

``` bash
php artisan queue:work
```

## API Endpoints

  -----------------------------------------------------------------------
  Endpoint            Method   Description
  ------------------- -------- ------------------------------------------
  api/products/{id}      GET      Get product info (cached)

  /api/holds      POST     Create a temporary hold on stock

  /api/orders             POST     Confirm a hold and create an order

  /api/payments/webhook            POST     Update order payment status (idempotent)
  -----------------------------------------------------------------------

## Example Request: Create Hold

``` json
POST /holds
{
  "product_id": 1,
  "quantity": 2
}
```

## Example Request: Create Order

``` json
POST /orders
{
  "hold_id": "hold_652e3c7a6b4e2"
}
```

## Example Request: Webhook

``` json
POST /payments/webhook
{
  "order_id": 123,
  "payment_status": "paid",
  "idempotency_key": "abc123"
}
```

## Running Tests

``` bash
php artisan test
```

Tests should cover: - Stock consistency - Hold creation and expiration -
Order creation - Webhook idempotency

## Logs & Metrics

### Laravel logs

`storage/logs/laravel.log`

### Queue jobs

``` bash
php artisan queue:listen
```

### Redis metrics

``` bash
redis-cli keys "*"
redis-cli get "product:{id}:stock"
```
