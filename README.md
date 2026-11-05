# uuub_ai_hub — WellCMS 3.0 AI 服务中枢

> AI 连接基础设施：统一管理 OpenAI 兼容与 jev 决策协议服务、**开放槽位路由**、
> 用量统计与日预算熔断。自身不含业务功能；插件生态里所有 AI 功能插件
> （如 `uuub_ai_translate`）统一经本中枢调用 AI。

## 命名约定

`uuub_` 为本系列插件品牌前缀（`uuub_ai_*` = AI 类插件；非 AI 类用 `uub_*`）。
WellCMS 作者约定 `well_` 前缀保留给官方插件，第三方请勿使用。

## 特性

- **双协议**：OpenAI 兼容（`/chat/completions`，含 JSON mode）与 jev 决策协议，
  按连接选择；provider 级 `extra` 参数透传（如 Qwen 系关闭思考）。
- **多模型连接**：一条连接挂多个模型（`models[]`），同一 API 地址 + 密钥只需登记一次；
  后台「拉取模型列表」代理 `GET {base_url}/models` 弹窗勾选启用，同时充当免费快速校验
  （不烧 token、不进用量与预算），也支持手动一行一个填写（jev / 无模型列表端点兜底）。
- **开放槽位路由**：槽位（slot）= 用途到「连接 + 模型」的绑定（如 `intent` 走便宜稳定的连接、
  `extract` 走强模型）。槽位名开放——后台「新增槽位」即建，消费插件自选用途命名；
  出厂预置 `intent` / `extract` / `translate` / `translate_light` 四个种子槽位。
- **用量与预算**：每次调用记录（按消费插件 / 方法 / 状态），日调用数与 token 双预算，
  超限抛 `CODE_BUDGET` 由消费方决定降级策略；连通性测试入口（真实调用计入用量，
  连接表单内可对当前选中的任一模型单独测试）。
- **失败静默隔离**：Bot/AI 通道失败只记日志不上抛主业务（v3 原则 5）。
- **配置自动迁移**：旧 schema（单模型 provider、字符串槽位绑定）读取时自动迁移，
  首次保存落盘为 v2 结构。

## 消费方接入（三入口）

```php
$hub = $container->get(\Plugins\uuub_ai_hub\Services\AiHubService::class);

// 1. 纯文本对话
$text = $hub->chat('my_slot', $systemPrompt, $userPrompt, ['timeout' => 30]);

// 2. 结构化 JSON（简化 schema 合同 + 校验失败自动重试）
$data = $hub->jsonChat('my_slot', $system, $user, ['fields' => ['items' => 'array'], 'required' => ['items']]);

// 3. jev 决策（多问题选择/评分/needs_review）
$decisions = $hub->decide('my_slot', ['state' => ..., 'questions' => [...]]);
```

- 槽位未配置连接抛 `CODE_CONFIG`、日预算超限抛 `CODE_BUDGET`（见
  `Services/AiHubException.php`），消费方按自己的降级策略处理。
- `opt` 支持按调用覆盖：`timeout` / `max_tokens` / `attempts` / `consumer`（用量归账标识）。
- 槽位与预算在后台「AI 服务中枢 → AI 连接设置」配置；`config/ai.php`（0600）保存
  连接密钥，不进版本库。

## 安装

1. 后台安装并启用；复制 `config/ai.example.php` 为 `config/ai.php` 填写连接。
2. 后台「AI 连接设置」：添加 provider（填好地址与密钥后可「拉取模型列表」勾选启用，
   该操作免费且不计入用量）→ 槽位路由绑定「连接 + 模型」→（可选）新增槽位、日预算。
3. 「用量统计」页查看按日调用/token/错误。

## 目录结构

```
Services/AiHubService.php            门面：chat / jsonChat / decide / testProvider
Services/AiHubSettingsService.php    连接/槽位/预算配置（config/ai.php 原子读写）
Services/Protocol/OpenAiProtocol.php OpenAI 兼容协议（JSON mode / 用量解析）
Services/Protocol/JevProtocol.php    jev 决策协议
Services/AiUsageLogService.php       调用流水（消费插件 / 方法 / 状态）
Services/AiDailyUsageService.php     日用量聚合（预算熔断依据）
Controllers/Admin/…                  连接设置 + 用量统计后台
Support/HttpJson.php                 HTTP 单点（TLS 约定见 docs/踩坑记录.md §1.3）
docs/踩坑记录.md                      开发踩坑（HttpClient 契约、依赖映射等）
```
