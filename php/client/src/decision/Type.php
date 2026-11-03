<?php

declare(strict_types=1);

namespace Decionis\Client\Decision;

/** The seven canonical Commerce Gate decision types. */
enum Type: string
{
    case PriceChange = 'PRICE_CHANGE';
    case InventoryMutation = 'INVENTORY_MUTATION';
    case OrderAcceptance = 'ORDER_ACCEPTANCE';
    case FulfillmentAction = 'FULFILLMENT_ACTION';
    case PromotionChange = 'PROMOTION_CHANGE';
    case RefundRequest = 'REFUND_REQUEST';
    case ReturnAuthorization = 'RETURN_AUTHORIZATION';

    public function transactionType(): string
    {
        return strtolower($this->value);
    }

    public function workflowKey(): string
    {
        return 'commerce_' . $this->transactionType();
    }
}
