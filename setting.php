<?php

declare(strict_types=1);

!defined('DEBUG') and exit('Forbidden');

if (!isset($container, $request, $method)
    || !$container instanceof \Framework\Core\Container
    || !$request instanceof \Framework\Http\Interfaces\ServerRequestInterface) {
    throw new \RuntimeException('Invalid plugin setting execution context');
}

// 插件管理器的设置入口仅展示现有页面；表单继续提交到原有权限与 CSRF 路由。
$settingController = $container->get(
    \Plugins\uuub_ai_hub\Controllers\Admin\AiHubAdminController::class
);
$routeMeta = $request->getAttribute('_route_meta', []);
$routeMeta['layout'] = 'admin_uuub_ai_hub_setting';

return $settingController->setting($request->withAttribute('_route_meta', $routeMeta));
