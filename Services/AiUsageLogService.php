<?php

declare(strict_types=1);

namespace Plugins\uuub_ai_hub\Services;

use Framework\Core\Container;
use Framework\Core\Traits\StatefulTrait;
use Plugins\uuub_ai_hub\Models\AiUsageLogModel;

/**
 * ai_usage_log 表的原子服务：一表一导，只做明细写入与游标读取。
 */
final class AiUsageLogService
{
    use StatefulTrait;

    /** @var AiUsageLogModel */
    private $model;

    public function __construct(Container $container)
    {
        $this->model = $container->get(AiUsageLogModel::class);
    }

    public function insert(array $row): int
    {
        $this->clearStates();
        return (int)$this->model->insert($row);
    }

    /**
     * 游标分页读取最近调用记录；$cursorId 为上一页最后一条 id，取 id 严格小于它的记录。
     *
     * @return array
     */
    public function recent(int $cursorId, int $limit): array
    {
        $this->clearStates();
        $condition = $cursorId > 0 ? ['id' => ['<' => $cursorId]] : [];
        return $this->model->find($condition, ['id' => -1], 1, $limit);
    }

    /**
     * 消费方频控用：统计某消费插件自 $since 以来的调用条数（有界扫描）。
     * ref 精确前缀（如 preview:tg:123）可进一步过滤。
     */
    public function countRecent(string $consumer, int $since, string $refPrefix = ''): int
    {
        $this->clearStates();
        $rows = $this->model->find(['consumer' => $consumer, 'created_at' => ['>=' => $since]], [], 1, 1000);
        if ($refPrefix === '') {
            return count($rows);
        }
        $count = 0;
        foreach ($rows as $row) {
            if (strpos((string)$row['ref'], $refPrefix) === 0) {
                $count++;
            }
        }
        return $count;
    }
}
