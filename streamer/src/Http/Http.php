<?php

namespace Simp\Pindrop\Modules\streamer\src\Http;

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Modules\streamer\src\Config\Config;

class Http
{
    protected array $headers = [];
    protected array $body = [];
    protected string $method = "GET";
    protected mixed $url = "";
    protected array $params = [];

    protected int $responseStatus = 200;
    protected array $responseHeaders = [];
    protected array $responseBody = [];
    protected string $responseTextStatus = "";
    protected string $responseBodyRaw;
    private string $error;

    public function __construct(protected Config $config)
    {
        $this->url = $this->config->get('general.base');
        $this->headers = [
            'accept' => $this->config->get('general.accept'),
            'Authorization' => "Bearer {$this->config->getApiKey()}",
        ];
        $this->params = ['api_key' => $this->config->getApiKey()];
    }

    public function setHeaders(array $headers): Http {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    public function setMethod(string $method): Http {
        $this->method = $method;
        return $this;
    }
    public function setUrl(string $url): Http {
        $this->url = $url;
        return $this;
    }
    public function setBody(array $body): Http {
        $this->body = $body;
        return $this;
    }
    public function setParams(array $params): Http {
        $this->params = array_merge($this->params, $params);
        return $this;
    }
    public function getHeaders(): array {
        return $this->headers;
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    public function getResponseBody(): array{
        return $this->responseBody;
    }

    public function getResponseStatus(): int
    {
        return $this->responseStatus;
    }
    public function getResponseStatusText(): string {
        return $this->responseTextStatus;
    }

    public function request(string $path): Http
    {
        $url = rtrim($this->url, '/') . '/' . ltrim($path, '/');
        $url = $this->parseNamedParams($url);

        if (!empty($this->params)) {
            $url .= '?' . http_build_query($this->params);
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true, // we need headers
            CURLOPT_CUSTOMREQUEST => $this->method,
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_TIMEOUT => 30,
        ];

        if (!empty($this->body)) {
            $options[CURLOPT_POSTFIELDS] = json_encode($this->body);
        }

        $curl = curl_init();
        curl_setopt_array($curl, $options);

        $response = curl_exec($curl);

        if ($response === false) {
            $this->error = curl_error($curl);
            curl_close($curl);
            return $this;
        }

        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $this->responseStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        $rawHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // ✅ Convert headers string into array
        $this->responseHeaders = $this->parseHeaders($rawHeaders);

        // Decode JSON safely
        $decoded = json_decode($body, true);
        $this->responseBody = json_last_error() === JSON_ERROR_NONE
            ? $decoded
            : $body;

        curl_close($curl);

        return $this;
    }

    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];

        $lines = explode("\r\n", trim($rawHeaders));

        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $headers[trim($key)] = trim($value);
            }
        }

        return $headers;
    }

    /**
     * @return $this
     */
    public function clear(): static
    {
        $this->headers = [
            'accept' => $this->config->get('general.accept'),
            'Authorization' => "Bearer {$this->config->getApiKey()}",
        ];
        $this->params = [
            'api_key' => $this->config->getApiKey(),
        ];
        $this->method = "GET";
        $this->url = $this->config->get('general.base');
        $this->responseBody = [];
        $this->responseStatus = 200;
        $this->responseHeaders = [];
        $this->responseTextStatus = "";
        $this->responseBodyRaw = "";
        $this->error = "";
        return $this;
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public static function parseParams(array $params, string $configKey): array
    {
        /**@var Config $configs**/
        $configs = \getAppContainer()->get('streamer.config');
        $paramsKeys = $configs->get($configKey);

        $validated = [];
        foreach ($paramsKeys as $key) {
            if (isset($params[$key])) {
                $validated[$key] = $params[$key];
            }
        }

        return $validated;
    }

    public static function parseBodyFields(array $body, string $configKey): array
    {
        /**@var Config $configs**/
        $configs = \getAppContainer()->get('streamer.config');
        $paramsKeys = $configs->get($configKey);

        $validated = [];
        foreach ($body as $data) {
            $temp = [];
            foreach ($paramsKeys as $key) {
                $temp[$key] = self::getValueRecursive($data, $key,null);
            }
            $validated[] = $temp;
        }
        return $validated;
    }

    private static function getValueRecursive(array $data, string $searchKey, $default = null)
    {
        foreach ($data as $key => $value) {
            if ($key === $searchKey) {
                return $value;
            }

            if (is_array($value)) {
                $found = self::getValueRecursive($value, $searchKey);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return $default;
    }

    private function parseNamedParams(string $url): string
    {
        foreach ($this->params as $key => $value) {
            if (str_contains($url, "[$key]")) {
                $value = is_array($value) ? http_build_query($value) : $value;
                $url = str_replace("[$key]",$value,$url);
                unset($this->params[$key]);
            }
        }
        return $url;
    }

    public static function getValue(array $data,string $configKey): array
    {
        /**@var Config $configs**/
        $configs = \getAppContainer()->get('streamer.config');
        $paramsKeys = $configs->get($configKey);

        $validated = [];
        foreach ($paramsKeys as $segment) {
            $keys = explode(".", $segment);
            foreach ($keys as $key) {
                if (isset($data[$key])) {
                    $validated[$key] = $data[$key];
                }
            }
        }

        return $validated;
    }
}