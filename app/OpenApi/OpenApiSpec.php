<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: "1.0.0",
    title: "Flash Sale API",
    description: "API documentation for the Flash Sale application. This API handles product inventory management, stock holds, orders, and payment webhooks for flash sale events.",
    contact: new OA\Contact(
        name: "API Support",
        email: "support@example.com"
    )
)]
#[OA\Server(
    url: "/api",
    description: "API Server"
)]
#[OA\Tag(
    name: "Products",
    description: "Product management endpoints"
)]
#[OA\Tag(
    name: "Holds",
    description: "Stock hold management endpoints"
)]
#[OA\Tag(
    name: "Orders",
    description: "Order management endpoints"
)]
#[OA\Tag(
    name: "Webhooks",
    description: "Payment webhook endpoints"
)]
#[OA\Schema(
    schema: "Product",
    type: "object",
    properties: [
        new OA\Property(property: "id", type: "integer", example: 1),
        new OA\Property(property: "name", type: "string", example: "Flash Sale Item"),
        new OA\Property(property: "price_cents", type: "integer", example: 9999),
        new OA\Property(property: "stock", type: "integer", example: 100),
        new OA\Property(property: "created_at", type: "string", format: "date-time"),
        new OA\Property(property: "updated_at", type: "string", format: "date-time"),
    ]
)]
#[OA\Schema(
    schema: "Hold",
    type: "object",
    properties: [
        new OA\Property(property: "id", type: "integer", example: 1),
        new OA\Property(property: "product_id", type: "integer", example: 1),
        new OA\Property(property: "qty", type: "integer", example: 2),
        new OA\Property(property: "expire_at", type: "string", format: "date-time"),
        new OA\Property(property: "created_at", type: "string", format: "date-time"),
        new OA\Property(property: "updated_at", type: "string", format: "date-time"),
    ]
)]
#[OA\Schema(
    schema: "Order",
    type: "object",
    properties: [
        new OA\Property(property: "id", type: "integer", example: 1),
        new OA\Property(property: "product_id", type: "integer", example: 1),
        new OA\Property(property: "hold_id", type: "integer", example: 1),
        new OA\Property(property: "qty", type: "integer", example: 2),
        new OA\Property(property: "amount_cents", type: "integer", example: 19998),
        new OA\Property(property: "status", type: "string", enum: ["pending", "paid", "cancelled"], example: "pending"),
        new OA\Property(property: "created_at", type: "string", format: "date-time"),
        new OA\Property(property: "updated_at", type: "string", format: "date-time"),
    ]
)]
#[OA\Get(
    path: "/products/{id}",
    summary: "Get a product by ID",
    description: "Retrieves product details including current stock level. Results are cached for 60 seconds.",
    tags: ["Products"],
    parameters: [
        new OA\Parameter(
            name: "id",
            in: "path",
            required: true,
            description: "Product ID",
            schema: new OA\Schema(type: "integer", example: 1)
        )
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: "Product found",
            content: new OA\JsonContent(ref: "#/components/schemas/Product")
        ),
        new OA\Response(
            response: 404,
            description: "Product not found",
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "message", type: "string", example: "Product not found")
                ]
            )
        )
    ]
)]
#[OA\Post(
    path: "/holds",
    summary: "Create a stock hold",
    description: "Creates a temporary hold on product stock. The hold expires after 2 minutes. Stock is decremented immediately and will be restored if the hold expires without an order being placed.",
    tags: ["Holds"],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["product_id", "qty"],
            properties: [
                new OA\Property(property: "product_id", type: "integer", description: "ID of the product to hold", example: 1),
                new OA\Property(property: "qty", type: "integer", description: "Quantity to hold (minimum 1)", example: 2)
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 201,
            description: "Hold created successfully",
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: "data",
                        type: "object",
                        properties: [
                            new OA\Property(property: "hold", type: "integer", description: "Hold ID", example: 1),
                            new OA\Property(property: "expire_at", type: "string", format: "date-time", description: "Hold expiration time")
                        ]
                    )
                ]
            )
        ),
        new OA\Response(
            response: 404,
            description: "Product not found",
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "error", type: "string", example: "Product not found")
                ]
            )
        ),
        new OA\Response(
            response: 409,
            description: "Insufficient stock",
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "error", type: "string", example: "Out of stock")
                ]
            )
        ),
        new OA\Response(
            response: 422,
            description: "Validation error",
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "message", type: "string", example: "The product_id field is required."),
                    new OA\Property(property: "errors", type: "object")
                ]
            )
        )
    ]
)]
#[OA\Post(
    path: "/orders",
    summary: "Create an order from a hold",
    description: "Converts a valid hold into an order. The hold must not be expired. Once an order is created, the hold is deleted and the order enters 'pending' status awaiting payment confirmation via webhook.",
    tags: ["Orders"],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["hold_id"],
            properties: [
                new OA\Property(property: "hold_id", type: "integer", description: "ID of the hold to convert to an order", example: 1)
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 201,
            description: "Order created successfully",
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "data", ref: "#/components/schemas/Order")
                ]
            )
        ),
        new OA\Response(
            response: 410,
            description: "Hold expired",
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "error", type: "string", example: "Hold expired")
                ]
            )
        ),
        new OA\Response(
            response: 422,
            description: "Validation error",
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "message", type: "string", example: "The hold_id field is required."),
                    new OA\Property(property: "errors", type: "object")
                ]
            )
        )
    ]
)]
#[OA\Post(
    path: "/payments/webhook",
    summary: "Handle payment webhook",
    description: "Receives payment status updates from the payment provider. Uses idempotency keys to prevent duplicate processing. If payment succeeds, the order is marked as 'paid'. If payment fails, the order is cancelled and stock is restored. Supports early webhook arrival (before order creation) by queuing for later processing.",
    tags: ["Webhooks"],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["idempotency_key", "order_id", "status"],
            properties: [
                new OA\Property(property: "idempotency_key", type: "string", description: "Unique key to prevent duplicate webhook processing", example: "pay_abc123xyz"),
                new OA\Property(property: "order_id", type: "integer", description: "ID of the order this payment is for", example: 1),
                new OA\Property(property: "status", type: "string", enum: ["success", "failure"], description: "Payment status", example: "success")
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: "Webhook processed successfully",
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "message", type: "string", example: "Payment successful, order marked as paid"),
                    new OA\Property(property: "order_id", type: "integer", example: 1),
                    new OA\Property(property: "order_status", type: "string", example: "paid"),
                    new OA\Property(property: "idempotency_key", type: "string", example: "pay_abc123xyz")
                ]
            )
        ),
        new OA\Response(
            response: 202,
            description: "Order not yet created, webhook queued for later processing",
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "message", type: "string", example: "Order not found, webhook recorded for later processing"),
                    new OA\Property(property: "idempotency_key", type: "string", example: "pay_abc123xyz")
                ]
            )
        ),
        new OA\Response(
            response: 422,
            description: "Validation error",
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "message", type: "string", example: "The idempotency_key field is required."),
                    new OA\Property(property: "errors", type: "object")
                ]
            )
        )
    ]
)]
class OpenApiSpec
{

}
