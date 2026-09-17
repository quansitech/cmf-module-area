/**
 * cmf-area AreaPicker：省 → 市 → 区 → 镇级联选择（任意层级可选中）。
 * 原生 JS，无框架依赖；状态通过 Livewire 写回 Filament 表单字段。
 * 仅 status=1 的正常地区由端点返回，撤销行不可选。
 */
(function () {
    function livewireFor(el) {
        var host = el.closest('[wire\\:id]');
        return host ? window.Livewire.find(host.getAttribute('wire:id')) : null;
    }

    function fetchJson(url) {
        return fetch(url, { headers: { 'Accept': 'application/json' } }).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }
            return r.json();
        });
    }

    function makeSelect(placeholder) {
        var select = document.createElement('select');
        select.className = 'fi-input fi-select-input block rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-700';
        var empty = document.createElement('option');
        empty.value = '';
        empty.textContent = placeholder;
        select.appendChild(empty);
        return select;
    }

    function init(el) {
        var selectsWrap = el.querySelector('[data-cmf-area-selects]');

        // 已初始化且 DOM 完好时跳过；被 Livewire morph 清空时允许重建
        if (el.dataset.cmfAreaReady && selectsWrap && selectsWrap.children.length) { return; }
        el.dataset.cmfAreaReady = '1';

        var statePath = el.dataset.statePath;
        var rootUrl = el.dataset.rootUrl;
        var childrenUrl = el.dataset.childrenUrl;
        var pathUrl = el.dataset.pathUrl;
        var currentLabel = el.querySelector('[data-cmf-area-current]');
        var lw = livewireFor(el);

        var levels = ['请选择省', '请选择市', '请选择区/县', '请选择镇/街道'];
        var selects = [];
        var chain = [];

        function currentValue() {
            return chain.length ? chain[chain.length - 1] : null;
        }

        function syncState() {
            if (lw) { lw.set(statePath, currentValue()); }
            renderCurrent();
        }

        function renderCurrent() {
            if (!currentLabel) { return; }
            currentLabel.textContent = chainNames().length ? '已选：' + chainNames().join(' / ') : '';
        }

        var names = [];
        function chainNames() { return names.slice(0, chain.length); }

        function childrenEndpoint(pid) {
            return childrenUrl.replace('__ID__', String(pid));
        }

        function fillSelect(level, items) {
            var select = selects[level];
            while (select.options.length > 1) { select.remove(1); }
            items.forEach(function (item) {
                var opt = document.createElement('option');
                opt.value = item.id;
                opt.textContent = item.name;
                opt.dataset.extName = item.ext_name;
                opt.dataset.hasChildren = item.has_children ? '1' : '';
                select.appendChild(opt);
            });
            select.style.display = items.length ? '' : 'none';
        }

        function loadLevel(level, pid, selectedId) {
            var url = pid === null ? rootUrl : childrenEndpoint(pid);
            return fetchJson(url).then(function (items) {
                fillSelect(level, items);
                if (selectedId) {
                    selects[level].value = String(selectedId);
                }
            });
        }

        function truncateFrom(level) {
            chain = chain.slice(0, level);
            names = names.slice(0, level);
            for (var i = level + 1; i < selects.length; i++) {
                fillSelect(i, []);
            }
        }

        for (var i = 0; i < levels.length; i++) {
            (function (level) {
                var select = makeSelect(levels[level]);
                select.style.display = 'none';
                select.addEventListener('change', function () {
                    var id = select.value ? parseInt(select.value, 10) : null;
                    truncateFrom(level);

                    if (id === null) {
                        // 清空本级：选中值回退到上一级
                        syncState();
                        return;
                    }

                    chain[level] = id;
                    var opt = select.options[select.selectedIndex];
                    names[level] = opt ? (opt.dataset.extName || opt.textContent) : '';

                    if (opt && opt.dataset.hasChildren) {
                        loadLevel(level + 1, id, null);
                    }
                    syncState();
                });
                selects.push(select);
                selectsWrap.appendChild(select);
            })(i);
        }

        // 按状态值重建整个级联（含祖先链回填）；null / '' 表示清空
        function hydrateFromState(value) {
            var id = value === null || value === undefined || value === '' ? null : value;

            chain = [];
            names = [];

            if (id === null) {
                for (var i = 1; i < selects.length; i++) {
                    fillSelect(i, []);
                }

                return loadLevel(0, null, null).then(renderCurrent);
            }

            return loadLevel(0, null, null).then(function () {
                return fetchJson(pathUrl.replace('__ID__', String(id))).then(function (path) {
                    var promise = Promise.resolve();
                    path.forEach(function (node, level) {
                        promise = promise.then(function () {
                            chain[level] = node.id;
                            names[level] = node.ext_name || node.name;
                            selects[level].value = String(node.id);
                            if (level < levels.length - 1) {
                                return loadLevel(level + 1, node.id, null);
                            }
                        });
                    });
                    return promise.then(renderCurrent);
                });
            }).catch(function () { /* 旧值已被撤销或删除时静默降级为空 */ });
        }

        function stateId(value) {
            return value === null || value === undefined || value === '' ? '' : String(value);
        }

        // 初始回填；此后跟随服务端状态变化（表单重置 / 编辑页回填）
        hydrateFromState(lw ? lw.get(statePath) : null);

        if (lw && typeof lw.$watch === 'function') {
            lw.$watch(statePath, function (value) {
                // 忽略自身写入触发的回声（值未变）
                if (stateId(value) === stateId(currentValue())) { return; }
                hydrateFromState(value);
            });
        }
    }

    window.cmfAreaPickerInit = init;
})();
