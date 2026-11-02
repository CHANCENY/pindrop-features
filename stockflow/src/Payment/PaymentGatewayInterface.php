<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Payment;

use Simp\Price\lib\Price;

interface PaymentGatewayInterface
{
    /**
     * @return array{status: string, provider_payment_id: ?string, message: string, raw_response: array}
     */
    public function charge(
        Price $amount,
        string $currency,
        array $paymentData,
        array $credentials,
        array $configuration
    ): array;
}
