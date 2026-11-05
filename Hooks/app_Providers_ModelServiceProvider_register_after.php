<?php exit;

foreach ($aiHubTypedServices as $aiHubService) {
    $container->bind($aiHubService, static function ($container, array $params) use ($aiHubService) {
        return $container->make($aiHubService, $params);
    }, true, true);
}
