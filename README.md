# Flash-Sale Task


## Used
- PHP 8.5
- MySQL
- Radis
- PHPUnit

## Running the Application

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
- Log viewer with `artisan pail`

### Running Tests

```bash
# directly with PHPUnit
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


## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/products/{id}` | Get product details |
| `POST` | `/api/holds` | Create inventory hold |
| `POST` | `/api/orders` | Convert hold to order |
| `POST` | `/api/payments/webhook` | Handle payment webhook |

