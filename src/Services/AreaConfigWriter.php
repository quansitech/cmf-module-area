<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

use RuntimeException;

/**
 * 模块 config/cmf-area.php 的版本号写入器（定稿动作使用）。
 * 只改 data_version / upstream_base 两个标量值，其余配置行原样保留。
 * 宿主已发布同名 config 时优先改写宿主文件（与运行期生效值一致）。
 */
class AreaConfigWriter
{
    public function configPath(): string
    {
        $override = config('cmf-area.upgrade.config_file');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        $published = config_path('cmf-area.php');

        return is_file($published) ? $published : dirname(__DIR__, 2).'/config/cmf-area.php';
    }

    /**
     * 写入/更新一个字符串配置项（'key' => 'value' 单行格式）。
     */
    public function write(string $key, string $value): void
    {
        $path = $this->configPath();
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("无法读取配置文件：{$path}");
        }

        $line = "'{$key}' => '".addslashes($value)."',";
        $pattern = '/^([ \t]*)\''.preg_quote($key, '/').'\'\s*=>\s*\'[^\']*\',/m';

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, '$1'.$line, $content, 1);
        } else {
            // 键不存在：插到 data_version 行之后（保持版本相关配置聚拢）
            $anchor = '/^([ \t]*)\'data_version\'\s*=>\s*\'[^\']*\',$/m';
            if (preg_match($anchor, $content, $m, PREG_OFFSET_CAPTURE)) {
                $insertAt = (int) $m[0][1] + strlen($m[0][0]);
                $content = substr($content, 0, $insertAt)."\n\n    ".$line.substr($content, $insertAt);
            } else {
                throw new RuntimeException("配置文件缺少 data_version 锚点，无法插入 {$key}：{$path}");
            }
        }

        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException("无法写入配置文件：{$path}");
        }
    }
}
