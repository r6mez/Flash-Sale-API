#!/bin/bash
# This script tests true concurrent access to the holds endpoint
# 
# Prerequisites:
#   1. Server running: php artisan serve
#   2. Database seeded with a test product
#
# Usage:
#   ./tests/scripts/parallel_holds_test.sh [product_id] [stock] [requests]

set -e

BASE_URL="${BASE_URL:-http://127.0.0.1:8000}"
PRODUCT_ID="${1:-1}"
EXPECTED_STOCK="${2:-5}"
NUM_REQUESTS="${3:-10}"

echo "=== Parallel Holds Concurrency Test ==="
echo "Base URL: $BASE_URL"
echo "Product ID: $PRODUCT_ID"
echo "Expected Stock: $EXPECTED_STOCK"
echo "Concurrent Requests: $NUM_REQUESTS"
echo ""

# Temporary files for results
RESULT_DIR=$(mktemp -d)
trap "rm -rf $RESULT_DIR" EXIT

echo "Launching $NUM_REQUESTS concurrent requests..."

# Launch all requests in parallel
for i in $(seq 1 $NUM_REQUESTS); do
    (
        STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
            -X POST "$BASE_URL/api/holds" \
            -H "Content-Type: application/json" \
            -H "Accept: application/json" \
            -d "{\"product_id\": $PRODUCT_ID, \"qty\": 1}")
        echo "$STATUS" > "$RESULT_DIR/result_$i.txt"
    ) &
done

# Wait for all background jobs to complete
wait

echo "All requests completed. Analyzing results..."
echo ""

SUCCESS_COUNT=0
FAIL_COUNT=0
OTHER_COUNT=0

for i in $(seq 1 $NUM_REQUESTS); do
    STATUS=$(cat "$RESULT_DIR/result_$i.txt")
    case $STATUS in
        201) SUCCESS_COUNT=$((SUCCESS_COUNT + 1)) ;;
        409) FAIL_COUNT=$((FAIL_COUNT + 1)) ;;
        *) OTHER_COUNT=$((OTHER_COUNT + 1)); echo "  Request $i: unexpected status $STATUS" ;;
    esac
done

echo "=== Results ==="
echo "Successful holds (201): $SUCCESS_COUNT"
echo "Rejected - out of stock (409): $FAIL_COUNT"
if [ $OTHER_COUNT -gt 0 ]; then
    echo "Other responses: $OTHER_COUNT"
fi
echo ""

# Verify results
if [ $SUCCESS_COUNT -eq $EXPECTED_STOCK ] && [ $FAIL_COUNT -eq $((NUM_REQUESTS - EXPECTED_STOCK)) ]; then
    echo "TEST PASSED: No overselling detected!"
    echo "   Expected $EXPECTED_STOCK successes, got $SUCCESS_COUNT"
    echo "   Expected $((NUM_REQUESTS - EXPECTED_STOCK)) failures, got $FAIL_COUNT"
    exit 0
else
    echo "TEST FAILED: Possible overselling or lock contention issue!"
    echo "   Expected $EXPECTED_STOCK successes, got $SUCCESS_COUNT"
    echo "   Expected $((NUM_REQUESTS - EXPECTED_STOCK)) failures, got $FAIL_COUNT"
    exit 1
fi
