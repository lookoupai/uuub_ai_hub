<?php

declare(strict_types=1);

namespace Plugins\uuub_ai_hub\Controllers\Admin;

use App\Controllers\Base\BaseController;
use App\Traits\Admin\AdminTrait;
use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Interfaces\ServerRequestInterface;
use Framework\Http\Psr7\RequestUtils;
use Plugins\uuub_ai_hub\Services\AiDailyUsageService;
use Plugins\uuub_ai_hub\Services\AiHubException;
use Plugins\uuub_ai_hub\Services\AiHubService;
use Plugins\uuub_ai_hub\Services\AiHubSettingsService;
use Plugins\uuub_ai_hub\Services\AiUsageLogService;
use Plugins\uuub_ai_hub\Support\AiHubCode;

final class AiHubAdminController extends BaseController
{
    use AdminTrait;

    public function setting(ServerRequestInterface $request): ResponseInterface
    {
        $settings = $this->container->get(AiHubSettingsService::class);
        $config = $settings->configuration();
        $editKey = RequestUtils::param('providerKey', '');
        $form = $config['providers'][$editKey] ?? ['protocol' => 'openai', 'base_url' => '', 'api_key' => '', 'model' => '', 'models' => [], 'timeout' => 30];
        $form['providerKey'] = $editKey;
        // 密钥不回显：留空提交表示沿用原值
        $form['api_key'] = '';
        $form['models_text'] = implode("\n", array_values((array)($form['models'] ?? [])));

        $language = [];
        foreach (['submit', 'update', 'operation_success', 'no_data_available', 'yes', 'no'] as $key) $language[$key] = $this->language->get($key);
        foreach (['title', 'description', 'providers', 'new_provider', 'editing', 'provider_key', 'protocol', 'protocol_openai', 'protocol_jev',
            'base_url', 'url_hint', 'api_key', 'model', 'models', 'models_hint', 'models_unit', 'fetch_models',
            'fetch_dialog_title', 'fetch_search_placeholder', 'fetch_select_all', 'fetch_clear', 'fetch_ok', 'fetch_empty',
            'fetch_confirm', 'fetch_cancel', 'in_use_by', 'slot_model', 'timeout', 'secret_tip', 'delete', 'delete_confirm', 'test', 'test_model', 'test_need_save',
            'routing', 'unbound', 'budget', 'budget_calls', 'budget_tokens',
            'budget_tip', 'usage_today', 'usage_link'] as $key) {
            $language['uuub_ai_hub_' . $key] = $this->language->get('uuub_ai_hub_' . $key);
        }
        /* 槽位标签按配置键派生：新增槽位只需补语言包（缺键回退槽位名），自定义槽回退显示槽位名 */
        foreach (array_keys($config['slots']) as $slot) {
            $val = $this->language->get('uuub_ai_hub_slot_' . $slot);
            $language['uuub_ai_hub_slot_' . $slot] = is_string($val)
                && strpos($val, 'language pack does not exist') === false ? $val : $slot;
        }

        /* providersJson 供页面脚本使用（槽位模型联动、拉取弹窗重复标记）；不含 api_key */
        $providers = [];
        $providersJson = [];
        foreach ($config['providers'] as $key => $provider) {
            $models = array_values((array)($provider['models'] ?? []));
            $providers[] = ['key' => $key, 'protocol' => $provider['protocol'], 'model' => $provider['model'],
                'model_count' => count($models), 'base_url' => $provider['base_url'],
                'editUrl' => $this->urlGenerator->url('admin/aiHub/setting', ['providerKey' => $key])];
            /* 键归一为字符串：全数字 provider 键会被 PHP 规范化为 int（踩坑记录 §4） */
            $providersJson[(string)$key] = ['protocol' => $provider['protocol'], 'model' => (string)$provider['model'], 'models' => $models];
        }
        /* 槽位双下拉：连接下拉 + 模型下拉（切换连接时模型选项由页面脚本联动重建） */
        $slotSelects = [];
        foreach (array_keys($config['slots']) as $slot) {
            $binding = $config['slots'][$slot];
            $boundProvider = (string)($binding['provider'] ?? '');
            $boundModel = (string)($binding['model'] ?? '');
            $providerOptions = [['value' => '', 'text' => $language['uuub_ai_hub_unbound'], 'selected' => $boundProvider === '']];
            foreach ($providersJson as $key => $provider) {
                /* 绑定值恒为 string，provider 键可能被 PHP 规范化为 int（踩坑记录 §4）：
                   foreach 取出的 $key 会还原成 int，必须 (string) 归一后比较，否则永远"未绑定" */
                $providerOptions[] = ['value' => (string)$key, 'text' => (string)$key, 'selected' => (string)$key === $boundProvider];
            }
            /* 绑定模型已被移出清单时前置补入选项避免回显丢失（运行时仍按绑定调用，见 slotBinding 注） */
            $boundModels = (array)($providersJson[$boundProvider]['models'] ?? []);
            $modelOptions = [];
            if ($boundModel !== '' && !in_array($boundModel, $boundModels, true)) {
                $modelOptions[] = ['value' => $boundModel, 'text' => $boundModel, 'selected' => true];
            }
            foreach ($boundModels as $model) {
                $modelOptions[] = ['value' => $model, 'text' => $model, 'selected' => $model === $boundModel];
            }
            $slotSelects[] = ['name' => $slot, 'label' => $language['uuub_ai_hub_slot_' . $slot],
                'provider_options' => $providerOptions, 'model_options' => $modelOptions, 'model' => $boundModel];
        }
        $newSlotProviderOptions = [];
        foreach ($providersJson as $key => $provider) {
            $newSlotProviderOptions[] = ['value' => (string)$key, 'text' => (string)$key];
        }
        /* 默认模型下拉：选项 = 连接模型清单（datalist 会按输入值过滤候选，不能用）；
           新连接清单为空时给单个空选项，拉取/手填后由页面脚本联动重建 */
        $formModelOptions = [];
        foreach (array_values((array)($form['models'] ?? [])) as $formModel) {
            $formModelOptions[] = ['value' => (string)$formModel, 'text' => (string)$formModel, 'selected' => (string)$form['model'] === (string)$formModel];
        }
        if ($formModelOptions === []) {
            $formModelOptions[] = ['value' => '', 'text' => '', 'selected' => true];
        }
        $protocolOptions = [];
        foreach ([['value' => 'openai', 'label' => 'protocol_openai'], ['value' => 'jev', 'label' => 'protocol_jev']] as $protocol) {
            $protocolOptions[] = ['value' => $protocol['value'], 'text' => $language['uuub_ai_hub_' . $protocol['label']],
                'selected' => $form['protocol'] === $protocol['value']];
        }
        $today = $this->container->get(AiDailyUsageService::class)->readDay((int)date('Ymd'));
        $user = $request->getAttribute('user', []);
        $data = ['header' => ['title' => $language['uuub_ai_hub_title'], 'keywords' => '', 'description' => $language['uuub_ai_hub_description']],
            'language' => $language, 'menu' => $this->getAdminMenu(), 'menu_fixed' => ['parent' => 'aiHub', 'child' => 'setting'],
            'extra' => [], 'csrf_token' => $this->getCsrfToken((string)$user['salt']),
            'providers' => $providers, 'providers_json' => $providersJson, 'form' => $form,
            'protocolOptions' => $protocolOptions, 'slotSelects' => $slotSelects, 'newSlotProviderOptions' => $newSlotProviderOptions,
            'formModelOptions' => $formModelOptions,
            'fetch_hidden' => $form['protocol'] !== 'openai',
            'budget_calls' => (int)$config['budget']['daily_calls'], 'budget_tokens' => (int)$config['budget']['daily_tokens'],
            'today_calls' => (int)($today['calls'] ?? 0), 'today_errors' => (int)($today['errors'] ?? 0),
            'today_tokens' => (int)($today['tokens_in'] ?? 0) + (int)($today['tokens_out'] ?? 0),
            'save_provider_url' => $this->urlGenerator->url('admin/aiHub/postSaveProvider'),
            'delete_provider_url' => $this->urlGenerator->url('admin/aiHub/postDeleteProvider'),
            'routing_url' => $this->urlGenerator->url('admin/aiHub/postSaveRouting'),
            'test_url' => $this->urlGenerator->url('admin/aiHub/postTest'),
            'fetch_url' => $this->urlGenerator->url('admin/aiHub/postFetchModels'),
            'usage_url' => $this->urlGenerator->url('admin/aiHub/usage'),
            'new_url' => $this->urlGenerator->url('admin/aiHub/setting')];
        $routeMeta = $request->getAttributes()['_route_meta'];
        $rendered = $this->render($routeMeta['layout'], $data, 'uuub_ai_hub');
        return $rendered->withHeader('Cache-Control', 'private, no-store');
    }

    public function usage(ServerRequestInterface $request): ResponseInterface
    {
        $language = [];
        foreach (['no_data_available'] as $key) $language[$key] = $this->language->get($key);
        foreach (['daily_usage', 'day', 'calls', 'errors', 'tokens', 'recent_logs', 'log_time', 'log_consumer', 'log_slot',
            'log_method', 'log_model', 'log_latency', 'log_status', 'next_page',
            'method_chat', 'method_json', 'method_decide', 'status_ok', 'status_error'] as $key) {
            $language['uuub_ai_hub_' . $key] = $this->language->get('uuub_ai_hub_' . $key);
        }
        $daily = [];
        foreach ($this->container->get(AiDailyUsageService::class)->recentDays(14) as $row) {
            $daily[] = ['day' => substr((string)$row['day'], 0, 4) . '-' . substr((string)$row['day'], 4, 2) . '-' . substr((string)$row['day'], 6, 2),
                'calls' => (int)$row['calls'], 'errors' => (int)$row['errors'],
                'tokens' => (int)$row['tokens_in'] + (int)$row['tokens_out']];
        }
        $methods = [AiHubCode::METHOD_CHAT => 'method_chat', AiHubCode::METHOD_JSON => 'method_json', AiHubCode::METHOD_DECIDE => 'method_decide'];
        $errors = [AiHubException::CODE_BUDGET => 'error_budget', AiHubException::CODE_CONFIG => 'error_config',
            AiHubException::CODE_NETWORK => 'error_network', AiHubException::CODE_PROTOCOL => 'error_protocol',
            AiHubException::CODE_SCHEMA => 'error_schema'];
        $cursor = RequestUtils::param('cursor', 0);
        $logs = $this->container->get(AiUsageLogService::class)->recent((int)$cursor, 20);
        $logRows = [];
        foreach ($logs as $log) {
            $logRows[] = [
                'id' => (int)$log['id'],
                'time' => date('Y-m-d H:i:s', (int)$log['created_at']),
                'consumer' => (string)$log['consumer'],
                'slot' => (string)$log['slot'],
                'method' => $language['uuub_ai_hub_' . ($methods[(int)$log['method']] ?? 'method_chat')],
                'model' => (string)$log['model'],
                'latency_ms' => (int)$log['latency_ms'],
                'status' => ($language[(int)$log['status'] === AiHubCode::STATUS_OK ? 'uuub_ai_hub_status_ok' : 'uuub_ai_hub_status_error'])
                    . ((int)$log['error_code'] === 0 ? '' : ' (' . $language['uuub_ai_hub_' . ($errors[(int)$log['error_code']] ?? 'error_network')] . ')'),
            ];
        }
        $nextUrl = '';
        if (count($logs) === 20) {
            $nextUrl = $this->urlGenerator->url('admin/aiHub/usage', ['cursor' => (int)$logs[19]['id']]);
        }
        $data = ['header' => ['title' => $language['uuub_ai_hub_daily_usage'], 'keywords' => '', 'description' => ''],
            'language' => $language, 'menu' => $this->getAdminMenu(), 'menu_fixed' => ['parent' => 'aiHub', 'child' => 'usage'],
            'extra' => [], 'daily' => $daily, 'logs' => $logRows, 'next_url' => $nextUrl];
        $routeMeta = $request->getAttributes()['_route_meta'];
        $rendered = $this->render($routeMeta['layout'], $data, 'uuub_ai_hub');
        return $rendered->withHeader('Cache-Control', 'private, no-store');
    }

    public function postSaveProvider(ServerRequestInterface $request): ResponseInterface
    {
        $key = RequestUtils::param('providerKey', '');
        $input = [];
        // 协议配置按原字符串校验；密钥不能先做 HTML 实体转换
        foreach (['protocol', 'base_url', 'api_key', 'model'] as $name) {
            $input[$name] = RequestUtils::param($name, '', false);
        }
        $input['timeout'] = RequestUtils::param('timeout', 30);
        // 模型清单 textarea 一行一个；filter=false 保留原始字符串，逐行交由服务端校验
        $input['models'] = preg_split('/\R/', (string)RequestUtils::param('models_text', '', false)) ?: [];
        try {
            $this->container->get(AiHubSettingsService::class)->saveProvider($key, $input);
        } catch (\Framework\Exception\BusinessException $error) {
            return $this->errorMessage($this->language->get($error->getMessage()), $error->getCode());
        }
        return $this->successMessage($this->language->get('operation_success'), 0, $this->urlGenerator->url('admin/aiHub/setting', ['providerKey' => $key]));
    }

    public function postDeleteProvider(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->container->get(AiHubSettingsService::class)->deleteProvider(RequestUtils::param('providerKey', ''));
        } catch (\RuntimeException $error) {
            return $this->errorMessage($this->language->get($error->getMessage()), 7);
        }
        return $this->successMessage($this->language->get('operation_success'), 0, $this->urlGenerator->url('admin/aiHub/setting'));
    }

    public function postSaveRouting(ServerRequestInterface $request): ResponseInterface
    {
        $config = $this->container->get(AiHubSettingsService::class)->configuration();
        $slots = [];
        foreach (array_keys($config['slots']) as $slot) {
            $slots[$slot] = ['provider' => RequestUtils::param($slot, '', false), 'model' => RequestUtils::param($slot . '_model', '', false)];
        }
        /* 开放槽位：后台「新增槽位」非空时一并提交（名字格式由 saveRouting 校验） */
        $newSlot = strtolower(trim((string)RequestUtils::param('new_slot', '', false)));
        if ($newSlot !== '') {
            $slots[$newSlot] = ['provider' => RequestUtils::param('new_slot_provider', '', false), 'model' => RequestUtils::param('new_slot_model', '', false)];
        }
        $budget = ['daily_calls' => RequestUtils::param('daily_calls', 0), 'daily_tokens' => RequestUtils::param('daily_tokens', 0)];
        try {
            $this->container->get(AiHubSettingsService::class)->saveRouting($slots, $budget);
        } catch (\Framework\Exception\BusinessException $error) {
            return $this->errorMessage($this->language->get($error->getMessage()), $error->getCode());
        }
        return $this->successMessage($this->language->get('operation_success'), 0, $this->urlGenerator->url('admin/aiHub/setting'));
    }

    public function postTest(ServerRequestInterface $request): ResponseInterface
    {
        /* model/base_url/api_key 非空时按表单值覆盖存储配置（测试所见即所测） */
        $override = ['model' => RequestUtils::param('model', '', false),
            'base_url' => RequestUtils::param('base_url', '', false),
            'api_key' => RequestUtils::param('api_key', '', false)];
        try {
            $result = $this->container->get(AiHubService::class)->testProvider(RequestUtils::param('providerKey', ''), $override);
        } catch (AiHubException $error) {
            return $this->errorMessage($this->aiHubError($error), $error->getCode());
        }
        return $this->successMessage($this->language->get('uuub_ai_hub_test_ok'), 0,
            $this->urlGenerator->url('admin/aiHub/setting'), 0, $result);
    }

    /** 拉取上游模型清单（GET /models）：免费快速校验，不烧 token 不进用量；成功返回模型清单供弹窗勾选 */
    public function postFetchModels(ServerRequestInterface $request): ResponseInterface
    {
        $input = ['providerKey' => RequestUtils::param('providerKey', '', false),
            'base_url' => RequestUtils::param('base_url', '', false),
            'api_key' => RequestUtils::param('api_key', '', false)];
        try {
            $result = $this->container->get(AiHubService::class)->fetchModels($input);
        } catch (AiHubException $error) {
            return $this->errorMessage($this->aiHubError($error), $error->getCode());
        }
        return $this->successMessage($this->language->get('uuub_ai_hub_fetch_ok'), 0, '', 0, ['models' => $result['models']]);
    }

    /** AiHubException → 展示消息：错误类型语言键 + 截断的内部诊断（HTTP 状态与响应片段） */
    private function aiHubError(AiHubException $error): string
    {
        $names = [AiHubException::CODE_BUDGET => 'budget', AiHubException::CODE_CONFIG => 'config',
            AiHubException::CODE_NETWORK => 'network', AiHubException::CODE_PROTOCOL => 'protocol',
            AiHubException::CODE_SCHEMA => 'schema'];
        $name = $names[$error->getCode()] ?? 'network';
        $detail = $error->getMessage();
        $detail = function_exists('mb_substr') ? mb_substr($detail, 0, 200) : substr($detail, 0, 200);
        return $this->language->get('uuub_ai_hub_error_' . $name) . ' — ' . $detail;
    }
}
