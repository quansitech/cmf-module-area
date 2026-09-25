# cmf-module-area

QS CMF 行政区划模块：内置省市区乡镇四级区划数据（随包迁移导入）、业务引用**强制登记**（未声明即抛错）、AI 驱动的区划变更升级流水线（diff → 判读 → 校验 → 生成迁移 → 业务项目 migrate 生效）。

## 功能

- **内置四级区划**：上游 [AreaCity-JsSpider-StatsGov](https://github.com/xiangyuecn/AreaCity-JsSpider-StatsGov) 数据（2025.251231.260403 版，42,826 行）随 `migrate` 导入 `cmf_areas`；撤销不删行（`status=0` + `successor_id`），历史数据回显不断链
- **引用强制登记**：业务模型存区划 id 前必须在模型上声明 `areaReferences()`（或 `Area::registerReference()` 手工登记），否则赋值 / 表单构建即抛 `AreaReferenceNotRegisteredException`（异常消息带可粘贴的声明模板）；登记列**必须是整型**（PG 类型严格校验，非整型拒绝登记）
- **AreaPicker 表单组件**：省市区乡镇级联下拉（异步取下级、自动回填路径、可写完整路径名称快照列）；状态/合法性强制校验（撤销区划不可选）
- **AreaIdCast**：Eloquent 属性 cast，一行接入即获得强制校验
- **后台管理**：区划树形浏览（默认省级、层级/状态筛选、详情页下级列表）、只读 Resource、Shield 权限点自动登记
- **变更升级流水线**：`area:check-upstream` / `area:download` / `area:diff` / `area:check-changes` / `area:generate-migration` 五命令 + `area-upgrade` skill（AI 判读 SOP），产出"薄壳"迁移文件（冻结 payload，不含业务表名），业务项目 `migrate` 时按**本项目引用登记表**延迟绑定执行
- **A 级精确回滚**：`migrate` 时每个写动作的行级现场（before-image）落 `cmf_area_migration_journal`，`migrate:rollback` 按日志逆序精确回放——结构行整行恢复（含时间戳）、业务行带守卫条件还原（升级后被业务改写的行跳过并进报告），不多改一行、不少改一行；确认无回滚需求后 `area:cleanup-journal {version}` 清理
- **变更档案**：每次升级的判定类型、证据链接、AI 摘要、受影响行数落 `cmf_area_changes`

## 安装

```bash
composer require quansitech/cmf-module-area
php artisan migrate        # 建表 + 导入内置区划数据（4 万余行，一次性）
```

配置（`php artisan vendor:publish --tag=cmf-config` 落出 `config/cmf-area.php`）：

```dotenv
# 一般无需修改；route_prefix / middleware 可按项目调整
```

`cmf:install` 会自动发布配置并执行迁移（`cmf_areas` / `cmf_area_references` / `cmf_area_changes`）。

## 业务模型引用区划

在存区划 id 的模型上声明引用（**未声明会抛错**）：

```php
use Quansitech\Cmf\Area\Casts\AreaIdCast;

class Store extends Model
{
    protected $casts = ['area_id' => AreaIdCast::class];

    public static function areaReferences(): array
    {
        return [
            // onMerge: remap = 存"当前状态"（区划合并/拆分时自动改写为新码）
            //          keep  = 存"历史事实"（默认，永不自动改写）
            'area_id' => ['onMerge' => 'remap'],
        ];
    }
}
```

无 Model 的表（DB facade 操作的历史表）走手工登记口：

```php
Area::registerReference('legacy_orders', 'region_id', onMerge: 'keep');
```

声明后执行 `php artisan area:sync-references` 幂等落库登记表（装了包就会被自动发现：以 ServiceProvider 为锚点反推 composer 包扫描范围）。

### 表单中使用 AreaPicker

```php
use Filament\Forms\Components\Hidden;
use Quansitech\Cmf\Area\Filament\Forms\Components\AreaPicker;

AreaPicker::make('area_id')->label('所在地区')->required(),
// 需要冗余名称快照（列表页免 join）时：选中后自动写入完整路径
// （逐级 ext_name 组合，如「湖北省 武汉市 江岸区」）
AreaPicker::make('region_id')->withNameSnapshot('region_name'),
// 快照列必须同时是表单里的真实字段（隐藏字段即可）：
// Schema::getState() 会按字段裁剪状态，非字段的快照值会被丢弃
Hidden::make('region_name'),
```

## 区划升级流水线

行政区划每年都有调整（撤县设区、析置新县等）。升级由 skill（`skill/SKILL.md`，area-upgrade）驱动 AI 完成判读，人工只需复核：

```bash
php artisan area:check-upstream            # ① 检查上游新版
php artisan area:download --version=...    # ② 下载新版 csv
php artisan area:diff new.csv              # ③ 机械 diff（added/removed/renamed/parent_changed）
php artisan area:check-changes changes.json --diff=diff.json ...   # ④ 机器校验 AI 判读产物
php artisan area:generate-migration changes.json ...               # ⑤ 生成薄壳迁移（人工闸门后执行）
```

生成的迁移落在业务项目 `database/migrations/`，`migrate` 时：

- `cmf_areas` 结构操作无条件执行（所有项目结果一致）；
- 业务表按**本项目引用登记表**逐列处理：`remap` 列自动改写、`keep` 列只出信息性报告；
- 不可判定的（split 浅层值、部分疆域旁落、代码重用、无承继撤销）进**待人工清单**（迁移日志 + `storage/logs/area-migration-*.log`）。

变更档案（changes.json v3，见 `skill/changes.schema.json`）只含两类记录：**node**（一个单位在某一版的
存续状态，`state` 即定侧，无需写 `side`）与 **edge**（一条旧 id→新 id 对应关系，拍平任意层级）。
变更类型标签（`add` / `rename` / `parent_change` / `merge_into` / `split_from` / `code_change` /
`code_reuse` / `abolish`）由节点状态 + 出入边纯派生（不可手写）；continued 的 `attributes` 只声明
字段名清单（值从两版 csv 取）；证据登记在文件级 `evidence` 池、记录只写引用 id（下级边可继承
单位级边）；id 复用（同 id 换单位）必须显式声明 `id_reuse`，映射执行序由复用链拓扑排序确定
（成环禁行转人工）。

### 回滚（migrate:rollback）

新版迁移文件（payload 带 `journal` 标记）的 `up()` 会把每行写入前的现场记入
`cmf_area_migration_journal`（结构行整行、业务行单值，同事务分块）；`down()` 按日志逆序回放：

- 结构操作（insert/retire/rename/reparent/archive，含链式复用覆盖）整行精确恢复或删除；
- 业务列改写按主键 + 守卫条件还原（`WHERE pk=? AND col=新值`）：升级后被业务改写的行**跳过**
  并写入回滚报告（`storage/logs/area-migration-{version}-rollback.log`），不误伤业务新数据；
- 无单主键的业务表不支持行级日志：登记时可用 `pkColumn`（模型声明）/ `pk_column`（手工登记）
  声明主键列，未声明且探测不到主键的列退回旧式值扫描并在报告中警告；
- 观察窗口确认无回滚需求后，执行 `php artisan area:cleanup-journal {version}` 清理日志
  （apply 报告末尾会附此提示；回滚成功后日志自动删除）。

旧格式迁移文件（无 `journal` 标记）的 `down()` 仍走旧式值扫描并输出能力边界警告
（rename/reparent/链式复用不在其自动回滚范围）；重新生成在途迁移文件即可获得精确回滚能力。
整库回到升级时点（含升级后新业务数据消失）属数据库级 PITR（备份 + binlog 闪回），不在本框架范围。

## 测试

```bash
cd area && composer install && composer test
```

- `composer test`：串行跑全量（脚本已内置 `XDEBUG_MODE=off`，避免本机 Xdebug 拖慢 ~45%）。
- `composer test:parallel`：按文件分进程并行跑全量（paratest），多核机器上最快。
- 直接 `vendor/bin/pest` 亦可，但请先 `export XDEBUG_MODE=off`（`xdebug.mode=debug` 会让套件慢近一倍）。

覆盖：diff 判定、导入（BOM/引号/12 位 ext_id）、登记同步与类型拦截、强制校验（Cast/Picker）、
迁移执行（五类结构 op、remap/keep 策略、幂等、延迟绑定）、A 级精确回滚（链式复用整行恢复、
废止复用 status 恢复、rename/reparent 反转、守卫跳过清单、无日志拒绝回滚、chunk 大数据量、
无单主键回退、cleanup-journal）、changes.json v3 机器校验（I1–I5/I7 不变量、证据池与继承、
id_reuse 链拓扑执行序、复用环禁环）、真实案例回归（2024 和康县析自皮山县）、后台页面与数据端点。

## 文档

- 开发方案：`../docs/qscmf-filament-area模块开发方案.md`（monorepo 内）
- 升级 SOP：`skill/SKILL.md`（area-upgrade）
