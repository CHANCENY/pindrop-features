<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Payment;

use RuntimeException;

class HttpJsonClient
{
    public function request(string $method, string $url, array $headers, ?array $payload = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for payment gateway requests.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Could not initialize the payment gateway request.');
        }

        $requestHeaders = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            $requestHeaders[] = $name . ': ' . $value;
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($payload !== null) {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
            $requestHeaders[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $requestHeaders;
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);
        $responseBody = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if (!is_string($responseBody)) {
            throw new RuntimeException('Payment gateway request failed: ' . ($curlError ?: 'network error'));
        }
        $response = $this->decodeResponse($responseBody);
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('Payment gateway rejected the request (HTTP ' . $statusCode . ').');
        }
        return $response;
    }

    private function decodeResponse(string $responseBody): array
    {
        if (str_starts_with($responseBody, "\xEF\xBB\xBF")) {
            $responseBody = substr($responseBody, 3);
        }
        try {
            $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Payment gateway returned an invalid response.', 0, $exception);
        }
        if (!is_array($response)) {
            throw new RuntimeException('Payment gateway returned an unexpected response.');
        }
        return $response;
    }

}
