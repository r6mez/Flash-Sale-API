# Flash-Sale Task

## Used
PHP 8.5, Laravel 12, PostgreSQL for db, Redis for cache, PHPUnit for sequential testing testing + Shell Scripts for parallel testing.

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/products/{id}` | Get product details |
| `POST` | `/api/orders` | Make order request |
| `POST` | `/api/payments/webhook` | Handle payment webhook |

## Assumptions and Invariants


## Product Purchase Flow
This is a diagram I made to illustrate the different scenarios that happen when the user attempts to go through the product purchase process 



## Running and Testing the Application

### Clone the repo
```bash
git clone https://github.com/r6mez/Flash-Sale-Task
cd Flash-Sale-Task
```

### Environment Varibles
Copy `.env.example` into a `.env` file, and change postgres configurations to meet yours.

### Setup
Create a database called `flash_sale_api` or whatever name you put in the `.env` file then run

```
composer setup
```

### Database Seeding
```bash
php artisan db:seed
```
- this will add 2 products to the db.

### Development Server
```bash
composer dev
```

This runs concurrently:
- Laravel server at `http://localhost:8000`
- Queue worker for clearning expired holds background job.

### Accessing the Documentation
After starting the development server visit http://localhost:8000/api/documentation to view SwaggerUI docs.

### Where to see logs/metrics
run:
```bash
php artisan pail
```

### Running Tests
directly with PHPUnit (for sequential tests):
```bash
php artisan test
```

The test suite covers:
- Parallel hold attempts at stock boundary
- Hold expiration and stock restoration
- Webhook idempotency (duplicate keys)
- Payment failure stock restoration (no double-return)
- Webhook arriving before order creation
- Complete purchase flow (hold → order → paid)
- Failed purchase flow (hold → order → cancelled)
- Expired hold rejection

Or by the shell script I wrote to simulate parallel requests for holds make sure first you clear the database, run the seeders, this can be done with the following commands:

```shell
php artisan migrate:rollback
php artisan migrate
php artisan db:seed
php artisan app:sync-stock-to-redis
```

then run the script:

```shell
# args: [product_id] [stock] [requests] [sell_qty]
./tests/scripts/parallel_holds_test.sh 1 100 10 50
```

you should get a result like this:
```bash
=== Parallel Holds Concurrency Test ===
Base URL: http://127.0.0.1:8000
Product ID: 1
Expected Stock: 100
Concurrent Requests: 10

Launching 10 concurrent requests...
All requests completed.

=== Results ===
Successful holds (201): 2
Rejected - out of stock (409): 8

   Expected  successes, got 2
   Expected 8 failures, got 8
TEST PASSED: No overselling detected!
```