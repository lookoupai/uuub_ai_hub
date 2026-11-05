<?php exit;

Router::group(['prefix' => '/admin/aiHub', 'meta' => [
    'requiresAdminSignIn' => true,
    'requiresUserPerm' => ['enable' => true, 'role' => ['administer']],
]], function () {
    Router::get('/setting', [\Plugins\uuub_ai_hub\Controllers\Admin\AiHubAdminController::class, 'setting'],
        ['layout' => 'admin_uuub_ai_hub_setting']);
    Router::get('/usage', [\Plugins\uuub_ai_hub\Controllers\Admin\AiHubAdminController::class, 'usage'],
        ['layout' => 'admin_uuub_ai_hub_usage']);
    foreach (['postSaveProvider', 'postDeleteProvider', 'postSaveRouting', 'postTest', 'postFetchModels'] as $aiHubAction) {
        Router::post('/' . $aiHubAction, [\Plugins\uuub_ai_hub\Controllers\Admin\AiHubAdminController::class, $aiHubAction],
            ['requiresCsrf' => ['enable' => true, 'ttl' => 3600]]);
    }
});
