<?php

declare(strict_types=1);

namespace Plugins\uuub_ai_hub\Services;

use Framework\Core\Container;
use Framework\Core\Traits\StatefulTrait;
use Plugins\uuub_ai_hub\Models\AiDailyUsageModel;

/**
 * ai_daily_usage 表的原子服务：日键冗余计数，供预算熔断与用量看板读取，
 * 避免对明细表实时 COUNT。
 */
final class AiDailyUsageService
{
    use StatefulTrait;

    /** @var AiDailyUsageModel */
    private $model;

    public function __construct(Container $container)
    {
        $this->model = $container->get(AiDailyUsageModel::class);
    }

    /** @return array 无记录时返回空数组 */
    public function readDay(int $day): array
    {
        $this->clearStates();
        return $this->model->read(['day' => $day]);
    }

    public function bump(int $day, int $calls, int $tokensIn, int $tokensOut, int $errors): void
    {
        $this->clearStates();
        $update = ['calls+' => $calls, 'errors+' => $errors, 'tokens_in+' => $tokensIn, 'tokens_out+' => $tokensOut];
        if ($this->model->update(['day' => $day], $update) > 0) {
            return;
        }
        try {
            $this->model->insert(['day' => $day, 'calls' => $calls, 'errors' => $errors,
                'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut]);
        } catch (\Throwable $ignored) {
            // 并发首写命中 day 唯一键时退回增量更新
            $this->model->update(['day' => $day], $update);
        }
    }

    /** @return array 按日键倒序的最近 N 天计数 */
    public function recentDays(int $limit): array
    {
        $this->clearStates();
        return $this->model->find([], ['day' => -1], 1, $limit);
    }
}
