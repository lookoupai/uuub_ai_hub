<?php

declare(strict_types=1);

namespace Plugins\uuub_ai_hub\Models;

use App\Models\BaseModel;

final class AiUsageLogModel extends BaseModel
{
    /** @var string */
    protected $table = 'ai_usage_log';
}
