<?php

declare(strict_types=1);

namespace Plugins\uuub_ai_hub\Services\Protocol;

use Framework\Utils\HttpClient;
use Plugins\uuub_ai_hub\Services\AiHubException;
use Plugins\uuub_ai_hub\Support\HttpJson;

/**
 * jev 协议适配器：类型化判断（choice/noul/score）。
 * 请求契约 {model, state, questions} 与响应 answers 映射对齐 jev 官方 wrapper
 * （github.com/wuyoscar/jev-skill scripts/jev.py）；base_url 填完整 decide 端点，
 * 如 https://openrouter.ai/api/alpha/decisions 或 https://api.typesafe.ai/v1/systemone。
 */
final class JevProtocol
{
    /**
     * @param array $provider provider 配置；model 作为默认模型，可被 $request['model'] 覆盖
     * @param array $request  ['state' => mixed, 'questions' => [qid => ['type' => 'choice|noul|score', 'instructions' => ..., 'criteria' => ...]]]
     * @param array $opt      min_probability（默认 0.8）/ min_margin（默认 0.15）复核阈值
     *
     * @return array [qid => ['status' => 'selected|needs_review|scored', 'value' => mixed, 'probability' => float|null, 'margin' => float|null]]
     */
    public static function decide(HttpClient $http, array $provider, array $request, array $opt = []): array
    {
        $questions = $request['questions'] ?? null;
        if (!is_array($questions) || $questions === []) {
            throw new \InvalidArgumentException('decide requires a non-empty questions map');
        }
        $payload = [
            'model'     => (string)($request['model'] ?? $provider['model']),
            'state'     => $request['state'] ?? '',
            'questions' => $questions,
        ];
        $body = HttpJson::post($http, $provider, (string)$provider['base_url'], $payload);
        $answers = $body['answers'] ?? null;
        if (!is_array($answers)) {
            throw new AiHubException('answers_missing', AiHubException::CODE_PROTOCOL);
        }
        $minProbability = (float)($opt['min_probability'] ?? 0.8);
        $minMargin = (float)($opt['min_margin'] ?? 0.15);
        return self::normalize($questions, $answers, $minProbability, $minMargin);
    }

    /** @return array 按 jev wrapper 的保守复核策略归一化每题答案 */
    private static function normalize(array $questions, array $answers, float $minProbability, float $minMargin): array
    {
        $decisions = [];
        foreach ($questions as $qid => $question) {
            $kind = (string)($question['type'] ?? '');
            $answer = $answers[$qid] ?? null;
            if (!is_array($answer) || (string)($answer['type'] ?? '') !== $kind) {
                throw new AiHubException('answer_missing: ' . $qid, AiHubException::CODE_PROTOCOL);
            }
            if ($kind === 'choice') {
                $decisions[$qid] = self::normalizeChoice($qid, $answer, $minProbability, $minMargin);
            } elseif ($kind === 'noul') {
                $decisions[$qid] = self::normalizeNoul($qid, $answer, $minProbability);
            } elseif ($kind === 'score') {
                $decisions[$qid] = self::normalizeScore($qid, $question, $answer);
            } else {
                throw new \InvalidArgumentException('question type must be choice, noul or score: ' . $qid);
            }
        }
        return $decisions;
    }

    private static function normalizeChoice(string $qid, array $answer, float $minProbability, float $minMargin): array
    {
        $probabilities = $answer['probabilities'] ?? null;
        $value = $answer['choice'] ?? null;
        if (!is_array($probabilities) || !is_string($value) || !isset($probabilities[$value])) {
            throw new AiHubException('invalid_choice_answer: ' . $qid, AiHubException::CODE_PROTOCOL);
        }
        $probability = self::probability($probabilities[$value], $qid);
        $bestOther = 0.0;
        foreach ($probabilities as $label => $raw) {
            if ((string)$label !== $value) {
                $bestOther = max($bestOther, self::probability($raw, $qid));
            }
        }
        $margin = $probability - $bestOther;
        $needsReview = $probability < $minProbability || $margin < $minMargin;
        return [
            'status'      => $needsReview ? 'needs_review' : 'selected',
            'value'       => $value,
            'probability' => $probability,
            'margin'      => round($margin, 4),
        ];
    }

    private static function normalizeNoul(string $qid, array $answer, float $minProbability): array
    {
        if (!is_numeric($answer['noul'] ?? null)) {
            throw new AiHubException('invalid_noul_answer: ' . $qid, AiHubException::CODE_PROTOCOL);
        }
        $probability = (float)$answer['noul'];
        if ($probability < 0.0 || $probability > 1.0) {
            throw new AiHubException('invalid_noul_answer: ' . $qid, AiHubException::CODE_PROTOCOL);
        }
        $certainty = max($probability, 1.0 - $probability);
        $needsReview = $certainty < $minProbability || $probability === 0.5;
        return [
            'status'      => $needsReview ? 'needs_review' : 'selected',
            'value'       => $probability > 0.5,
            'probability' => $probability,
        ];
    }

    private static function normalizeScore(string $qid, array $question, array $answer): array
    {
        $levels = $question['criteria'] ?? null;
        $score = $answer['score'] ?? null;
        if (!is_array($levels) || count($levels) < 2 || !is_numeric($score)) {
            throw new AiHubException('invalid_score_answer: ' . $qid, AiHubException::CODE_PROTOCOL);
        }
        $score = (int)$score;
        if ($score < 0 || $score >= count($levels)) {
            throw new AiHubException('invalid_score_answer: ' . $qid, AiHubException::CODE_PROTOCOL);
        }
        return ['status' => 'scored', 'value' => $score];
    }

    /** @return float 校验概率值域并转 float */
    private static function probability($raw, string $qid): float
    {
        if (!is_numeric($raw)) {
            throw new AiHubException('invalid_probability: ' . $qid, AiHubException::CODE_PROTOCOL);
        }
        $value = (float)$raw;
        if ($value < 0.0 || $value > 1.0) {
            throw new AiHubException('invalid_probability: ' . $qid, AiHubException::CODE_PROTOCOL);
        }
        return $value;
    }

    private function __construct()
    {
    }
}
