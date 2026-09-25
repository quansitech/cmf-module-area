<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Quansitech\Cmf\Area\Models\AreaReference;
use ReflectionClass;
use RuntimeException;

/**
 * 引用声明收集器：
 *  - 读取单个模型的 areaReferences() 声明（Cast / Picker 校验用）；
 *  - 以已注册 ServiceProvider 为锚点反推扫描范围，收集全部声明并幂等落库
 *    （area:sync-references）。装了就会被扫到，卸载即消失（见开发方案 §6.2）。
 */
class ReferenceCollector
{
    /** @var array<class-string, array<string, array{onMerge:string, snapshotColumn:?string, pkColumn:?string, description:string}>> */
    protected array $declarationCache = [];

    /**
     * 读取模型声明（纯数据、无副作用）。未定义 areaReferences() 时返回空数组。
     *
     * @param  class-string<Model>  $modelClass
     * @return array<string, array{onMerge:string, snapshotColumn:?string, pkColumn:?string, description:string}>
     */
    public function declarationsOf(string $modelClass): array
    {
        if (isset($this->declarationCache[$modelClass])) {
            return $this->declarationCache[$modelClass];
        }

        if (! method_exists($modelClass, 'areaReferences')) {
            return $this->declarationCache[$modelClass] = [];
        }

        /** @var array<string, mixed> $raw */
        $raw = $modelClass::areaReferences();
        $normalized = [];

        foreach ($raw as $column => $options) {
            $options = is_array($options) ? $options : [];
            /** @var string $onMerge */
            $onMerge = $options['onMerge'] ?? AreaReference::STRATEGY_KEEP;

            if (! in_array($onMerge, AreaReference::STRATEGIES, true)) {
                throw new RuntimeException(
                    "[{$modelClass}::areaReferences()] 字段 {$column} 的 onMerge 取值非法：{$onMerge}，"
                    .'可选值：'.implode(' / ', AreaReference::STRATEGIES)
                );
            }

            $normalized[$column] = [
                'onMerge' => $onMerge,
                'snapshotColumn' => isset($options['snapshotColumn']) ? (string) $options['snapshotColumn'] : null,
                // 行级回滚日志按主键寻址；表无单主键（默认 id）时在此声明
                'pkColumn' => isset($options['pkColumn']) ? (string) $options['pkColumn'] : null,
                'description' => (string) ($options['description'] ?? ''),
            ];
        }

        return $this->declarationCache[$modelClass] = $normalized;
    }

    /**
     * 该字段是否已在模型声明。
     *
     * @param  class-string<Model>  $modelClass
     */
    public function isDeclared(string $modelClass, string $column): bool
    {
        return array_key_exists($column, $this->declarationsOf($modelClass));
    }

    /**
     * 以已注册 ServiceProvider 为锚点，推导扫描范围内的全部模型类。
     * 只扫描 composer.json 中 require 了 quansitech/cmf-module-area 的包
     * （业务包使用地区引用必然依赖本模块），避免遍历整个 vendor。
     *
     * @return array<string, list<class-string<Model>>> 包名 => 模型类清单
     */
    public function discoverModels(): array
    {
        $result = [];
        $scanned = [];

        foreach (array_keys(app()->getLoadedProviders()) as $providerClass) {
            $packageRoot = $this->packageRootOf((string) $providerClass);
            if ($packageRoot === null || isset($scanned[$packageRoot])) {
                continue;
            }
            $scanned[$packageRoot] = true;

            if (! $this->shouldScanPackage($packageRoot)) {
                continue;
            }

            $packageName = $this->packageNameOf($packageRoot) ?? (string) $providerClass;

            foreach ($this->modelsInPackage($packageRoot) as $modelClass) {
                if ($this->declarationsOf($modelClass) !== []) {
                    $result[$packageName][] = $modelClass;
                }
            }
        }

        // 兜底：config scan_paths（无 provider 的纯模型库等边缘场景）
        /** @var list<string> $scanPaths */
        $scanPaths = config('cmf-area.scan_paths', []);
        foreach ($scanPaths as $path) {
            $absolute = str_starts_with($path, '/') ? $path : base_path($path);
            foreach ($this->modelsInDirectory($absolute) as $modelClass) {
                if ($this->declarationsOf($modelClass) !== []) {
                    $result['config:scan_paths'][] = $modelClass;
                }
            }
        }

        return $result;
    }

    /**
     * 收集全部声明并幂等 upsert 落库。
     *
     * 落库前校验被注册列的数据类型：必须是整型（int2/int4/int8），
     * 否则迁移生成的整型 UPDATE 在 PG 上类型不匹配（见开发方案 §3.5-1），拒绝登记。
     *
     * @return array{packages: array<string, int>, synced: int, skipped: list<string>}
     */
    public function sync(): array
    {
        $rows = [];
        $packages = [];
        $skipped = [];

        foreach ($this->discoverModels() as $package => $modelClasses) {
            $packages[$package] = 0;

            foreach ($modelClasses as $modelClass) {
                $model = new $modelClass;
                $table = $model->getTable();

                foreach ($this->declarationsOf($modelClass) as $column => $options) {
                    $this->assertIntegerColumn($table, $column);

                    $rows[] = [
                        'table_name' => $table,
                        'column_name' => $column,
                        'pk_column' => $options['pkColumn'],
                        'merge_strategy' => $options['onMerge'],
                        'snapshot_column' => $options['snapshotColumn'],
                        'description' => $options['description'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $packages[$package]++;
                }
            }
        }

        // 兜底注册口：Area::registerReference() 手工登记的（DB facade 操作的历史表等）
        foreach (self::$manualReferences as $key => $ref) {
            [$table, $column] = explode('.', $key, 2);
            $this->assertIntegerColumn($table, $column);
            $rows[] = $ref + ['created_at' => now(), 'updated_at' => now()];
            $packages['manual'] = ($packages['manual'] ?? 0) + 1;
        }

        // 幂等 upsert；已不存在于任何声明中的登记保留（表可能已下线但数据仍在），
        // 由业务方自行清理，避免误删影响迁移判断。
        foreach (array_chunk($rows, 500) as $chunk) {
            AreaReference::query()->upsert(
                $chunk,
                ['table_name', 'column_name'],
                ['pk_column', 'merge_strategy', 'snapshot_column', 'description', 'updated_at'],
            );
        }

        return ['packages' => $packages, 'synced' => count($rows), 'skipped' => $skipped];
    }

    /** @var array<string, array{table_name:string, column_name:string, pk_column:?string, merge_strategy:string, snapshot_column:?string, description:string}> */
    protected static array $manualReferences = [];

    /**
     * 兜底注册口：无 Model 的表（DB facade 操作的历史表）手工登记。
     * $pkColumn：表无单主键（默认 id）时显式声明主键列，供行级回滚日志寻址。
     */
    public static function registerReference(
        string $table,
        string $column,
        string $onMerge = AreaReference::STRATEGY_KEEP,
        ?string $snapshotColumn = null,
        string $description = '',
        ?string $pkColumn = null,
    ): void {
        if (! in_array($onMerge, AreaReference::STRATEGIES, true)) {
            throw new RuntimeException("onMerge 取值非法：{$onMerge}");
        }

        self::$manualReferences["{$table}.{$column}"] = [
            'table_name' => $table,
            'column_name' => $column,
            'pk_column' => $pkColumn,
            'merge_strategy' => $onMerge,
            'snapshot_column' => $snapshotColumn,
            'description' => $description,
        ];
    }

    /**
     * 校验被注册列的数据库类型必须是整型（int2/int4/int8）。
     * PG 对运算符两侧类型严格校验，varchar 存 adcode 会在迁移 UPDATE 时报错。
     */
    public function assertIntegerColumn(string $table, string $column): void
    {
        $type = $this->columnType($table, $column);

        if ($type === null) {
            throw new RuntimeException("[{$table}.{$column}] 列不存在，无法登记地区引用");
        }

        $integerTypes = ['int2', 'int4', 'int8', 'smallint', 'integer', 'bigint', 'tinyint', 'mediumint', 'int'];

        if (! in_array(strtolower($type), $integerTypes, true)) {
            throw new RuntimeException(
                "[{$table}.{$column}] 列类型为 {$type}，地区引用列必须是整型（int2/int4/int8）；"
                .'PostgreSQL 类型严格校验，非整型列会导致迁移 UPDATE 报类型错误，拒绝登记。'
            );
        }
    }

    /**
     * 读取列的数据库类型（driver 感知）。
     */
    protected function columnType(string $table, string $column): ?string
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        return match ($driver) {
            'pgsql' => $this->pgColumnType($table, $column),
            'sqlite' => $this->sqliteColumnType($table, $column),
            default => $this->mysqlColumnType($table, $column),
        };
    }

    protected function pgColumnType(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            'select data_type from information_schema.columns where table_name = ? and column_name = ? '
            .'and table_schema = current_schema()',
            [$table, $column],
        );

        return $row->data_type ?? null;
    }

    protected function sqliteColumnType(string $table, string $column): ?string
    {
        foreach (DB::select("pragma table_info(\"{$table}\")") as $col) {
            if ($col->name === $column) {
                return $col->type === '' ? 'integer' : $col->type;
            }
        }

        return null;
    }

    protected function mysqlColumnType(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            'select data_type from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
            [$table, $column],
        );

        return $row->data_type ?? null;
    }

    /**
     * 反射 provider 类定位包根目录：含 composer.json 的最近祖先目录。
     */
    protected function packageRootOf(string $providerClass): ?string
    {
        if (! class_exists($providerClass)) {
            return null;
        }

        $file = (new ReflectionClass($providerClass))->getFileName();
        if ($file === false) {
            return null;
        }

        $dir = dirname($file);
        $guard = 0;

        while ($guard++ < 10) {
            if (is_file($dir.'/composer.json')) {
                return $dir;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    protected function packageNameOf(string $packageRoot): ?string
    {
        $composer = json_decode((string) file_get_contents($packageRoot.'/composer.json'), true);

        return is_array($composer) && isset($composer['name']) ? (string) $composer['name'] : null;
    }

    /**
     * 该包是否纳入扫描：本模块自身，或 require 了本模块的包（业务包装了本模块才可能声明地区引用）。
     */
    protected function shouldScanPackage(string $packageRoot): bool
    {
        $composer = json_decode((string) file_get_contents($packageRoot.'/composer.json'), true);
        if (! is_array($composer)) {
            return false;
        }

        if (($composer['name'] ?? '') === 'quansitech/cmf-module-area') {
            return true;
        }

        $requires = array_merge(
            array_keys((array) ($composer['require'] ?? [])),
            array_keys((array) ($composer['require-dev'] ?? [])),
        );

        return in_array('quansitech/cmf-module-area', $requires, true);
    }

    /**
     * 按 composer PSR-4 映射扫描包内定义了 areaReferences() 的 Model 子类。
     *
     * @return list<class-string<Model>>
     */
    protected function modelsInPackage(string $packageRoot): array
    {
        $composer = json_decode((string) file_get_contents($packageRoot.'/composer.json'), true);
        if (! is_array($composer)) {
            return [];
        }

        /** @var array<string, string|list<string>> $psr4 */
        $psr4 = $composer['autoload']['psr-4'] ?? [];
        $models = [];

        foreach ($psr4 as $namespace => $dirs) {
            foreach ((array) $dirs as $dir) {
                $models = array_merge($models, $this->modelsInDirectory($packageRoot.'/'.ltrim($dir, '/'), $namespace));
            }
        }

        return $models;
    }

    /**
     * 扫描目录下的 PHP 类，筛出定义了 areaReferences() 的 Model 子类。
     *
     * @return list<class-string<Model>>
     */
    protected function modelsInDirectory(string $directory, string $namespace = ''): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $models = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = $this->classFromFile($file->getPathname(), $directory, $namespace);
            if ($class === null || ! class_exists($class)) {
                continue;
            }

            if (is_subclass_of($class, Model::class) && method_exists($class, 'areaReferences')) {
                $models[] = $class;
            }
        }

        return $models;
    }

    /**
     * 由文件路径推导类名：给定了 PSR-4 前缀时直接映射（目录即前缀对应目录）；
     * 未给定时读取文件内的 namespace 声明。
     */
    protected function classFromFile(string $file, string $baseDir, string $namespace): ?string
    {
        $baseDir = rtrim($baseDir, '/');

        if ($namespace !== '' && str_starts_with($file, $baseDir.'/')) {
            return rtrim($namespace, '\\').'\\'.str_replace('/', '\\', substr($file, strlen($baseDir) + 1, -4));
        }

        // 读 namespace 声明（scan_paths 场景）
        $contents = (string) file_get_contents($file);
        if (! preg_match('/namespace\s+([^;]+);/', $contents, $m)) {
            return null;
        }

        return trim($m[1]).'\\'.basename($file, '.php');
    }
}
