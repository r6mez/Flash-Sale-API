# The Workflow (Step-by-Step)
## Step 1: Request Interception (The Filter)
- When the sale starts, thousands of requests hit your API per second.
- Rate Limiting: The API Gateway rejects users spamming the "Buy" button

## Step 2: The Atomic Inventory Check (The Critical Moment)
- The Problem (Race Condition): If User A and User B both see "1 item left" at the exact same millisecond, both might buy it.
- The Solution: You use a Redis Lua script to make the "Check" and "Decrement" a single, indivisible step.
- "If stock > 0, decrease by 1 and return TRUE. Else, return FALSE."
- Because Redis is single-threaded for scripts, no two users can execute this logic simultaneously.

## Step 3: Asynchronous Handoff
- If the Redis script returns TRUE (User secured an item):
- The API does not create the order in the database yet (too slow).
- It pushes a message event to the Message Queue.
- The user immediately sees a "Reservation Successful! Redirecting to payment..." response.

## Step 4: Order Creation (The Worker)
A background worker pulls messages from the queue at a steady pace that your database can handle.
It creates the Order record in the SQL database.
It sets a timeout. If the user doesn't pay in that time, a cron job cancels the order and increments the Redis stock back up (+1).

# Differences
## 1. Stock Check & Decrement (The “Critical Moment”)

**Requirements (Step 2)**    
- Stock check and decrement must happen **entirely in Redis memory**.  
- Goal: **atomicity** without touching the database initially.

**Actual Code**  
- Uses **SQL pessimistic locking** instead.  
- In `HoldController.php`, the code relies on `lockForUpdate()` on the database row.  
- Although wrapped with `Cache::lock` (Redis distributed lock), the **source of truth for stock is the SQL database**, not Redis.


## 2. Asynchronous vs. Synchronous Processing

**Requirements (Step 3 & 4)**  
- Describes an **asynchronous queue-based workflow**.  
- Flow:
  1. Redis validates stock (“Yes”).
  2. API immediately returns **Success**.
  3. A **background worker** later creates the database record.

**Actual Code**  
- Implements a **synchronous workflow**.  
- `HoldController::create`:
  - Writes to the database (`Hold::create`).
  - Waits for the transaction to commit.
  - Only then returns the HTTP response.
- No queue or background worker is involved.


## 3. Order Lifecycle & “Holds”

**Requirements**  
- Implies a **single “Buy” action**.  
- A worker eventually creates an **Order** with a timeout.

**Actual Code**  
- Introduces an intermediate **Hold** concept.  
- Process is split into two API calls:
  - `POST /api/holds` → creates a temporary reservation (`Hold`).
  - `POST /api/orders` → converts a specific Hold into an Order.
- This forces the client to manage a **two-step transaction**, unlike the requirements.


## 4. Cron / Cleanup Logic

**Requirements**  
- A cron job:
  - Cancels unpaid orders.
  - Increments **Redis stock**.

**Actual Code**  
- A cron job:
  - Releases expired **Holds**.
  - Increments **database stock**.
- Implemented in `ReleaseExpiredHolds.php`.
- The **intent matches** the requirements (cleanup on timeout), but the **state is managed in SQL, not Redis**.
