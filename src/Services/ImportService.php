<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

use Illuminate\Support\Facades\DB;
use Quansitech\Cmf\Area\Models\Area;
use RuntimeException;

/**
 * csv → 分块批量插入 cmf_areas。
 * csv 为上游 ok_data_level4.csv 格式：UTF-8 带 BOM、双引号限定符，
 * 列序 id,pid,deep,name,pinyin_prefix,pinyin,ext_id,ext_name。
 */
class ImportService
{
    public const CHUNK_SIZE = 1000;

    /**
     * 读取上游 csv 并解析为行数组（不做类型转换之外的加工）。
     *
     * @return \Generator<int, array{id:int, pid:int, deep:int, name:string, pinyin_prefix:string, pinyin:string, ext_id:int, ext_name:string}>
     */
    public function readCsv(string $path): \Generator
    {
        if (! is_file($path)) {
            throw new RuntimeException("csv 文件不存在：{$path}");
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("无法打开 csv 文件：{$path}");
        }

        try {
            // 剥离 UTF-8 BOM
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            $header = fgetcsv($handle, escape: '');
            if ($header === false || $header[0] !== 'id') {
                throw new RuntimeException("csv 表头不符合预期（应为 id,pid,deep,...）：{$path}");
            }

            while (($row = fgetcsv($handle, escape: '')) !== false) {
                if (count($row) < 8) {
                    continue;
                }

                yield [
                    'id' => (int) $row[0],
                    'pid' => (int) $row[1],
                    'deep' => (int) $row[2],
                    'name' => $row[3],
                    'pinyin_prefix' => $row[4],
                    'pinyin' => $row[5],
                    'ext_id' => (int) $row[6],
                    'ext_name' => $row[7],
                ];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * 导入 csv 到 cmf_areas（truncate 后全量导入）。
     *
     * @return int 导入行数
     */
    public function import(string $path): int
    {
        DB::table('cmf_areas')->truncate();

        $count = 0;
        $chunk = [];

        foreach ($this->readCsv($path) as $row) {
            $chunk[] = $row + ['status' => 1, 'successor_id' => null, 'created_at' => now(), 'updated_at' => now()];

            if (count($chunk) >= self::CHUNK_SIZE) {
                DB::table('cmf_areas')->insert($chunk);
                $count += count($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            DB::table('cmf_areas')->insert($chunk);
            $count += count($chunk);
        }

        return $count;
    }

    /**
     * 把 csv 文件导入为以 id 为键的数组（diff / 校验用，不碰数据库）。
     *
     * @return array<int, array{id:int, pid:int, deep:int, name:string, pinyin_prefix:string, pinyin:string, ext_id:int, ext_name:string}>
     */
    public function loadCsvAsMap(string $path): array
    {
        $map = [];

        foreach ($this->readCsv($path) as $row) {
            $map[$row['id']] = $row;
        }

        return $map;
    }

    /**
     * 从数据库 cmf_areas 加载为以 id 为键的数组（代码重用检测用）。
     *
     * @return array<int, Area>
     */
    public function loadDbAsMap(): array
    {
        return Area::query()->get()->keyBy('id')->all();
    }
}
