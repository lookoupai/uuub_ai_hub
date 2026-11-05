<?php

declare(strict_types=1);

namespace Plugins\uuub_ai_hub\Services;

use Framework\Core\Container;
use Framework\Core\Traits\StatefulTrait;
use Framework\Exception\BusinessException;
use Framework\Utils\FileHelper;

/**
 * AI 中枢配置：providers（OpenAI 兼容 / jev 连接）、slots（用途槽位路由）、
 * budget（日预算，0=不限）。持久化为插件目录下 config/ai.php（0600），
 * 写入采用锁 + 备份 + 临时文件原子替换（对齐 well_cloud_storage 的既有模式）。
 */
final class AiHubSettingsService
{
    use StatefulTrait;

    public const PROTOCOLS = ['openai', 'jev'];
    /** @var array 默认槽位种子（normalizeConfig 播种；槽位开放，任意 [a-z] 开头键可经后台新增） */
    public const DEFAULT_SLOTS = ['intent', 'extract', 'translate', 'translate_light'];

    /** @var Container */
    private $container;

    /** @var array|null 进程内配置缓存；写入后置空重读，Swoole 下由 Runtime::reload() 重置进程 */
    private $config;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /** @return array 完整配置（含 api_key，仅服务端使用，严禁进入任何响应） */
    public function configuration(): array
    {
        if ($this->config === null) {
            $this->config = self::normalizeConfig(is_file(self::configFile()) ? (array)require self::configFile() : []);
        }
        return $this->config;
    }

    /** @return array ['key' => 配置键, 'config' => provider 配置（model 已按槽位选择覆盖）] */
    public function slotBinding(string $slot): array
    {
        $config = $this->configuration();
        if (!isset($config['slots'][$slot])) {
            throw new AiHubException('slot_not_configured: ' . $slot, AiHubException::CODE_CONFIG);
        }
        $binding = $config['slots'][$slot];
        $providerKey = (string)($binding['provider'] ?? '');
        $providerConfig = $this->provider($providerKey);
        /* 槽位选中的模型覆盖连接默认模型（COW 副本，不污染进程缓存）。
           模型清单是管理视图不是运行时闸门：绑定模型被移出清单时仍按绑定调用，
           避免后台改清单瞬间打断正在使用该槽位的业务。 */
        $model = (string)($binding['model'] ?? '');
        if ($model !== '') {
            $providerConfig['model'] = $model;
        }
        return ['key' => $providerKey, 'config' => $providerConfig];
    }

    /** @return array 指定键的 provider 配置 */
    public function provider(string $key): array
    {
        $providers = $this->configuration()['providers'];
        if ($key === '' || !isset($providers[$key])) {
            throw new AiHubException('provider_not_found: ' . $key, AiHubException::CODE_CONFIG);
        }
        return $providers[$key];
    }

    public function saveProvider(string $key, array $input): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $key) !== 1) {
            throw new BusinessException('uuub_ai_hub_invalid_provider_key', 7);
        }
        $this->change(function (array $settings) use ($key, $input): array {
            $previous = $settings['providers'][$key] ?? [];
            // 密钥留空表示沿用原值；表单不回显密钥
            if (($input['api_key'] ?? '') === '' && isset($previous['api_key'])) {
                $input['api_key'] = $previous['api_key'];
            }
            $settings['providers'][$key] = $this->validateProvider($input);
            return $settings;
        });
    }

    public function deleteProvider(string $key): void
    {
        $this->change(function (array $settings) use ($key): array {
            unset($settings['providers'][$key]);
            foreach ($settings['slots'] as $slot => $binding) {
                if ((string)($binding['provider'] ?? '') === $key) {
                    $settings['slots'][$slot] = ['provider' => '', 'model' => ''];
                }
            }
            return $settings;
        });
    }

    /**
     * 槽位开放：任意 [a-z] 开头小写键（≤32 位）均可作为槽位（消费插件自选用途名），
     * 未提交的既有槽保持原值；新键即创建（后台「新增槽位」或消费插件首次绑定）。
     * 槽位绑定为连接+模型对：['provider' => providerKey, 'model' => 模型 ID]，
     * model 空串表示用该连接默认模型。
     *
     * @param array $slots [slot => ['provider' => providerKey, 'model' => 模型 ID]]
     */
    public function saveRouting(array $slots, array $budget): void
    {
        $this->change(function (array $settings) use ($slots, $budget): array {
            /* 提交映射即全量槽位清单：未提交的键移除（默认种子槽由 normalizeConfig 播种永在） */
            $newSlots = [];
            foreach ($slots as $slot => $binding) {
                $slot = (string)$slot;
                if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $slot) !== 1) {
                    throw new BusinessException('uuub_ai_hub_invalid_slot_name', 7);
                }
                $binding = is_array($binding) ? $binding : [];
                $providerKey = (string)($binding['provider'] ?? '');
                $model = (string)($binding['model'] ?? '');
                if ($providerKey !== '') {
                    if (!isset($settings['providers'][$providerKey])) {
                        throw new BusinessException('uuub_ai_hub_unknown_slot_provider', 7);
                    }
                    if ($model !== '' && !in_array($model, (array)$settings['providers'][$providerKey]['models'], true)) {
                        throw new BusinessException('uuub_ai_hub_unknown_slot_model', 7);
                    }
                }
                $newSlots[$slot] = ['provider' => $providerKey, 'model' => $model];
            }
            $settings['slots'] = $newSlots;
            foreach (['daily_calls', 'daily_tokens'] as $field) {
                $value = (int)($budget[$field] ?? 0);
                if ($value < 0 || $value > 1000000000) {
                    throw new BusinessException('uuub_ai_hub_invalid_budget', 7);
                }
                $settings['budget'][$field] = $value;
            }
            return $settings;
        });
    }

    public function validateProvider(array $input): array
    {
        $provider = [];
        $provider['protocol'] = (string)($input['protocol'] ?? '');
        if (!in_array($provider['protocol'], self::PROTOCOLS, true)) {
            throw new BusinessException('uuub_ai_hub_invalid_protocol', 7);
        }
        foreach (['base_url', 'api_key', 'model'] as $field) {
            $value = $input[$field] ?? '';
            if (!is_string($value) || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new BusinessException('uuub_ai_hub_invalid_provider', 7);
            }
            $provider[$field] = trim($value);
        }
        // 误带 Bearer 前缀时剥离：Authorization 头由 HttpJson 统一添加
        if (stripos($provider['api_key'], 'bearer ') === 0) {
            $provider['api_key'] = trim(substr($provider['api_key'], 7));
        }
        if (!self::isValidBaseUrl($provider['base_url'])) {
            throw new BusinessException('uuub_ai_hub_invalid_base_url', 7);
        }
        if ($provider['model'] === '' || strlen($provider['model']) > 128 || strlen($provider['base_url']) > 255) {
            throw new BusinessException('uuub_ai_hub_invalid_model', 7);
        }
        /* 模型清单校验与不变式收敛：models 非空、去重保序、每项 1~128 位无控制字符；
           默认 model 必须在清单内（清单为权威，model 不在时取首项），jev/旧数据仅填
           model 时清单 = [model] */
        $models = [];
        foreach ((array)($input['models'] ?? []) as $item) {
            if (!is_string($item) || preg_match('/[\x00-\x1f\x7f]/', $item)) {
                throw new BusinessException('uuub_ai_hub_invalid_model', 7);
            }
            $item = trim($item);
            if ($item === '' || strlen($item) > 128 || in_array($item, $models, true)) {
                continue;
            }
            $models[] = $item;
        }
        if ($models === []) {
            $models[] = $provider['model'];
        }
        if (!in_array($provider['model'], $models, true)) {
            $provider['model'] = $models[0];
        }
        $provider['models'] = $models;
        $provider['timeout'] = (int)($input['timeout'] ?? 30);
        if ($provider['timeout'] < 5 || $provider['timeout'] > 120) {
            throw new BusinessException('uuub_ai_hub_invalid_timeout', 7);
        }
        /* 可选扩展参数（透传到请求体，如 Qwen 的 chat_template_kwargs）；仅接受扁平 JSON 安全数组 */
        $extra = $input['extra'] ?? [];
        $provider['extra'] = is_array($extra) ? $extra : [];
        return $provider;
    }

    /** @return bool base_url 合法（http/https 且带 host，禁止 userinfo/query/fragment）；保存与拉取模型共用 */
    public static function isValidBaseUrl(string $url): bool
    {
        $parts = parse_url($url);
        return $parts !== false
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment'])
            && preg_match('/[\s]/', $parts['host']) !== 1;
    }

    private static function configFile(): string
    {
        return APP_PATH . 'plugins/uuub_ai_hub/config/ai.php';
    }

    /** @return array 补全缺省键，容忍配置文件早期形态（单模型 provider / 字符串槽位绑定） */
    private static function normalizeConfig(array $config): array
    {
        $config += [
            'providers' => [],
            'slots' => [],
            'budget' => ['daily_calls' => 0, 'daily_tokens' => 0],
        ];
        /* 旧 schema（provider 仅有单 model 字符串）读取时播种 models 清单，默认模型居首；
           保存时经 validateProvider 落盘为 v2 结构，纯读取路径无需写回 */
        if (!is_array($config['providers'])) {
            $config['providers'] = [];
        }
        foreach ($config['providers'] as $key => $provider) {
            if (!is_array($provider)) {
                continue;
            }
            $models = is_array($provider['models'] ?? null) ? $provider['models'] : [];
            $model = (string)($provider['model'] ?? '');
            if ($model !== '' && !in_array($model, $models, true)) {
                array_unshift($models, $model);
            }
            $provider['models'] = $models;
            $config['providers'][$key] = $provider;
        }
        if (!is_array($config['slots'])) {
            $config['slots'] = [];
        }
        /* 槽位开放：默认种子逐键补缺（旧配置文件缺新槽时以未绑定补位），
           配置里已有的任意自定义槽原样保留；读取方（后台路由页）遍历配置键渲染 */
        foreach (self::DEFAULT_SLOTS as $slot) {
            $config['slots'][$slot] = $config['slots'][$slot] ?? '';
        }
        /* 槽位绑定统一为 ['provider' => ..., 'model' => ...]：旧字符串形态迁移为
           连接+该连接默认模型；连接已不存在（手改残留）重置为空对（运行时与未绑定
           同为 provider_not_found，见 slotBinding/provider()） */
        foreach ($config['slots'] as $slot => $binding) {
            if (is_array($binding)) {
                $config['slots'][$slot] = ['provider' => (string)($binding['provider'] ?? ''), 'model' => (string)($binding['model'] ?? '')];
                continue;
            }
            $providerKey = (string)$binding;
            $config['slots'][$slot] = isset($config['providers'][$providerKey])
                ? ['provider' => $providerKey, 'model' => (string)$config['providers'][$providerKey]['model']]
                : ['provider' => '', 'model' => ''];
        }
        /* 注意：全数字 provider 键（如 "911888"）在 require 与数组赋值时都会被 PHP
           规范化为 int，字符串键根本存不住。保存/解析路径走 isset 强制转换不受影响；
           唯一受影响的是严格比较（===），已在使用方（控制器渲染）做 (string) 归一。 */
        return $config;
    }

    private function change(callable $change): void
    {
        $file = self::configFile();
        if (!is_file($file)) {
            throw new \RuntimeException('uuub_ai_hub_config_missing');
        }
        if (!FileHelper::lock($file, 30)) {
            throw new \RuntimeException('uuub_ai_hub_settings_updating');
        }
        try {
            // 写入期间重读同一来源，防止并发保存互相覆盖
            $settings = self::normalizeConfig((array)require $file);
            $settings = $change($settings);
            $backup = $file . '.backup.php';
            if (!FileHelper::copy($file, $backup) || !chmod($backup, 0600)) {
                throw new \RuntimeException('uuub_ai_hub_settings_backup_failed');
            }
            $temporary = $file . '.pending-' . bin2hex(random_bytes(6)) . '.php';
            try {
                $bytes = "<?php\n\nreturn " . var_export($settings, true) . ";\n";
                if (FileHelper::filePutContentsTry($temporary, $bytes) !== strlen($bytes) || !chmod($temporary, 0600)
                    || !rename($temporary, $file)) {
                    throw new \RuntimeException('uuub_ai_hub_settings_write_failed');
                }
            } finally {
                if (is_file($temporary)) {
                    FileHelper::unlink($temporary);
                }
            }
        } finally {
            FileHelper::unlock($file);
            $this->config = null;
        }
        \App\Core\Compile::flushCache();
        \Framework\Utils\Runtime::reload();
    }
}
