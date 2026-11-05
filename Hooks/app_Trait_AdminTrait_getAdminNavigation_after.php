<?php exit;

$data['aiHub'] = [
    'parent' => 'aiHub',
    'name' => $this->language->get('uuub_ai_hub_menu'),
    'url' => $this->urlGenerator->url('admin/aiHub/setting'),
    'icon' => '<svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>',
    'children' => [
        'AiHubSetting' => [
            'child' => 'setting',
            'name' => $this->language->get('uuub_ai_hub_setting'),
            'url' => $this->urlGenerator->url('admin/aiHub/setting'),
        ],
        'AiHubUsage' => [
            'child' => 'usage',
            'name' => $this->language->get('uuub_ai_hub_usage'),
            'url' => $this->urlGenerator->url('admin/aiHub/usage'),
        ],
    ],
];
