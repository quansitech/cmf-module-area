<?php

declare(strict_types=1);

use Quansitech\Cmf\Area\Filament\Resources\Area\AreaResource;

return [

    /*
    |--------------------------------------------------------------------------
    | 上游数据源
    |--------------------------------------------------------------------------
    |
    | upstream_repo：AreaCity-JsSpider-StatsGov 仓库（owner/repo），
    | area:check-upstream / area:download 据此访问 GitHub Releases。
    |
    */

    'upstream_repo' => env('CMF_AREA_UPSTREAM_REPO', 'xiangyuecn/AreaCity-JsSpider-StatsGov'),

    /*
    |--------------------------------------------------------------------------
    | 当前内置数据基线版本
    |--------------------------------------------------------------------------
    |
    | 与 database/data/ok_data_level4.csv 对应的上游 Release tag，
    | 升级发版时随新基线数据一并更新（升级 skill 会改写本值）。
    |
    */

    'data_version' => '2025.251231.260403',

    /*
    |--------------------------------------------------------------------------
    | 最近一次对比的上游版本
    |--------------------------------------------------------------------------
    |
    | area 自有基线版本模型下（升级方案 §3），data_version 可能部分对齐上游
    | （如 2025.251231.260403+1）。本项记录最近一次对比的上游版本 tag，
    | 供界面展示"部分对齐上游 X"；对齐后与 data_version 相等。
    | 由定稿动作自动维护。
    |
    */

    'upstream_base' => '2025.251231.260403',

    /*
    |--------------------------------------------------------------------------
    | 升级管理界面（维护者工具）
    |--------------------------------------------------------------------------
    |
    | 包的维护者工具，不默认出现在业务项目后台（升级方案 §12）：
    | enabled 默认关，维护环境（monorepo）显式打开；
    | max_retries 为 AI 采集的机器校验重试上限，超限转人工录入。
    |
    | AI 判读（方案 §11-A）：agent_command 为本地 agent CLI（默认 pi，
    | https://pi.dev，print 模式一次性会话拉起）；agent_args / agent_timeout
    | 控制调用参数与单次进程超时（秒）。
    |
    */

    'upgrade' => [
        'enabled' => env('CMF_AREA_UPGRADE_ENABLED', false),
        'max_retries' => 3,
        // 基线 csv / changes 留档 / 基线快照目录（默认模块 database/data）
        'data_dir' => null,
        // 版本号写回的 config 文件（默认 config_path('cmf-area.php') 或模块 config）
        'config_file' => null,
        // 升级工作区目录（默认 storage/app/cmf-area/upgrade）
        'work_dir' => null,
        // AI 判读 agent：可执行命令（pi / 其他 agent CLI）与调用参数、进程超时（秒，0 = 不限）
        'agent_command' => env('CMF_AREA_UPGRADE_AGENT_COMMAND', 'pi'),
        // --mode json：stdout 输出流式 JSON 事件（thinking_delta/text_delta/tool 调用逐行实时落盘），
        // 页面轮询日志即可实时看到判读过程；text 模式（默认）要跑完才一次性输出
        'agent_args' => ['-p', '--no-session', '--mode', 'json'],
        'agent_timeout' => (int) env('CMF_AREA_UPGRADE_AGENT_TIMEOUT', 1800),
        // pi 模型指定（--model，支持 provider/id 与 :thinking 档，如 anthropic/claude-sonnet-4-5:high）；
        // null = 用 pi 自身配置的默认模型
        'agent_model' => env('CMF_AREA_UPGRADE_AGENT_MODEL'),
        // 判读时挂载的 skill 目录清单（pi --skill，可多个）；默认挂本包的判读 SOP（area/skill），
        // 使 agent 在任务包目录工作时也能读到 changes v3 契约与判读流程
        'agent_skills' => [dirname(__DIR__).'/skill'],
    ],

    /*
    |--------------------------------------------------------------------------
    | 引用登记表名
    |--------------------------------------------------------------------------
    |
    | 迁移执行器（MigrationExecutor）按此表名读取本项目的引用登记。
    | 一般无需修改；修改需在 migrate 建表之前完成。
    |
    */

    'references_table' => 'cmf_area_references',

    /*
    |--------------------------------------------------------------------------
    | 兜底扫描路径
    |--------------------------------------------------------------------------
    |
    | area:sync-references 默认以已注册 ServiceProvider 为锚点自动发现
    | 扫描范围（装了就会被扫到，卸载即消失）。此处兜底覆盖无 provider
    | 的纯模型库等边缘场景，路径相对于 base_path()。
    |
    */

    'scan_paths' => [],

    /*
    |--------------------------------------------------------------------------
    | AreaPicker 级联数据端点路由
    |--------------------------------------------------------------------------
    */

    'route_prefix' => env('CMF_AREA_ROUTE_PREFIX', 'cmf-area'),

    'middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | 模块的 Filament Resource
    |--------------------------------------------------------------------------
    |
    | 深度定制时在宿主项目继承该类并在 app 配置中替换。
    |
    */

    'resource' => AreaResource::class,

    /*
    |--------------------------------------------------------------------------
    | Shield 权限点（写入 filament-shield.resources.manage）
    |--------------------------------------------------------------------------
    */

    'permissions' => ['viewAny', 'view'],

];
