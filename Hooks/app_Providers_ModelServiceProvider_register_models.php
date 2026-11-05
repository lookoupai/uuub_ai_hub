<?php exit;

$aiHubModels = [
    \Plugins\uuub_ai_hub\Models\AiUsageLogModel::class,
    \Plugins\uuub_ai_hub\Models\AiDailyUsageModel::class,
];
$models = array_merge($models, $aiHubModels);
$deferredModels += array_fill_keys($aiHubModels, true);
