<?php
declare(strict_types=1);

!defined('DEBUG') and exit('Forbidden');

use Framework\Utils\{DirectoryHelper, FileHelper};

// AI 用量明细日志表：每次真实发出的 AI 调用记录一条，供用量看板与失败诊断回查
$db = $this->container->get(\Framework\Database\Interfaces\DatabaseInterface::class);
set_time_limit(0);

if (!$db->findTable('ai_usage_log')) {
    $sql = "CREATE TABLE `{$db->prefix}ai_usage_log` (
        `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, -- 主键ID
        `consumer` varchar(64) NOT NULL DEFAULT '', -- 消费插件标识，如 uub_sms_bazaar
        `slot` varchar(32) NOT NULL DEFAULT '', -- 用途槽位：intent/extract/translate
        `method` tinyint(1) NOT NULL DEFAULT 0, -- 调用方法(0:chat 1:json 2:decide)
        `protocol` tinyint(1) NOT NULL DEFAULT 0, -- 协议(0:openai兼容 1:jev)
        `provider` varchar(64) NOT NULL DEFAULT '', -- provider 配置键
        `model` varchar(128) NOT NULL DEFAULT '', -- 模型名快照
        `tokens_in` int(11) UNSIGNED NOT NULL DEFAULT 0, -- 输入 token 数(jev 无 token 计 0)
        `tokens_out` int(11) UNSIGNED NOT NULL DEFAULT 0, -- 输出 token 数
        `latency_ms` int(11) UNSIGNED NOT NULL DEFAULT 0, -- 调用耗时毫秒
        `status` tinyint(1) NOT NULL DEFAULT 0, -- 结果(0:成功 1:失败)
        `error_code` tinyint(1) NOT NULL DEFAULT 0, -- 失败原因(0:无 1:预算 2:配置 3:网络 4:协议 5:schema)
        `ref` varchar(64) NOT NULL DEFAULT '', -- 业务关联号，如 ingest ID
        `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0, -- 创建时间
        PRIMARY KEY (`id`),
        KEY `idx_aulog_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $db->exec($sql);
}

// AI 日用量计数表：以日为键的冗余计数，预算熔断与看板读此表，避免对明细表实时 COUNT
if (!$db->findTable('ai_daily_usage')) {
    $sql = "CREATE TABLE `{$db->prefix}ai_daily_usage` (
        `day` int(11) UNSIGNED NOT NULL DEFAULT 0, -- 日键，Ymd 格式如 20261024
        `calls` int(11) UNSIGNED NOT NULL DEFAULT 0, -- 当日发起的 API 调用次数(含失败)
        `errors` int(11) UNSIGNED NOT NULL DEFAULT 0, -- 当日失败次数
        `tokens_in` int(11) UNSIGNED NOT NULL DEFAULT 0, -- 当日输入 token 累计
        `tokens_out` int(11) UNSIGNED NOT NULL DEFAULT 0, -- 当日输出 token 累计
        PRIMARY KEY (`day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $db->exec($sql);
}

// 中枢配置文件：无 provider、槽位未绑定（连接+模型对，model 空=默认模型）、日预算 0=不限；API 密钥仅存此文件(0600)
$configFile = APP_PATH . 'plugins/uuub_ai_hub/config/ai.php';
if (!is_file($configFile)) {
    DirectoryHelper::mkdir(dirname($configFile));
    $default = [
        'providers' => [],
        'slots' => [
            'intent' => ['provider' => '', 'model' => ''],
            'extract' => ['provider' => '', 'model' => ''],
            'translate' => ['provider' => '', 'model' => ''],
            'translate_light' => ['provider' => '', 'model' => ''],
        ],
        'budget' => ['daily_calls' => 0, 'daily_tokens' => 0],
    ];
    $bytes = "<?php\n\nreturn " . var_export($default, true) . ";\n";
    if (FileHelper::filePutContentsTry($configFile, $bytes) !== strlen($bytes)) {
        throw new \RuntimeException('uuub_ai_hub_install_config_write_failed');
    }
}
chmod($configFile, 0600);
