<?php

declare(strict_types=1);

namespace Plugins\uuub_ai_hub\Support;

/**
 * AI 用量记录的分类码合同，与 ai_usage_log 表字段值域一一对应。
 */
final class AiHubCode
{
    public const PROTOCOL_OPENAI = 0;
    public const PROTOCOL_JEV = 1;

    public const METHOD_CHAT = 0;
    public const METHOD_JSON = 1;
    public const METHOD_DECIDE = 2;

    public const STATUS_OK = 0;
    public const STATUS_ERROR = 1;

    private function __construct()
    {
    }
}
