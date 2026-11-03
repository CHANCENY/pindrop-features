<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Payment;

use InvalidArgumentException;
use RuntimeException;
use Simp\Pindrop\Settings\Settings;
use Simp\Price\lib\Price;
use SodiumException;
use Symfony\Component\Yaml\Yaml;

final class PaymentMethodRegistry
{
    private const SETTINGS_KEY = 'stockflow.payment_methods';

    public function __construct(private readonly Settings $settings)
    {
    }

    public function getMethods(): array
    {
        $configured = $this->storedMethods();
        $methods = [];
        foreach ($this->definitions() as $id => $definition) {
            $saved = $configured[$id] ?? [];
            $methods[] = [
                'id' => $id,
                'label' => $definition['label'],
                'type' => $definition['type'] ?? 'gateway',
                'enabled' => (bool) ($saved['enabled'] ?? $definition['default_enabled'] ?? false),
                'configured' => ($definition['type'] ?? '') === 'offline'
                    || $this->hasRequiredCredentials($definition, $saved['credentials'] ?? []),
                'currencies' => $definition['currencies'] ?? [],
                'payment_fields' => $definition['payment_fields'] ?? [],
                'required_payment_data' => $definition['required_payment_data'] ?? [],
                'settings' => $definition['settings'] ?? [],
                'values' => $this->publicValues($definition, $saved['values'] ?? []),
            ];
        }
        return $methods;
    }

    public function authorizeNetClientConfiguration(): array
    {
        $saved = $this->storedMethods()['authorize_net'] ?? [];
        return [
            'api_login_id' => isset($saved['credentials']['api_login_id'])
                ? $this->decrypt($saved['credentials']['api_login_id'])
                : '',
            'client_key' => (string) ($saved['values']['client_key'] ?? ''),
            'environment' => (string) ($saved['values']['environment'] ?? 'sandbox'),
        ];
    }

    public function getMethod(string $id): ?array
    {
        $definition = $this->definitions()[$id] ?? null;
        if ($definition === null) {
            return null;
        }
        $saved = $this->storedMethods()[$id] ?? [];
        return [
            'id' => $id,
            'label' => $definition['label'],
            'type' => $definition['type'] ?? 'gateway',
            'enabled' => (bool) ($saved['enabled'] ?? $definition['default_enabled'] ?? false),
            'configured' => ($definition['type'] ?? '') === 'offline'
                || $this->hasRequiredCredentials($definition, $saved['credentials'] ?? []),
            'settings' => $definition['settings'] ?? [],
            'values' => $this->publicValues($definition, $saved['values'] ?? []),
        ];
    }

    public function isOffline(string $id): bool
    {
        return ($this->definitions()[$id]['type'] ?? '') === 'offline';
    }

    public function supportsCurrency(string $id, string $currency): bool
    {
        $definition = $this->definitions()[$id] ?? null;
        if ($definition === null) {
            return false;
        }
        $currencies = $definition['currencies'] ?? [];
        return in_array('*', $currencies, true)
            || in_array(strtoupper($currency), $currencies, true);
    }

    public function saveMethod(string $id, array $data): void
    {
        $definition = $this->definitions()[$id] ?? null;
        if ($definition === null) {
            throw new InvalidArgumentException('Payment method not found.');
        }
        $all = $this->storedMethods();
        $saved = $all[$id] ?? ['enabled' => false, 'values' => [], 'credentials' => []];
        $enabled = $data['enabled'] ?? 'disabled';
        if (!is_scalar($enabled)) {
            throw new InvalidArgumentException('Select a valid payment method status.');
        }
        if (!in_array((string) $enabled, ['enabled', 'disabled'], true)) {
            throw new InvalidArgumentException('Select a valid payment method status.');
        }
        foreach ($definition['settings'] ?? [] as $field) {
            $name = $field['name'];
            $rawValue = $data[$name] ?? $saved['values'][$name] ?? $field['default'] ?? '';
            if (!is_scalar($rawValue) && $rawValue !== null) {
                throw new InvalidArgumentException($field['label'] . ' must be a single value.');
            }
            $value = trim((string) ($rawValue ?? ''));
            if (!empty($field['secret'])) {
                if ($value !== '') {
                    $saved['credentials'][$name] = $this->encrypt($value);
                }
                if (!empty($field['required']) && empty($saved['credentials'][$name])) {
                    throw new InvalidArgumentException($field['label'] . ' is required before this payment method can be enabled.');
                }
                continue;
            }
            if ($value === '') {
                $value = (string) ($saved['values'][$name] ?? $field['default'] ?? '');
            }
            if (($field['type'] ?? '') === 'select'
                && !array_key_exists($value, $field['options'] ?? [])) {
                throw new InvalidArgumentException('Select a valid ' . strtolower($field['label']) . '.');
            }
            if (!empty($field['required']) && $value === '') {
                throw new InvalidArgumentException($field['label'] . ' is required.');
            }
            $saved['values'][$name] = $value;
        }
        $saved['enabled'] = $enabled === 'enabled';
        if ($saved['enabled']
            && ($definition['type'] ?? '') !== 'offline'
            && !$this->hasRequiredCredentials($definition, $saved['credentials'] ?? [])) {
            throw new InvalidArgumentException('Configure all required credentials before enabling this payment method.');
        }
        $all[$id] = $saved;
        $this->settings->createSetting(self::SETTINGS_KEY, $all);
    }

    public function charge(string $id, Price $amount, string $currency, array $paymentData): array
    {
        $this->validateCharge($id, $currency, $paymentData);
        $definition = $this->definitions()[$id] ?? null;
        $saved = $this->storedMethods()[$id] ?? [];
        if ($amount->getCurrency()->getCode() !== strtoupper($currency)
            || !is_finite($amount->getAmount())
            || !$amount->isGreaterThan(0.0)) {
            throw new InvalidArgumentException('Payment amount must be positive and use the selected currency.');
        }
        $class = $definition['gateway'] ?? '';
        if (($definition['type'] ?? '') === 'offline') {
            throw new InvalidArgumentException('Offline payment methods are collected by an administrator and cannot be charged online.');
        }
        if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, PaymentGatewayInterface::class)) {
            throw new RuntimeException('Payment method gateway must implement PaymentGatewayInterface.');
        }
        $credentials = [];
        foreach ($saved['credentials'] ?? [] as $name => $encrypted) {
            $credentials[$name] = $this->decrypt($encrypted);
        }
        $gateway = new $class();
        return $gateway->charge($amount, strtoupper($currency), $paymentData, $credentials, $saved['values'] ?? []);
    }

    public function validateCharge(string $id, string $currency, array $paymentData): void
    {
        $definition = $this->definitions()[$id] ?? null;
        $saved = $this->storedMethods()[$id] ?? [];
        if (
            $definition === null
            || !($saved['enabled'] ?? $definition['default_enabled'] ?? false)
        ) {
            throw new InvalidArgumentException('Selected payment method is not enabled.');
        }
        if (!$this->supportsCurrency($id, $currency)) {
            throw new InvalidArgumentException('Selected payment method does not support this currency.');
        }
        if (!$this->hasRequiredCredentials($definition, $saved['credentials'] ?? [])) {
            throw new InvalidArgumentException('Selected payment method is missing required credentials.');
        }
        if (($definition['type'] ?? '') === 'offline') {
            return;
        }
        $class = $definition['gateway'] ?? '';
        if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, PaymentGatewayInterface::class)) {
            throw new RuntimeException('Payment method gateway must implement PaymentGatewayInterface.');
        }
        foreach ($saved['credentials'] ?? [] as $encrypted) {
            $this->decrypt($encrypted);
        }
        foreach ($definition['required_payment_data'] ?? [] as $field) {
            $value = $paymentData[$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('Payment field ' . str_replace('_', ' ', $field) . ' is required.');
            }
        }
    }

    private function definitions(): array
    {
        $path = dirname(__DIR__, 2) . '/payments.yml';
        $configuration = Yaml::parseFile($path);
        $methods = $configuration['methods'] ?? null;
        if (!is_array($methods)) {
            throw new RuntimeException('Stockflow payments.yml must define a methods map.');
        }
        return $methods;
    }

    private function storedMethods(): array
    {
        $stored = $this->settings->getSetting(self::SETTINGS_KEY)?->getValue() ?? [];
        return is_array($stored) ? $stored : [];
    }

    private function publicValues(array $definition, array $savedValues): array
    {
        $values = [];
        foreach ($definition['settings'] ?? [] as $field) {
            if (empty($field['secret'])) {
                $values[$field['name']] = $savedValues[$field['name']] ?? $field['default'] ?? '';
            }
        }
        return $values;
    }

    private function hasRequiredCredentials(array $definition, array $credentials): bool
    {
        foreach ($definition['settings'] ?? [] as $field) {
            if (!empty($field['secret']) && !empty($field['required'])
                && empty($credentials[$field['name']])) {
                return false;
            }
        }
        return true;
    }

    private function encrypt(string $value): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($value, $nonce, $this->encryptionKey());
        return base64_encode($nonce . $ciphertext);
    }

    private function decrypt(string $value): string
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Stored payment credential cannot be decrypted.');
        }
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        try {
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->encryptionKey());
        } catch (SodiumException $exception) {
            throw new RuntimeException('Stored payment credential cannot be decrypted.', 0, $exception);
        }
        if ($plaintext === false) {
            throw new RuntimeException('Stored payment credential cannot be decrypted; check PAYMENT_CONFIG_ENCRYPTION_KEY.');
        }
        return $plaintext;
    }

    private function encryptionKey(): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException('The PHP Sodium extension is required to store payment credentials securely.');
        }
        $configuredKey = getenv('PAYMENT_CONFIG_ENCRYPTION_KEY');
        if (!is_string($configuredKey) || trim($configuredKey) === '') {
            throw new RuntimeException('Set PAYMENT_CONFIG_ENCRYPTION_KEY before saving or using payment credentials.');
        }
        return hash('sha256', $configuredKey, true);
    }
}
