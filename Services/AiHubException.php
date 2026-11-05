<?php

declare(strict_types=1);

namespace Plugins\uuub_ai_hub\Services;

/**
 * AI 中枢统一异常：code 对应 AiHubException::CODE_* 常量，
 * 消费插件按 code 决定降级行为（预算耗尽与配置缺失应静默降级，其余记日志）。
 */
final class AiHubException extends \RuntimeException
{
    public const CODE_BUDGET = 1;   // 日预算耗尽，消费方应降级
    public const CODE_CONFIG = 2;   // 槽位或 provider 配置缺失/非法
    public const CODE_NETWORK = 3;  // 网络请求失败
    public const CODE_PROTOCOL = 4; // 服务端响应不符合协议
    public const CODE_SCHEMA = 5;   // JSON 输出未通过 schema 校验
}
