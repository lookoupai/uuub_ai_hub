<?php

declare(strict_types=1);

namespace Plugins\uuub_ai_hub\Services;

use Framework\Core\Container;
use Framework\Utils\HttpClient;
use Plugins\uuub_ai_hub\Services\Protocol\{JevProtocol, OpenAiProtocol};
use Plugins\uuub_ai_hub\Support\AiHubCode;

/**
 * AI 中枢门面：消费插件（如 uub_sms_bazaar、后续翻译插件）统一经此调用 AI，
 * 不得绕过中枢自行实现 AI HTTP 连接。
 *
 * - chat()：OpenAI 兼容文本补全
 * - jsonChat()：结构化输出，schema 校验失败自动带错误重试一次，仍失败抛 CODE_SCHEMA（消费方转人审）
 * - decide()：jev 类型化判断，status=needs_review 即协议内置的人审信号
 *
 * 所有真实发出的调用记入 ai_usage_log 并累计 ai_daily_usage；
 * 日预算耗尽抛 CODE_BUDGET，槽位/连接缺失抛 CODE_CONFIG，消费方应捕获后降级，不影响主业务。
 *
 * $opt 公共项：consumer（消费插件标识）、ref（业务关联号）、max_tokens、temperature；
 * decide() 另支持 min_probability / min_margin（默认 0.8 / 0.15）。
 */
final class AiHubService
{
    /** @var AiHubSettingsService */
    private $settings;

    /** @var AiUsageLogService */
    private $usageLog;

    /** @var AiDailyUsageService */
    private $dailyUsage;

    /** @var HttpClient */
    private $http;

    public function __construct(Container $container)
    {
        $this->settings = $container->get(AiHubSettingsService::class);
        $this->usageLog = $container->get(AiUsageLogService::class);
        $this->dailyUsage = $container->get(AiDailyUsageService::class);
        $this->http = $container->get(HttpClient::class);
    }

    /** @return string 模型回复文本 */
    public function chat(string $slot, string $systemPrompt, string $userPrompt, array $opt = []): string
    {
        $messages = [];
        if ($systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $userPrompt];
        return $this->dispatchOpenAi($slot, $this->binding($slot, 'openai'), $messages, AiHubCode::METHOD_CHAT, $opt)['content'];
    }

    /**
     * @param array $schema 简化 schema 合同：['fields' => [key => 'string|int|float|bool|array'], 'required' => [key, ...]]
     *
     * @return array 通过校验的 JSON 对象
     */
    public function jsonChat(string $slot, string $systemPrompt, string $userPrompt, array $schema, array $opt = []): array
    {
        $schema = $this->assertSchemaContract($schema);
        $instruction = 'Respond with ONLY a JSON object (no markdown fences). Keys and types: '
            . json_encode($schema['fields'], JSON_UNESCAPED_UNICODE) . '.'
            . ($schema['required'] === [] ? '' : ' Required keys: ' . json_encode($schema['required'], JSON_UNESCAPED_UNICODE) . '.')
            . ' Use null for unknown values.';
        $attemptError = '';
        /* 校验失败带错误重试；attempts 可按场景收紧（交互式预览 1 次，避免超出网关回源超时） */
        $maxAttempts = max(1, min(2, (int)($opt['attempts'] ?? 2)));
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $messages = [];
            if ($systemPrompt !== '') {
                $messages[] = ['role' => 'system', 'content' => $systemPrompt];
            }
            $messages[] = ['role' => 'user', 'content' => $userPrompt . "\n\n" . $instruction];
            if ($attemptError !== '') {
                $messages[] = ['role' => 'user', 'content' => 'Your previous answer was invalid: ' . $attemptError . ' Answer again with corrected JSON only.'];
            }
            $result = $this->dispatchOpenAi($slot, $this->binding($slot, 'openai'), $messages, AiHubCode::METHOD_JSON, $opt + ['json_mode' => true]);
            $decoded = $this->extractJson($result['content']);
            if ($decoded !== null) {
                $error = $this->validateSchema($decoded, $schema);
                if ($error === '') {
                    return $decoded;
                }
                $attemptError = $error;
                continue;
            }
            $attemptError = 'response was not valid JSON';
        }
        throw new AiHubException('schema_validation_failed: ' . $attemptError, AiHubException::CODE_SCHEMA);
    }

    /**
     * @param array $request ['state' => mixed, 'questions' => [qid => question], 'model' => 可选覆盖]
     *
     * @return array [qid => ['status' => 'selected|needs_review|scored', 'value' => mixed, 'probability' => float|null, 'margin' => float|null]]
     */
    public function decide(string $slot, array $request, array $opt = []): array
    {
        if (empty($request['questions']) || !is_array($request['questions'])) {
            throw new \InvalidArgumentException('decide requires a non-empty questions map');
        }
        $binding = $this->binding($slot, 'jev');
        $this->assertBudget();
        $started = microtime(true);
        try {
            $decisions = JevProtocol::decide($this->http, $binding['config'], $request, $opt);
        } catch (AiHubException $exception) {
            $this->record($slot, $binding, AiHubCode::METHOD_DECIDE, 0, 0, $started, AiHubCode::STATUS_ERROR, $exception->getCode(), $opt);
            throw $exception;
        }
        $this->record($slot, $binding, AiHubCode::METHOD_DECIDE, 0, 0, $started, AiHubCode::STATUS_OK, 0, $opt);
        return $decisions;
    }

    /**
     * 连通性测试：真实调用一次目标连接（默认模型），计入用量与预算。
     * $override 非空项覆盖存储配置（base_url/api_key/model）：后台「测试当前模型」
     * 按表单所见即所测，未保存的新模型也能立即验证；api_key 留空沿用存储值
     * （与 saveProvider/fetchModels 的留空语义一致）。
     *
     * @return array ['latency_ms' => int]
     */
    public function testProvider(string $providerKey, array $override = []): array
    {
        $config = $this->settings->provider($providerKey);
        foreach (['base_url', 'api_key', 'model'] as $field) {
            $value = (string)($override[$field] ?? '');
            if ($value !== '') {
                $config[$field] = $value;
            }
        }
        $binding = ['key' => $providerKey, 'config' => $config];
        $opt = ['consumer' => 'uuub_ai_hub'];
        if ($binding['config']['protocol'] === 'jev') {
            $this->assertBudget();
            $started = microtime(true);
            try {
                JevProtocol::decide($this->http, $binding['config'], [
                    'state' => 'connectivity test',
                    'questions' => ['ok' => ['type' => 'noul', 'instructions' => 'Is one plus one two?']],
                ], $opt);
            } catch (AiHubException $exception) {
                $this->record('test', $binding, AiHubCode::METHOD_DECIDE, 0, 0, $started, AiHubCode::STATUS_ERROR, $exception->getCode(), $opt);
                throw $exception;
            }
            $this->record('test', $binding, AiHubCode::METHOD_DECIDE, 0, 0, $started, AiHubCode::STATUS_OK, 0, $opt);
            return ['latency_ms' => (int)round((microtime(true) - $started) * 1000)];
        }
        $started = microtime(true);
        // 16 token 足以验证连通性：思考型模型烧完配额返回空 content 亦视为连通（协议层已放行），
        // 更小的配额让慢源站有机会在网关（如 Cloudflare 100s）超时前回包
        $this->dispatchOpenAi('test', $binding, [['role' => 'user', 'content' => 'ping']], AiHubCode::METHOD_CHAT, $opt + ['max_tokens' => 16]);
        return ['latency_ms' => (int)round((microtime(true) - $started) * 1000)];
    }

    /**
     * 拉取连接上游的模型清单（GET /models）：免费元数据探测，充当快速连通性校验，
     * 不记用量不计预算（区别于 testProvider 的真实推理调用）。
     *
     * @param array $input ['providerKey' => 可选编辑中的连接键, 'base_url' => 表单值, 'api_key' => 表单值]
     *                     api_key 或 base_url 留空且 providerKey 存在时沿用存储配置（密钥留空=沿用原值语义）
     *
     * @return array ['models' => string[], 'latency_ms' => int]
     */
    public function fetchModels(array $input): array
    {
        $baseUrl = trim((string)($input['base_url'] ?? ''));
        $apiKey = (string)($input['api_key'] ?? '');
        $providerKey = (string)($input['providerKey'] ?? '');
        if (($apiKey === '' || $baseUrl === '') && $providerKey !== '') {
            $stored = $this->settings->provider($providerKey);
            $baseUrl = $baseUrl === '' ? (string)$stored['base_url'] : $baseUrl;
            $apiKey = $apiKey === '' ? (string)$stored['api_key'] : $apiKey;
        }
        if (!AiHubSettingsService::isValidBaseUrl($baseUrl)) {
            throw new AiHubException('invalid base_url: ' . $baseUrl, AiHubException::CODE_CONFIG);
        }
        $started = microtime(true);
        $models = OpenAiProtocol::listModels($this->http, ['base_url' => $baseUrl, 'api_key' => $apiKey, 'timeout' => 30]);
        return ['models' => $models, 'latency_ms' => (int)round((microtime(true) - $started) * 1000)];
    }

    /** @return array ['key' => ..., 'config' => ...] 并校验槽位绑定的协议 */
    private function binding(string $slot, string $protocol): array
    {
        $binding = $this->settings->slotBinding($slot);
        if ($binding['config']['protocol'] !== $protocol) {
            throw new AiHubException('slot_protocol_mismatch: ' . $slot, AiHubException::CODE_CONFIG);
        }
        return $binding;
    }

    /** @return array ['content' => ..., 'tokens_in' => ..., 'tokens_out' => ...] */
    private function dispatchOpenAi(string $slot, array $binding, array $messages, int $method, array $opt): array
    {
        $this->assertBudget();
        $started = microtime(true);
        try {
            $result = OpenAiProtocol::chat($this->http, $binding['config'], $messages, $opt);
        } catch (AiHubException $exception) {
            $this->record($slot, $binding, $method, 0, 0, $started, AiHubCode::STATUS_ERROR, $exception->getCode(), $opt);
            throw $exception;
        }
        $this->record($slot, $binding, $method, $result['tokens_in'], $result['tokens_out'], $started, AiHubCode::STATUS_OK, 0, $opt);
        return $result;
    }

    private function assertBudget(): void
    {
        $budget = $this->settings->configuration()['budget'];
        $dailyCalls = (int)($budget['daily_calls'] ?? 0);
        $dailyTokens = (int)($budget['daily_tokens'] ?? 0);
        if ($dailyCalls <= 0 && $dailyTokens <= 0) {
            return;
        }
        $usage = $this->dailyUsage->readDay((int)date('Ymd'));
        if ($dailyCalls > 0 && (int)($usage['calls'] ?? 0) >= $dailyCalls) {
            throw new AiHubException('daily_call_budget_exhausted', AiHubException::CODE_BUDGET);
        }
        if ($dailyTokens > 0 && ((int)($usage['tokens_in'] ?? 0) + (int)($usage['tokens_out'] ?? 0)) >= $dailyTokens) {
            throw new AiHubException('daily_token_budget_exhausted', AiHubException::CODE_BUDGET);
        }
    }

    private function record(string $slot, array $binding, int $method, int $tokensIn, int $tokensOut, float $started, int $status, int $errorCode, array $opt): void
    {
        $now = time();
        $this->usageLog->insert([
            'consumer'   => (string)($opt['consumer'] ?? ''),
            'slot'       => $slot,
            'method'     => $method,
            'protocol'   => $binding['config']['protocol'] === 'jev' ? AiHubCode::PROTOCOL_JEV : AiHubCode::PROTOCOL_OPENAI,
            'provider'   => $binding['key'],
            'model'      => (string)$binding['config']['model'],
            'tokens_in'  => $tokensIn,
            'tokens_out' => $tokensOut,
            'latency_ms' => (int)round((microtime(true) - $started) * 1000),
            'status'     => $status,
            'error_code' => $errorCode,
            'ref'        => (string)($opt['ref'] ?? ''),
            'created_at' => $now,
        ]);
        $this->dailyUsage->bump(
            (int)date('Ymd', $now),
            1,
            $status === AiHubCode::STATUS_OK ? $tokensIn : 0,
            $status === AiHubCode::STATUS_OK ? $tokensOut : 0,
            $status === AiHubCode::STATUS_ERROR ? 1 : 0
        );
    }

    /** @return array|null 剥离 markdown 代码栅栏后解码 JSON 对象，失败返回 null */
    private function extractJson(string $content): ?array
    {
        $text = trim($content);
        if (strpos($text, '```') === 0) {
            $text = trim((string)preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $text));
        }
        $decoded = json_decode($text, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @return string 校验错误描述，空串表示通过 */
    private function validateSchema(array $data, array $schema): string
    {
        foreach ($schema['required'] as $key) {
            if (!array_key_exists($key, $data)) {
                return 'missing key ' . $key;
            }
        }
        foreach ($schema['fields'] as $key => $type) {
            if (!array_key_exists($key, $data) || $data[$key] === null) {
                continue;
            }
            $valid = [
                'string' => 'is_string',
                'int' => 'is_int',
                'float' => 'is_float',
                'bool' => 'is_bool',
                'array' => 'is_array',
            ][$type];
            $value = $data[$key];
            if ($type === 'float' && is_int($value)) {
                continue;
            }
            if (!$valid($value)) {
                return 'key ' . $key . ' must be ' . $type;
            }
        }
        return '';
    }

    /** @return array 归一化后的 schema（补全 required 默认空数组），非法合同抛 InvalidArgumentException */
    private function assertSchemaContract(array $schema): array
    {
        $fields = $schema['fields'] ?? null;
        if (!is_array($fields) || $fields === []) {
            throw new \InvalidArgumentException('schema.fields must be a non-empty map');
        }
        foreach ($fields as $key => $type) {
            if (!in_array($type, ['string', 'int', 'float', 'bool', 'array'], true)) {
                throw new \InvalidArgumentException('schema field type must be string|int|float|bool|array: ' . $key);
            }
        }
        $schema += ['required' => []];
        foreach ($schema['required'] as $key) {
            if (!isset($fields[$key])) {
                throw new \InvalidArgumentException('schema.required key missing in fields: ' . $key);
            }
        }
        return $schema;
    }
}
