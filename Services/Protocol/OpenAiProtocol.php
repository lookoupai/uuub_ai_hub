<?php

declare(strict_types=1);

namespace Plugins\uuub_ai_hub\Services\Protocol;

use Framework\Utils\HttpClient;
use Plugins\uuub_ai_hub\Services\AiHubException;
use Plugins\uuub_ai_hub\Support\HttpJson;

/**
 * OpenAI 兼容协议适配器：chat completions 端点。
 * base_url 填服务基地址（官方或中转站，如 https://api.openai.com/v1），
 * 自动拼接 /chat/completions；官方与 one-api/new-api 等中转端点通用。
 */
final class OpenAiProtocol
{
    /**
     * @param array $provider provider 配置（protocol/base_url/api_key/model/timeout）
     * @param array $messages [['role' => 'system|user|assistant', 'content' => '...'], ...]
     * @param array $opt      max_tokens/temperature/json_mode 透传项
     *
     * @return array ['content' => string, 'tokens_in' => int, 'tokens_out' => int]
     */
    public static function chat(HttpClient $http, array $provider, array $messages, array $opt = []): array
    {
        $payload = ['model' => (string)$provider['model'], 'messages' => $messages];
        if (isset($opt['max_tokens'])) {
            $payload['max_tokens'] = (int)$opt['max_tokens'];
        }
        if (isset($opt['temperature'])) {
            $payload['temperature'] = (float)$opt['temperature'];
        }
        if (!empty($opt['json_mode'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        $url = rtrim((string)$provider['base_url'], '/') . '/chat/completions';
        /* 单次调用可覆盖 provider 超时（交互式场景须低于网关如 CF 100s 红线） */
        $provider['timeout'] = (int)($opt['timeout'] ?? $provider['timeout'] ?? 30);
        /* provider 级扩展参数（如 Qwen 系 chat_template_kwargs.enable_thinking=false 关推理提速） */
        if (!empty($provider['extra']) && is_array($provider['extra'])) {
            $payload += $provider['extra'];
        }
        $body = HttpJson::post($http, $provider, $url, $payload);
        $content = $body['choices'][0]['message']['content'] ?? null;
        // 仅缺失 content 键视为协议异常；空字符串放行（思考型模型小 max_tokens 时 reasoning 耗尽 token，content 合法为空）
        if (!is_string($content)) {
            throw new AiHubException('missing_choice_content, finish_reason=' . (string)($body['choices'][0]['finish_reason'] ?? 'unknown'), AiHubException::CODE_PROTOCOL);
        }
        return [
            'content'    => $content,
            'tokens_in'  => (int)($body['usage']['prompt_tokens'] ?? 0),
            'tokens_out' => (int)($body['usage']['completion_tokens'] ?? 0),
        ];
    }

    /**
     * 拉取上游模型清单（GET /models，OpenAI 兼容）：免费元数据探测，不计用量与预算。
     * 聚合站可能返回数百模型：宽容过滤非法项（非字符串/空/超长/含控制字符）后去重。
     *
     * @param array $provider 至少含 base_url/api_key/timeout
     *
     * @return string[] 模型 ID 清单
     */
    public static function listModels(HttpClient $http, array $provider): array
    {
        $url = rtrim((string)$provider['base_url'], '/') . '/models';
        $body = HttpJson::get($http, $provider, $url);
        $models = [];
        foreach ((array)($body['data'] ?? []) as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;
            if (!is_string($id) || $id === '' || strlen($id) > 128 || preg_match('/[\x00-\x1f\x7f]/', $id)) {
                continue;
            }
            $models[] = $id;
        }
        if ($models === []) {
            throw new AiHubException('model list empty (unsupported endpoint or malformed response)', AiHubException::CODE_PROTOCOL);
        }
        return array_values(array_unique($models));
    }

    private function __construct()
    {
    }
}
