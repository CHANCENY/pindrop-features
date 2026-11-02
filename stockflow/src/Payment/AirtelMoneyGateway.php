<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Payment;

use InvalidArgumentException;
use RuntimeException;
use Simp\Price\lib\Price;

final class AirtelMoneyGateway implements PaymentGatewayInterface
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
        if (strtoupper($currency) !== 'MWK') {
            throw new InvalidArgumentException('Airtel Money is configured for Malawi kwacha (MWK).');
        }
        $merchantCode = $this->scalar($configuration['merchant_code'] ?? '');
        $clientId = $this->scalar($credentials['client_id'] ?? '');
        $clientSecret = $this->scalar($credentials['client_secret'] ?? '');
        $apiKey = $this->scalar($credentials['api_key'] ?? '');
        $phone = preg_replace('/\D+/', '', $this->scalar($paymentData['phone'] ?? ''));
        $transactionId = $this->scalar($paymentData['transaction_id'] ?? $paymentData['order_number'] ?? '');
        if (is_string($phone) && str_starts_with($phone, '265')) {
            $phone = substr($phone, 3);
        }
        if (is_string($phone) && str_starts_with($phone, '0')) {
            $phone = substr($phone, 1);
        }
        if ($merchantCode === '' || $clientId === '' || $clientSecret === '' || $apiKey === '') {
            throw new InvalidArgumentException('Airtel Money merchant and API credentials are required.');
        }
        if (!is_string($phone) || !preg_match('/^[0-9]{9}$/', $phone) || $transactionId === '') {
            throw new InvalidArgumentException('Airtel Money requires a valid Malawi phone number and transaction ID.');
        }
        $environment = $configuration['environment'] ?? 'sandbox';
        if (!in_array($environment, ['sandbox', 'production'], true)) {
            throw new InvalidArgumentException('Invalid Airtel Money environment.');
        }
        $baseUrl = $environment === 'production'
            ? 'https://openapi.airtel.africa'
            : 'https://openapiuat.airtel.africa';
        $tokenResponse = $this->client->request('POST', $baseUrl . '/auth/oauth2/token', [], [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
        ]);
        $accessToken = (string) ($tokenResponse['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Airtel Money did not return an access token.');
        }
        $response = $this->client->request('POST', $baseUrl . '/merchant/v1/payments/', [
            'Authorization' => 'Bearer ' . $accessToken,
            'X-Country' => 'MW',
            'X-Currency' => 'MWK',
            'X-API-Key' => $apiKey,
        ], [
            'reference' => $merchantCode,
            'subscriber' => [
                'country' => 'MW',
                'currency' => 'MWK',
                'msisdn' => $phone,
            ],
            'transaction' => [
                'amount' => number_format($amount->getAmount(), 2, '.', ''),
                'country' => 'MW',
                'currency' => 'MWK',
                'id' => $transactionId,
            ],
        ]);
        $transaction = $response['data']['transaction'] ?? [];
        $transaction = is_array($transaction) ? $transaction : [];
        $statusData = $response['status'] ?? [];
        $statusData = is_array($statusData) ? $statusData : [];
        $providerStatus = strtoupper($this->scalar($transaction['status'] ?? ''));
        return [
            'status' => match ($providerStatus) {
                'TS' => 'paid',
                'TIP', 'TA' => 'pending',
                default => 'failed',
            },
            'provider_payment_id' => isset($transaction['id']) && is_scalar($transaction['id'])
                ? (string) $transaction['id']
                : null,
            'message' => $this->scalar(
                $transaction['message'] ?? $statusData['message'] ?? 'Airtel Money payment request submitted.'
            ),
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
