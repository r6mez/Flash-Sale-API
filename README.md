# Flash-Sale Task


## Used
- PHP 8.5
- MySQL
- Redis
- PHPUnit

## Running and Testing the Application

### Quick Setup
```bash
# Clone and install
git clone https://github.com/r6mez/Flash-Sale-Task
cd Flash-Sale-Task
composer setup
```

### env
Copy `.env.example` into a `.env` file, and change MySQL configurations to meet yours.

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

Or by the bash script I wrote to simulate parallel requests for holds
make sure first you clear the database, run the seeders, then run the script
this can be done with the following commands:

```bash
php artisan migrate:rollback
php artisan migrate
php artisan db:seed
./tests/scripts/parallel_holds_test.sh 1 100 200
```

you should get a result like this:
```bash
=== Parallel Holds Concurrency Test ===
Base URL: http://127.0.0.1:8000
Product ID: 1
Expected Stock: 100
Concurrent Requests: 200

Launching 200 concurrent requests...
All requests completed. Analyzing results...

=== Results ===
Successful holds (201): 100
Rejected - out of stock (409): 100

TEST PASSED: No overselling detected!
   Expected 100 successes, got 100
   Expected 100 failures, got 100
```

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/products/{id}` | Get product details |
| `POST` | `/api/holds` | Create inventory hold |
| `POST` | `/api/orders` | Convert hold to order |
| `POST` | `/api/payments/webhook` | Handle payment webhook |

