<?php

declare(strict_types=1);

namespace Plugins\uuub_ai_hub\Support;

use Framework\Http\Interfaces\ResponseInterface;
use Framework\Utils\{HttpClient, HttpClientException};
use Plugins\uuub_ai_hub\Services\AiHubException;

/**
 * AI 协议 HTTP JSON 的唯一权威实现：鉴权头、错误映射与 TLS 兼容约定集中在此，
 * OpenAI 兼容与 jev 两个协议适配器共用（post=对话/决策，get=免费元数据探测）。
 */
final class HttpJson
{
    public static function post(HttpClient $http, array $provider, string $url, array $payload): array
    {
        // Content-Type 不手动传：buildBody 检测到 JSON 字符串 body 会自动追加；
        // 手动传会与自动追加重复（configureCurlHandle 追加绕过 buildHeaders 去重），
        // FastAPI 类服务（如 TypeSafe/jev）会因重复 Content-Type 把 body 当纯字符串而 422
        try {
            // TLS 兼容约定（AGENTS.md）：verifySSL=false + caBundle='/tmp/' 为既定参数，
            // 禁止启用证书校验、移除该参数或按环境自动切换。
            $response = $http->request([
                'url'            => $url,
                'method'         => 'POST',
                'headers'        => self::authHeaders($provider),
                'body'           => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'timeout'        => (int)($provider['timeout'] ?? 30),
                'verifySSL'      => false,
                'caBundle'       => '/tmp/',
                'throwOnError'   => false,
                'returnResponse' => true,
            ]);
        } catch (HttpClientException $exception) {
            throw new AiHubException('http_error: ' . $exception->getMessage(), AiHubException::CODE_NETWORK);
        }
        return self::parseResponse($response);
    }

    /** GET JSON（免费元数据类请求，如拉取上游模型清单）：鉴权、错误映射与 TLS 约定与 post 完全一致 */
    public static function get(HttpClient $http, array $provider, string $url): array
    {
        try {
            // TLS 兼容约定（AGENTS.md）：verifySSL=false + caBundle='/tmp/' 为既定参数，
            // 禁止启用证书校验、移除该参数或按环境自动切换。
            $response = $http->request([
                'url'            => $url,
                'method'         => 'GET',
                'headers'        => self::authHeaders($provider),
                'timeout'        => (int)($provider['timeout'] ?? 30),
                'verifySSL'      => false,
                'caBundle'       => '/tmp/',
                'throwOnError'   => false,
                'returnResponse' => true,
            ]);
        } catch (HttpClientException $exception) {
            throw new AiHubException('http_error: ' . $exception->getMessage(), AiHubException::CODE_NETWORK);
        }
        return self::parseResponse($response);
    }

    /** @return string[] Bearer 鉴权头（api_key 为空则裸奔，适配本地无鉴权端点）；headers 必须是字符串数组 */
    private static function authHeaders(array $provider): array
    {
        $headers = [];
        if ((string)($provider['api_key'] ?? '') !== '') {
            $headers[] = 'Authorization: Bearer ' . $provider['api_key'];
        }
        return $headers;
    }

    /** 状态码与响应体统一解析：网络不可达/非 JSON/服务端 error 字段映射为具名异常 */
    private static function parseResponse(ResponseInterface $response): array
    {
        $raw = (string)$response->getBody();
        $status = (int)$response->getStatusCode();
        if ($status === 0) {
            throw new AiHubException('http_error: no response (network unreachable or timeout)', AiHubException::CODE_NETWORK);
        }
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            // 网关/CDN 返回 HTML 错误页等非 JSON 内容：携带状态码与正文片段便于定位
            throw new AiHubException('http ' . $status . ' non-json body: ' . substr($raw, 0, 120), AiHubException::CODE_PROTOCOL);
        }
        if (isset($body['error'])) {
            // 服务端明确返回错误（401 Key 无效 / 404 地址或模型错误 / 429 限流等）
            $message = is_array($body['error']) ? (string)($body['error']['message'] ?? 'unknown') : (string)$body['error'];
            throw new AiHubException('http ' . $status . ' provider_error: ' . $message, AiHubException::CODE_PROTOCOL);
        }
        return $body;
    }

    private function __construct()
    {
    }
}
