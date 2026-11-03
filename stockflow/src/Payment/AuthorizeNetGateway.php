<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Payment;

use InvalidArgumentException;
use Simp\Price\lib\Price;

final class AuthorizeNetGateway implements PaymentGatewayInterface
{
    public function __construct(private readonly HttpJsonClient $client = new HttpJsonClient())
    {
    }

    public function charge(
        Price $amount,
        string $currency,
        array $paymentData,
        array $credentials,
        array $configuration
    ): array {
        if (!$amount->isGreaterThan(0.0)) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }
        if (strtoupper($currency) !== 'USD') {
            throw new InvalidArgumentException('Authorize.Net currently supports USD in this Stockflow configuration.');
        }
        $apiLoginId = $this->scalar($credentials['api_login_id'] ?? '');
        $transactionKey = $this->scalar($credentials['transaction_key'] ?? '');
        $dataDescriptor = $this->scalar($paymentData['opaque_data_descriptor'] ?? '');
        $dataValue = $this->scalar($paymentData['opaque_data_value'] ?? '');
        if ($apiLoginId === '' || $transactionKey === '' || $dataDescriptor === '' || $dataValue === '') {
            throw new InvalidArgumentException('Authorize.Net credentials and opaque payment data are required.');
        }
        $environment = $configuration['environment'] ?? 'sandbox';
        if (!in_array($environment, ['sandbox', 'production'], true)) {
            throw new InvalidArgumentException('Invalid Authorize.Net environment.');
        }
        $endpoint = $environment === 'production'
            ? 'https://api.authorize.net/xml/v1/request.api'
            : 'https://apitest.authorize.net/xml/v1/request.api';
        $response = $this->client->request('POST', $endpoint, [], [
            'createTransactionRequest' => [
                'merchantAuthentication' => [
                    'name' => $apiLoginId,
                    'transactionKey' => $transactionKey,
                ],
                'transactionRequest' => [
                    'transactionType' => 'authCaptureTransaction',
                    'amount' => $amount->getAmount(),
                    'currencyCode' => 'USD',
                    'payment' => [
                        'opaqueData' => [
                            'dataDescriptor' => $dataDescriptor,
                            'dataValue' => $dataValue,
                        ],
                    ],
                    'order' => [
                        'invoiceNumber' => substr($this->scalar($paymentData['order_number'] ?? ''), 0, 20),
                    ],
                ],
            ],
        ]);
        $result = $response['transactionResponse'] ?? [];
        $result = is_array($result) ? $result : [];
        $responseCode = $this->scalar($result['responseCode'] ?? '');
        $message = $result['messages']['message'][0]['description']
            ?? $response['messages']['message'][0]['text']
            ?? 'Authorize.Net did not approve the transaction.';
        return [
            'status' => match ($responseCode) {
                '1' => 'paid',
                '4' => 'authorized',
                default => 'failed',
            },
            'provider_payment_id' => isset($result['transId']) && is_scalar($result['transId'])
                ? (string) $result['transId']
                : null,
            'message' => $this->scalar($message),
            'raw_response' => $response,
        ];
    }

    private function scalar(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        return trim((string) $value);
    }
}
