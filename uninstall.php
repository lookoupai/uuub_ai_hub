<?php
declare(strict_types=1);

if (!defined('DEBUG')) {
    exit;
}

set_time_limit(0);

// 卸载强力清理：用量日志随插件一并删除（docs/方案v3.md §5.9）
$db = $this->container->get(\Framework\Database\Interfaces\DatabaseInterface::class);
foreach (['ai_usage_log', 'ai_daily_usage'] as $table) {
    if ($db->findTable($table)) {
        $db->exec("DROP TABLE `{$db->prefix}{$table}`");
    }
}

// 配置文件含 API 密钥，卸载必须删除，防止密钥残留磁盘
$configFile = APP_PATH . 'plugins/uuub_ai_hub/config/ai.php';
foreach ([$configFile, $configFile . '.backup.php'] as $file) {
    if (is_file($file)) {
        \Framework\Utils\FileHelper::unlink($file);
    }
}
