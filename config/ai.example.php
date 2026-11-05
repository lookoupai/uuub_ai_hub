<?php
/* 复制为 ai.php 后填写；ai.php 含密钥已被 .gitignore 排除 */
return [
    'providers' => [
        // 'openai_main' => [
        //     'protocol' => 'openai',                       // openai | jev
        //     'base_url' => 'https://api.openai.com/v1',    // jev 协议填完整 decide 端点
        //     'api_key'  => 'sk-xxxx',
        //     'model'    => 'gpt-4o-mini',                  // 默认模型：测试目标 + 旧槽位绑定迁移锚点
        //     'models'   => ['gpt-4o-mini', 'gpt-4.1-mini'], // 开放给槽位选择的模型清单（后台可从上游拉取勾选）
        //     // 可选：透传到请求体的扩展参数（如 Qwen 系关闭思考：chat_template_kwargs.enable_thinking=false）
        //     'extra'   => [],
        //     'timeout'  => 30,
        // ],
    ],
    // 槽位绑定 = 连接 + 模型；model 留空串表示用该连接默认模型
    'slots' => ['intent' => ['provider' => '', 'model' => ''], 'extract' => ['provider' => '', 'model' => ''], 'translate' => ['provider' => '', 'model' => '']],
    'budget' => ['daily_calls' => 0, 'daily_tokens' => 0],
];
