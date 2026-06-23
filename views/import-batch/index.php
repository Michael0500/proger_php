<?php
/** @var yii\web\View $this */
/** @var array $initData */

use yii\helpers\Url;

$this->title = 'Импорт выписок — SmartMatch';
$initJson = json_encode($initData, JSON_UNESCAPED_UNICODE);
?>

<div id="imports-app" v-cloak>

    <!-- ══ TOOLBAR ══════════════════════════════════════════════ -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px">
        <div style="display:flex;align-items:center;gap:10px">
            <div style="width:36px;height:36px;background:linear-gradient(135deg,#0ea5e9,#6366f1);border-radius:10px;display:flex;align-items:center;justify-content:center">
                <i class="fas fa-file-import" style="color:#fff;font-size:16px"></i>
            </div>
            <div>
                <div style="font-size:18px;font-weight:800;color:#1a1f36;letter-spacing:-.3px">Импорт выписок</div>
                <div style="font-size:11px;color:#9ca3af;font-weight:500">Пачки загрузок и откат</div>
            </div>
        </div>
        <button class="btn-action btn-primary-violet" @click="reload" :disabled="loading">
            <i class="fas" :class="loading ? 'fa-spinner fa-spin' : 'fa-sync-alt'"></i>
            <span class="ms-1">Обновить</span>
        </button>
    </div>

    <div class="sm-card" style="padding:0;overflow:hidden">
        <table class="imp-table">
            <thead>
            <tr>
                <th style="width:60px">ID</th>
                <th>Тип</th>
                <th>Дата</th>
                <th>Счёт / файл</th>
                <th style="text-align:center">Записей</th>
                <th style="text-align:center">Балансов</th>
                <th style="text-align:center">Статус</th>
                <th style="width:140px"></th>
            </tr>
            </thead>
            <tbody>
            <tr v-for="b in batches" :key="b.id">
                <td style="color:#9ca3af">{{ b.id }}</td>
                <td><span class="imp-badge">{{ b.type_label }}</span></td>
                <td style="white-space:nowrap;color:#6b7280">{{ fmtDateTime(b.date_time) }}</td>
                <td>
                    <div v-if="b.account_name" style="font-weight:600">{{ b.account_name }}</div>
                    <div v-if="b.source_label" style="font-size:11px;color:#9ca3af">{{ b.source_label }}</div>
                    <span v-if="!b.account_name && !b.source_label" style="color:#d1d5db">—</span>
                </td>
                <td style="text-align:center">
                    <span :title="'В системе сейчас: ' + b.live_entries">{{ displayCount(b.imported_entries, b.live_entries) }}</span>
                </td>
                <td style="text-align:center">
                    <span :title="'В системе сейчас: ' + b.live_balances">{{ displayCount(b.imported_balances, b.live_balances) }}</span>
                </td>
                <td style="text-align:center">
                    <span v-if="b.is_rolled_back" class="imp-status imp-status-rolled" :title="'Откатано: ' + fmtDateTime(b.rolled_back_at)">
                        <i class="fas fa-undo"></i> Откатано
                    </span>
                    <span v-else-if="b.matched > 0" class="imp-status imp-status-locked">
                        <i class="fas fa-link"></i> Есть матчи ({{ b.matched }})
                    </span>
                    <span v-else-if="b.archived > 0" class="imp-status imp-status-locked">
                        <i class="fas fa-archive"></i> В архиве ({{ b.archived }})
                    </span>
                    <span v-else class="imp-status imp-status-ok">
                        <i class="fas fa-check"></i> Импортировано
                    </span>
                </td>
                <td style="text-align:right">
                    <button v-if="b.can_rollback" class="btn-action btn-danger-soft" @click="confirmRollback(b)" :disabled="rollingId === b.id">
                        <i class="fas" :class="rollingId === b.id ? 'fa-spinner fa-spin' : 'fa-undo'"></i>
                        <span class="ms-1">Откатить</span>
                    </button>
                    <span v-else style="font-size:11px;color:#9ca3af" :title="b.reason">{{ b.reason || '—' }}</span>
                </td>
            </tr>
            <tr v-if="!batches.length">
                <td colspan="8" style="text-align:center;color:#9ca3af;padding:30px">Пачек импорта пока нет</td>
            </tr>
            </tbody>
        </table>
    </div>

</div>

<style>
.imp-table { width:100%; border-collapse:collapse; font-size:13px; }
.imp-table th {
    padding:10px 12px; font-size:10.5px; font-weight:700; text-transform:uppercase;
    letter-spacing:.04em; color:#6b7280; border-bottom:2px solid #e5e7eb; background:#f9fafb; white-space:nowrap;
}
.imp-table td { padding:9px 12px; border-bottom:1px solid #f3f4f6; color:#374151; vertical-align:middle; }
.imp-table tr:last-child td { border-bottom:none; }
.imp-table tr:hover td { background:#fafbff; }

.imp-badge { display:inline-block; padding:1px 8px; border-radius:10px; font-size:11px; font-weight:700;
    background:#e0e7ff; color:#3730a3; font-family:monospace; }

.imp-status { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:700; padding:2px 9px; border-radius:10px; }
.imp-status-ok     { background:#dcfce7; color:#166534; }
.imp-status-locked { background:#fef3c7; color:#92400e; }
.imp-status-rolled { background:#f3f4f6; color:#6b7280; }

.btn-danger-soft { background:#fee2e2; color:#b91c1c; border:none; border-radius:8px; padding:5px 12px; font-size:12px; font-weight:600; cursor:pointer; }
.btn-danger-soft:hover { background:#fecaca; }
.btn-danger-soft:disabled { opacity:.6; cursor:default; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {

    var _init = <?= $initJson ?>;

    new Vue({
        el: '#imports-app',

        data: function () {
            return {
                batches:   _init.batches || [],
                loading:   false,
                rollingId: null,
            };
        },

        methods: {
            fmtDateTime: function (v) {
                if (!v) return '—';
                var d = new Date(v.replace(' ', 'T'));
                if (isNaN(d.getTime())) return v;
                var p = function (n) { return ('0' + n).slice(-2); };
                return p(d.getDate()) + '.' + p(d.getMonth() + 1) + '.' + d.getFullYear()
                    + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
            },
            displayCount: function (imported, live) {
                if (imported === null || imported === undefined) return live;
                return imported;
            },
            reload: function () {
                var self = this;
                self.loading = true;
                axios.get('<?= Url::to(['/import-batch/list']) ?>').then(function (r) {
                    if (r.data.success) self.batches = r.data.data;
                }).finally(function () { self.loading = false; });
            },
            confirmRollback: function (b) {
                var self = this;
                Swal.fire({
                    title: 'Откатить пачку?',
                    html: 'Пачка <b>#' + b.id + ' (' + b.type_label + ')</b> будет удалена из системы '
                        + 'вместе с записями и балансами. Действие необратимо.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Откатить',
                    cancelButtonText: 'Отмена',
                    confirmButtonColor: '#ef4444',
                }).then(function (r) {
                    if (!r.isConfirmed) return;
                    self.rollingId = b.id;
                    axios.post('<?= Url::to(['/import-batch/rollback']) ?>', { id: b.id }).then(function (res) {
                        if (res.data.success) {
                            if (res.data.data) self.batches = res.data.data;
                            Swal.fire({ icon: 'success', title: 'Откатано', text: res.data.message, timer: 2200, showConfirmButton: false });
                        } else {
                            Swal.fire({ icon: 'error', title: 'Ошибка', text: res.data.message });
                        }
                    }).catch(function () {
                        Swal.fire({ icon: 'error', title: 'Ошибка', text: 'Сетевая ошибка' });
                    }).finally(function () { self.rollingId = null; });
                });
            },
        },
    });

});
</script>
