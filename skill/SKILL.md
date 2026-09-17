---
name: area-upgrade
description: qscmf-filament area 模块的行政区划数据升级流水线：发现上游新版本后，驱动 AI agent 完成「下载 → diff → 联网取证判读 → 机器校验 → 生成迁移 → 提交 PR」全流程。当维护者要升级 cmf-module-area 内置的区划数据（AreaCity-JsSpider-StatsGov 上游更新、新区设立/撤并/更名）时使用。确定性步骤全部脚本化，本 skill 只负责语义判读与流程编排。
---

# 行政区划数据升级流水线

## 适用场景

- `php artisan area:check-upstream` 报告上游有新版本；
- 或人工从新闻/公告得知重要区划变更（如新区设立），需要升级模块数据。

## 核心原则

1. **AI 只做"判读"，落地必须是可审核、可回滚的确定性迁移**。每条变更判定必须附信息源 URL；
2. **确定性流程 = 脚本**（area:download / area:diff / area:check-changes / area:generate-migration），语义判断 = 本提示词；
3. **禁止编造**：查不到可靠来源的条目标 `confidence: "low"` 并在 summary 说明疑点，由 PR 审查重点核对；
4. 流程终点是**提交 PR 并停下**——合并、发版由人工接管。

## SOP（严格按序执行）

### ① 下载 + diff（脚本）

```bash
php artisan area:check-upstream            # 确认上游版本号 {version}
php artisan area:download {version}        # 产出 storage/app/cmf-area/ok_data_level4_{version}.csv
php artisan area:diff storage/app/cmf-area/ok_data_level4_{version}.csv
# 产出 storage/app/cmf-area/diff.json
```

diff.json 是纯事实清单（按省分组）：added / removed / renamed / parent_changed / code_reuse_suspected。
**若 `blocked: true`（疑似代码重用）：先走「代码重用专项」（见下），确认后方可继续。**

### ② 语义判读（本 skill 的核心环节）

对 diff.json 中的**每一条** diff 循环执行取证与判读：

#### 取证策略（按优先级逐级跟进）

1. 先查维基百科年度列表：`{年份}年中华人民共和国县级以上行政区划变更列表`（1949 至今每年一页），
   用 MediaWiki API 取 wikitext 解析表格：
   `https://zh.wikipedia.org/w/api.php?action=parse&page={页面名}&prop=wikitext&format=json`
   年份区间由 diff.json 的 from_version / to_version 推出（上游版本号格式 `{数据年}.{采集日期}.{发布日期}`）。
2. 列表中"原行政单位"为空或信息不足时，**跟进到该县/区的独立词条**（如"和康县"词条写明"原为皮山县的一部分"）；
3. 再跟进词条引用的**政府公告原文**（省级政府/民政厅网站）核实归属明细（含乡镇级新码）；
4. 乡镇级变更维基覆盖不全（民政部 2019 后不再集中公布），兜底来源：国家地名信息库 dmfw.mca.gov.cn 的地名沿革、地级政府公告；
5. 疑似代码重用的条目：必须确认"新 id 单位"与"历史废止单位"是**两个不同的行政单位**（而非更名复活），并附证据。

#### 判读规则

对每条 diff 判定 change_type 与归属关系：

| change_type | 语义 | 判定要点 |
|---|---|---|
| `add` | 新设（全新单位，不从既有单位析出） | 新增 id 且无明确"析自"来源 |
| `split_from` | 析出新设 | 有明确母单位（如和康县析自皮山县）；必须给出 old_id |
| `merge_into` | 合并并入 | 旧单位撤销、疆域并入新/另一单位；detail 必须给出 `full_transfer`（旧区是否 100% 疆域并入单一承继者）与旁落明细 |
| `rename` | 更名 | old_id == new_id，仅名称变化 |
| `abolish` | 撤销（无承继） | 撤销且无主要承继单位 |
| `parent_change` | 隶属变更 | id 两版均在、pid 不同 |
| `code_change` | 代码变更 | 单位延续但行政代码整体更换（旧码退休、新码启用） |
| `code_reuse` | 代码重用 | 新 id 命中历史 status=0 废止行，且新旧是两个不同单位 |

- 用新旧两版 csv 做**下级配对**，生成 `child_id_map`（旧下级 id → 新下级 id，按名称/隶属关系匹配，覆盖任意层级）；
  对不上的下级回查资料，仍无法确认的在 `detail.summary` 标注疑点；
- 每条产出追加进 `changes.json`（契约见 `changes.schema.json`），**必须附 evidence**（title + url）；
- `split_from`/`merge_into` 的 `detail.summary` 写明疆域归属描述（如"析皮山县南部山区设立和康县"）。

### ③ 机器校验（脚本，不过则回 ② 修正）

```bash
php artisan area:check-changes storage/app/cmf-area/changes.json \
    --diff=storage/app/cmf-area/diff.json \
    --new=storage/app/cmf-area/ok_data_level4_{version}.csv
```

校验清单：schema（字段/枚举/evidence/full_transfer）+ 逻辑（覆盖率、id 存在性、类型与事实一致、child_id_map 配对完整性、代码重用、版本一致）。
**不通过则把错误清单作为修正输入，回到 ② 修正后重跑，直至全绿。**

### ④ 生成迁移（脚本）

```bash
php artisan area:generate-migration storage/app/cmf-area/changes.json \
    --new=storage/app/cmf-area/ok_data_level4_{version}.csv
# 产出 database/migrations/updates/{date}_area_update_{version}.php
```

生成物是薄壳迁移：只有冻结的 payload 数据（不含任何业务表名），执行逻辑在模块内置 MigrationExecutor。

### ⑤ 提交 PR，停下

1. 用新版 csv 覆盖 `database/data/ok_data_level4.csv`（新基线）；
2. 更新 `config/cmf-area.php` 的 `data_version` 为新版本号；
3. 把 changes.json 复制到 `database/data/changes_{version}.json` 留档；
4. 同一 PR 提交：新基线 csv + changes.json + 迁移文件 + config 变更；
5. **停**。后续由人工接管。

## 人工闸门（PR 审查要点，供维护者参考）

- evidence 链接真实可查、与判定结论一致；
- `confidence: "low"` 条目逐条人工复核；
- `full_transfer` 判定与疆域归属明细（child_id_map 配对完整性已由 area:check-changes 机器背书）；
- 迁移文件与 changes.json 内容一致。

合并 PR 即放行发版：打 tag `area-vX.Y.Z`（见仓库 RELEASING.md）。

## 代码重用专项

diff 命中 `code_reuse_suspected` 时：

1. 取证确认新旧两个 id 是**不同行政单位**（非更名复活），附证据；
2. changes.json 中该条用 `change_type: "code_reuse"`；
3. 生成迁移后 payload 会含 `archive` 操作：旧行主键迁至归档 id 段（`90{原id}`），ext_name 保留不变，
   所有 keep 策略业务引用一并指向归档 id（显示结果不变，语义不断链）；新单位正常使用官方代码；
4. PR 审查重点核对。

## 产出物示例（changes.json 单条）

```json
{
  "change_type": "split_from",
  "new_id": 653228, "new_name": "和康县",
  "old_id": 653223, "old_name": "皮山",
  "detail": {
    "summary": "析皮山县南部山区设立和康县，县政府驻原赛图拉镇（后更名昆岭镇）",
    "child_id_map": {"653223102": "653228101"},
    "renames": [{"old_id": 653223102, "new_id": 653228101, "from": "赛图拉镇", "to": "昆岭镇"}]
  },
  "evidence": [
    {"title": "2024年中华人民共和国县级以上行政区划变更列表", "url": "https://zh.wikipedia.org/wiki/..."},
    {"title": "新疆维吾尔自治区人民政府关于党中央、国务院批准设立和康县的公告", "url": "https://www.xinjiang.gov.cn/..."}
  ],
  "confidence": "high"
}
```
