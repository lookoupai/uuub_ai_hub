<?php exit;

// 基础服务经统一列表注册；AiHubService 需要具体类型供消费插件构造注入，
// 由 register_after 钩子以真实实例工厂注册（见 Service_Loading_Performance.md）。
$aiHubPlainServices = [
    \Plugins\uuub_ai_hub\Services\AiHubSettingsService::class,
    \Plugins\uuub_ai_hub\Services\AiUsageLogService::class,
    \Plugins\uuub_ai_hub\Services\AiDailyUsageService::class,
];
$moduleServices = array_merge($moduleServices, $aiHubPlainServices);

$aiHubTypedServices = [
    \Plugins\uuub_ai_hub\Services\AiHubService::class,
];
