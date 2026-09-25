---
name: area-upgrade
description: qscmf-filament area 模块行政区划升级的「判读」环节：根据任务包内的 diff 事实清单与两版 csv，联网取证后产出 changes 片段（changes.json v3），并自校验修正到全绿。当 agent 在升级采集任务包目录（含 COLLECT_TASK.md / diff.json / old.csv / new.csv）中工作时使用。下载、diff、迁移生成、基线补丁、PR 均由系统完成，不在本 skill 范围。
---

# 区划升级 · 判读 SOP

## 你的唯一任务

当前目录是升级采集**任务包**：`COLLECT_TASK.md`（任务说明）、`diff.json`（事实清单）、
`old.csv` / `new.csv`（两版数据）、`scope.json`（选中地区）。

你要做的只有三步：

1. **判读**：为选中地区的 diff 事实逐条判定归属（联网取证），产出 `changes_fragment.json`；
2. **自校验**：运行 COLLECT_TASK.md 给出的 `area:collect --ingest=...` 命令回收校验；
3. **修正**：校验不过时按错误清单修正 fragment，重新 ingest，直至全绿。

**明确禁止**（这些环节系统已完成或将由系统/人工完成，执行只会浪费时间与破坏状态）：

- 禁止运行 `area:download` / `area:diff` / `area:check-changes` / `area:generate-migration`
  / `area:patch-baseline` / `area:finalize-upgrade`；
- 禁止阅读实现源码（src/ 下的 CollectCommand、ChangesGraph、校验器等）——产物契约以本文件
  与 `changes.schema.json` 为准，源码不是契约；
- 禁止修改 old.csv / new.csv / diff.json / scope.json；禁止操作 git。

## 核心原则

1. **AI 只做判读**：落地由机器校验与人工审核把关，你的产物永远不直接生效；
2. **禁止编造**：查不到可靠来源的条目标 `confidence: "low"` 并在 summary 说明疑点，人工会复核；
3. **每条判定附证据**，但**一次取证可覆盖多条变更**：同一公告/词条核实的所有条目共用同一个
   证据池引用 id，不要为每条变更重复搜索同一来源。

## 产物契约（changes_fragment.json）

文件顶层结构（照此模板写，`version` / `from_version` 留占位即可，系统回收时补齐）：

```json
{
  "schema_version": 3,
  "version": "placeholder",
  "from_version": "placeholder",
  "evidence": {
    "引用id": {"title": "公告或词条标题", "url": "https://..."}
  },
  "changes": [
    {"kind": "node", "id": 500112, "name": "渝北区", "state": "retired",
     "summary": "…", "evidence": ["引用id"]},
    {"kind": "edge", "from_id": 500112, "to_id": 500157,
     "summary": "…", "evidence": ["引用id"]},
    {"kind": "edge", "from_id": 500112113, "to_id": 500109126}
  ]
}
```

只有两类记录：

- **node**：一个单位在某一版的存续状态。`state` ∈ `retired`（旧版有新版无）/
  `appeared`（新版新增）/ `continued`（同 id 属性变化，`attributes` 只写字段名清单，
  如 `["ext_name", "pid"]`，值由机器从两版 csv 取，**不要写值**）。
- **edge**：一条 `旧 id → 新 id` 对应关系（`from_id ≠ to_id`），拍平、任意层级、无嵌套。

可写字段白名单（除此之外的字段一律不写，机器可推导的字段已移出契约）：

- 不写 `side`（由 state 派生）、不写 `change_type`（由出入边派生）；
- node 可写：`id` `name` `state` `attributes`(仅 continued) `exceptions` `id_reuse`(仅 appeared)
  `summary` `evidence` `confidence`；
- edge 可写：`from_id` `to_id` `cross_level_reason`(跨层级必填) `summary` `evidence` `confidence`；
- `evidence` 只写证据池引用 id；node 与单位级边必须引用至少一条；**下级边可省略 evidence
  与 summary，自动继承其单位级边**——这正是减负设计，善用。

## 判读要点（四句话）

1. **一条边一个对应，越界就是另一条边**：不存在"容器"概念。"渝北 5 镇去了北碚"就是
   `edge(500112113 → 500109126)` 等独立边，与"其余 25 镇去两江"的边平级，无主从；
2. **例外挂节点**：无对应项的下级只挂在 node 的 `exceptions` 上（retired 节点挂旧侧消失下级，
   如同期撤并；appeared 节点挂新侧新增下级，如托管），且必须 ⊆ 本节点同侧子树、只在同侧存在；
3. **id 跨版本不等价**：同 id 的属性变化写 continued node，不是边；
4. **id 复用必须显式声明**：同 id 换单位时，appeared node 必须 `id_reuse: true` 并在 summary
   写明复用对应关系（整族复用可在单位级一次声明，覆盖其下级）；
5. **换码/撤并的两种合法写法**：极简 = 只写 edge（from 端即蕴含 retired、to 端即蕴含
   appeared，无需再写 node）；叙述型 = edge 之外补端点 node 挂 summary/evidence/exceptions
   （edge 是执行骨架、node 是叙述与证据载体，机器按图整体消费，二者不冲突）。
   要挂 `exceptions`/`attributes`/`id_reuse` 时必须写 node（这些字段只存在于 node）。

## 工作节奏（两阶段：先覆盖、后增强，任何时刻被杀都有产物）

判读进程有硬超时（通常 1800s），超时=整轮作废。因此**禁止"逐条死磕、最后一次性成文"**，
必须两阶段推进，保证超时前落盘一版完整产物：

**阶段一 · 覆盖（硬性时间盒：开始判读后 5 分钟内必须落盘第一版）**：

**禁止"取证完才动笔"**。联网取证之前，盘上必须已有一版完整产物——实测一轮纯取证
可以轻松烧掉全部超时预算而零落盘。正确顺序：

1. **纯本地判读（不联网）**：从 diff.json 取出选中地区事实，对每个 removed id 找去向
   （一条出边，或无出边=撤销）；对每个 added id 找来源（一条入边，或无入边=纯新设）；
   renamed/parent_changed 用 continued node 声明变化字段名。**同名/同码/明显对应的条目
   直接判定**，证据先留待阶段二补；
2. **立即一次成文**：按产物契约把**全量条目一次性写入** `changes_fragment.json`——
   疑难条目标 `confidence: "low"` 并在 summary 写明疑点（人工会复核），不许空着不写；
   单位级主体（撤并双方、新设单位）写 node 挂 summary/exceptions；叶子下级只靠边端点
   （`from ∉ 新版 ⇒ retired`、`to ∉ 旧版 ⇒ appeared`，无需再写 node）；
3. 立即运行一次 ingest 自校验，把结构错误先清掉。

**阶段二 · 增强**：按变更集群（同一批文/同一县）逐集群联网取证，一次取证覆盖该集群
所有条目，登记一个证据池条目共用，然后**整体重写文件**（禁止追加，追加会造成同一
(side, id) 的 node 重复出现，触发 I1 唯一键错误）；每完成一个集群就重写一次文件，
不要攒到最后。单集群取证软上限约 5 分钟 / 20 个 URL，超预算直接保 low 交卷，
把精力让给下一集群——校验和人工会兜底，比超时颗粒无收强。

**取证深度设限（实测教训）**：判读只需"新旧两版对照 + 变更时点证据"，
**禁止考古式逐年扫描**（如为确认一个单位的历史代码，对 2016-2023 每年各查一次快照）——
单个单位的历史快照查询 ≤ 2 次，查不到就标 low 交人工。被判读的是"这两版之间变了什么"，
不是修地方志。

## id 复用判读（同 id 换单位）

1. 先判**链式复用**还是**废止复用**：
   - 链式复用：旧单位在另一 id 下继续存续（南沙区 460302→460303），旧 id 被别的单位启用
     → 正常写边，业务数据会 remap 到旧单位的新 id；
   - 废止复用：旧单位彻底消失、无承继，同码被新单位启用 → 旧单位写 retired node（无出边）；
2. appeared node 声明 `id_reuse: true` 并在 summary 写明对应关系；
3. 链式复用检查**是否成环**（A→B、B→A 互换）：成环不可自动迁移，校验器会报错转人工。

## 取证策略（直达源清单，按优先级命中即停；全部 URL 已验证可用）

**县级及以上变更**：

1. 维基百科年度列表：`{年份}年中华人民共和国县级以上行政区划变更列表`，用 MediaWiki API
   取 wikitext 解析表格（2024、2025 年均存在）：
   `https://zh.wikipedia.org/w/api.php?action=parse&page={页面名}&prop=wikitext&format=json`
   （年份区间由任务说明的基线/上游版本号推出，格式 `{数据年}.{采集日期}.{发布日期}`）；
2. 跟进列表引用的政府公告原文（省级政府/民政厅网站）核实归属明细。

**乡镇级变更（年度变更列表不覆盖，本路径已实测）**：

1. **先取省级乡级总表一页对照**：维基词条 `中华人民共和国{省}乡级以上行政区列表`
   （如 `中华人民共和国上海市乡级以上行政区列表`，已验证存在），同一 API 取 wikitext，
   一页含全省乡镇街道现状与代码，新旧码归属大半可在此批量核实，**替代逐街道查词条**；
2. **官方代码对照（含村级 12 位）**：国家统计局统计用区划代码年度页官网已 404，
   用 Wayback 快照取原文（几 KB、秒回）：
   - 先用 CDX API 找快照时间戳：
     `http://web.archive.org/cdx/search/cdx?url=stats.gov.cn/sj/tjbz/tjyqhdmhcxhfdm/{年份}/{路径}.html&output=json&limit=3&filter=statuscode:200`
   - 再取原文（`id_` 后缀=无 wayback 外壳的原始字节）：
     `http://web.archive.org/web/{时间戳}id_/https://www.stats.gov.cn/sj/tjbz/tjyqhdmhcxhfdm/{年份}/{路径}.html`
   - 路径规则：省级 `{省2位}.html`；地级 `{省2位}/{市4位}.html`；县级 `{省2位}/{市4位}/{县6位}.html`
     （该页列所辖乡镇街道 9 位码+名称）；村级在 `{省2位}/{市4位}/{县6位}/{乡9位}.html`。
     新旧两版年份各取同一路径对照，即官方证据；
3. **区县政府公告**：乡镇级撤并设由省级政府批复、区县政府网站"通知公告/区划地名"栏转载
   （如"上海市奉贤区人民政府关于设立奉贤区头桥街道办事处的公告"，实测可达）。
   发现路径：维基省级列表页/词条的引用脚注 → 顺藤摸瓜，不要用搜索引擎泛搜。

**疑似代码重用**：必须确认"新 id 单位"与"历史废止单位"是**两个不同的行政单位**
（而非更名复活），并附证据。

**证据分层与置信度**：官方公告原文、统计局区划页快照 = high；维基百科 = 中（可作 high
的旁证，单独引用时 summary 说明依据）；聚合站点（超赞地名网等内容农场）只能作旁证，
且引用它的条目 confidence 必须标 low——审核人员会按此分层抽查。

**单独街道/乡镇词条仅作辅助**：wikitext 是 `#REDIRECT ...` 时按重定向跳一次即止；
是 `{{中国乡级行政区}}` 模板页时（正文只有模板引用）直接放弃该词条，转列表页/公告，
不要继续追模板子页。

## 自校验错误速查

- **「与已入库记录 #N 完全重复 / 内容冲突」**：该键已被先前判读或人工录入占用且
  **不由本次回收替换**（人工/已审定/其他地区的记录）。不要重复提交，也不要试图覆盖；
  确需修正时在最终回复里说明，由人工在审核页面编辑。
- **I1「同一 (side, id) 只允许一条 node」（changes[x] 与 changes[y] 互撞）**：你的
  fragment **内部**重复——把两条的 summary/evidence/exceptions 合并为一条后删除多余者。
- 本次地区内 AI 未审定的旧记录在回收时会被你的新产物**整体替换**：重跑后同键重写
  是安全的（不会报重复），尽管按你的最新判定整体成文。

**明确禁止（实测全是死路或时间黑洞）**：

- 禁止访问 dmfw.mca.gov.cn（国家地名信息库，实测 404 接口失效）；
- 禁止抓取搜索引擎结果页（360/百度/搜狗等，实测整页 300KB+ 且触发真人验证）；
  需要发现来源时改用维基 API 搜索（`action=query&list=search&srsearch=...`，JSON 小响应）；
- 禁止把网页 HTML 原文读进上下文：一律 `curl -s` 存到任务包 `tmp/` 下，
  用脚本（python/sed）抽取正文不超过 150 行再读；单文件 >100KB 必须先抽取；
- **所有 curl 必须带 `--max-time`**（建议 30s，脚本内的每个 curl 调用也要带）——
  实测有无超时的请求悬挂到整轮被杀；
- 多个 URL 的探测（CDX、快照头、状态码）合并进**一个脚本**一次跑完并逐行输出进度，
  禁止逐个单发 curl 命令（每个工具往返都是时间）。

## 产出物示例

撤乡设镇换码（最高频模式：乡撤掉、镇新设、换代码）——**只写一条 edge，任何 node 都不用写**
（两侧存续状态由边端点推导；同一 (side, id) 写两条 node 会被 I1 唯一键拒绝）：

```json
{
  "schema_version": 3,
  "version": "...",
  "evidence": {
    "cq-ck-2024": {"title": "重庆市人民政府关于城口县部分行政区划调整的批复", "url": "https://..."}
  },
  "changes": [
    {"kind": "edge", "from_id": 500229201, "to_id": 500229119,
     "summary": "撤龙田乡设龙田镇", "evidence": ["cq-ck-2024"]}
  ]
}
```

成片撤乡设镇（一批乡镇同一份批复）：共用一条证据，逐条各写一行 edge 即可：

```json
{
  "schema_version": 3,
  "version": "...",
  "evidence": {
    "cq-ck-2024": {"title": "重庆市人民政府关于城口县部分行政区划调整的批复", "url": "https://..."}
  },
  "changes": [
    {"kind": "edge", "from_id": 500229201, "to_id": 500229119, "evidence": ["cq-ck-2024"]},
    {"kind": "edge", "from_id": 500229202, "to_id": 500229120, "evidence": ["cq-ck-2024"]},
    {"kind": "node", "id": 500229, "name": "城口县", "state": "continued",
     "summary": "撤龙田乡设龙田镇、撤北屏乡设北屏镇（同批复一批）",
     "evidence": ["cq-ck-2024"]}
  ]
}
```

撤并合设（2025 重庆：撤江北区、渝北区，合设两江新区）——证据入池一次，2 个 retired node
+ 1 个 appeared node + 2 条单位级边自证，其余下级边各一行（继承单位级边的证据与摘要）：

```json
{
  "schema_version": 3,
  "version": "2025.251231.260403",
  "from_version": "2023.240319.250114",
  "evidence": {
    "gov-2025-cq": {"title": "国务院关于同意重庆市调整部分行政区划的批复", "url": "https://..."}
  },
  "changes": [
    {"kind": "node", "id": 500112, "name": "渝北区", "state": "retired",
     "summary": "撤销渝北区：大部分区域并入两江新区，5 镇划归北碚区，单位无单一承继者",
     "evidence": ["gov-2025-cq"]},
    {"kind": "node", "id": 500157, "name": "两江新区", "state": "appeared",
     "exceptions": [
       {"id": 500157005, "reason": "新版两江新区托管原北碚区水土街道，原单位仍在，无旧版对应"}
     ],
     "summary": "撤销江北区、渝北区，设立两江新区",
     "evidence": ["gov-2025-cq"]},
    {"kind": "edge", "from_id": 500112, "to_id": 500157,
     "summary": "渝北区大部分区域并入两江新区",
     "evidence": ["gov-2025-cq"]},
    {"kind": "edge", "from_id": 500112113, "to_id": 500109126}
  ]
}
```

析出新设（2024 和康县析自皮山县，母体存续）：

```json
{
  "schema_version": 3,
  "version": "...",
  "evidence": {
    "gov-xj": {"title": "新疆维吾尔自治区人民政府公告", "url": "https://..."}
  },
  "changes": [
    {"kind": "node", "id": 653228, "name": "和康县", "state": "appeared",
     "summary": "析皮山县南部山区设立和康县，县政府驻原赛图拉镇（更名昆岭镇）",
     "evidence": ["gov-xj"]},
    {"kind": "edge", "from_id": 653223, "to_id": 653228,
     "summary": "和康县析自皮山县（皮山县存续）", "evidence": ["gov-xj"]},
    {"kind": "edge", "from_id": 653223102, "to_id": 653228101,
     "summary": "赛图拉镇划归和康县并更名昆岭镇"}
  ]
}
```
