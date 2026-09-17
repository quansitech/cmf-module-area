# 数据来源声明

本目录 `ok_data_level4.csv` 来自开源项目
[AreaCity-JsSpider-StatsGov](https://github.com/xiangyuecn/AreaCity-JsSpider-StatsGov)
（作者：xiangyuecn，**MIT License**）的 Release 数据文件 `ok_data_level3-4.csv.7z`
解压所得，内容为全国四级行政区划（省 / 市 / 区县 / 乡镇）。

- 当前基线版本：`2025.251231.260403`（与 `config/cmf-area.php` 的 `data_version` 同步更新）
- 上游 Release：https://github.com/xiangyuecn/AreaCity-JsSpider-StatsGov/releases

## 许可与合规

- AreaCity-JsSpider-StatsGov 以 MIT 协议发布，允许商用与再分发，需保留版权声明；
- 上游数据的采集源包含高德地图 / 国家地名信息库等，商业使用请自行评估其服务条款；
- 本模块不对数据的准确性、时效性作任何担保；区划变更以政府公告为准。

## 升级方式

数据更新通过发布模块新版本实现（迁移文件名携带上游版本号），升级流水线见 `skill/SKILL.md`。
