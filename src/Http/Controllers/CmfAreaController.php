<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Area\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Quansitech\Cmf\Area\Models\Area;

/**
 * AreaPicker 级联数据端点：下级列表 / 祖先链。只返回 status=1 的正常地区，
 * 撤销行对新数据不可见（历史回显走业务侧名称快照）。
 */
class CmfAreaController extends Controller
{
    /**
     * 某 id 的直接下级；id 缺省时返回省级列表。
     */
    public function children(?int $id = null): JsonResponse
    {
        $rows = Area::query()
            ->active()
            ->when($id === null, fn ($q) => $q->where('deep', 0), fn ($q) => $q->where('pid', $id))
            ->orderBy('id')
            ->get(['id', 'name', 'ext_name']);

        $ids = $rows->pluck('id')->all();
        $hasChildren = Area::query()->active()->whereIn('pid', $ids)->select('pid')->distinct()->pluck('pid')->flip();

        return response()->json($rows->map(fn (Area $a): array => [
            'id' => $a->id,
            'name' => $a->name,
            'ext_name' => $a->ext_name,
            'has_children' => isset($hasChildren[$a->id]),
        ])->values());
    }

    /**
     * 某 id 的祖先链（含自身，自顶向下），用于已有值的回显。
     */
    public function path(int $id): JsonResponse
    {
        $chain = [];
        $guard = 0;
        $node = Area::query()->find($id);

        while ($node instanceof Area && $guard++ < 6) {
            array_unshift($chain, ['id' => $node->id, 'name' => $node->name, 'ext_name' => $node->ext_name, 'pid' => $node->pid]);
            $node = $node->pid ? Area::query()->find($node->pid) : null;
        }

        abort_if($chain === [], 404);

        return response()->json($chain);
    }
}
