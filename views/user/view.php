<?php

use yii\helpers\Html;
use yii\helpers\Url;

/** @var yii\web\View $this */
/** @var app\models\User     $model */
/** @var app\models\Company[] $companies */
/** @var app\models\AccountPool[] $pools */
/** @var app\models\Account[]     $accounts */

$this->title = 'Профиль';

// Начальные данные для Vue (рендерятся сервером, без лишних AJAX на старте)
$initData = [
        'user' => [
                'id'        => $model->id,
                'username'  => $model->username,
                'email'     => $model->email,
                'companyId' => $model->company_id ?: null,
        ],
        'companies' => array_map(fn($c) => [
                'id' => $c->id, 'name' => $c->name, 'code' => $c->code,
        ], $companies),
        'pools' => array_map(fn($p) => [
                'id' => $p->id, 'name' => $p->name,
        ], $pools),
        'accounts' => array_map(fn($a) => [
                'id'          => $a->id,
                'name'        => $a->name,
                'currency'    => $a->currency,
                'is_suspense' => (bool) $a->is_suspense,
                'pool_id'     => $a->pool_id,
                'pool_name'   => $a->pool ? $a->pool->name : null,
        ], $accounts),
        'routes' => [
                'getPools'      => Url::to(['/user/get-pools']),
                'getAccounts'   => Url::to(['/user/get-accounts']),
                'selectCompany' => Url::to(['/user/select-company']),
                'resetCompany'  => Url::to(['/user/reset-company']),
                'createAccount' => Url::to(['/user/create-account']),
                'deleteAccount' => Url::to(['/user/delete-account']),
        ],
];
?>

<style>
    /* ═══════════════════════════════════════════
       Profile page — Vue2 app styles
       ═══════════════════════════════════════════ */
    #profile-app { max-width: 960px; margin: 0 auto; padding: 28px 20px 64px; }

    /* ── Header card ── */
    .pf-head {
        display: flex; align-items: center; gap: 20px;
        background: #fff; border: 1px solid #e5e9f2; border-radius: 14px;
        padding: 24px 28px; margin-bottom: 18px;
        box-shadow: 0 2px 14px rgba(79,70,229,.07);
    }
    .pf-ava {
        width: 64px; height: 64px; border-radius: 50%; flex-shrink: 0;
        background: linear-gradient(135deg,#4f46e5,#7c3aed);
        display: flex; align-items: center; justify-content: center;
        font-size: 24px; color: #fff; font-weight: 700;
    }
    .pf-meta h2 { margin: 0 0 3px; font-size: 19px; font-weight: 700; color: #1a1f36; }
    .pf-meta p  { margin: 0; font-size: 13px; color: #6b7a99; }
    .pf-cbadge  { margin-left: auto; padding: 5px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; white-space: nowrap; background: #ede9fe; color: #4f46e5; }
    .pf-cbadge.none { background: #fef3c7; color: #92400e; }

    /* ── Section card ── */
    .pf-sec {
        background: #fff; border: 1px solid #e5e9f2; border-radius: 14px;
        padding: 20px 24px; margin-bottom: 16px;
        box-shadow: 0 2px 14px rgba(79,70,229,.07);
    }
    .pf-stitle {
        font-size: 11px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .07em; color: #6b7a99;
        margin: 0 0 14px; display: flex; align-items: center; gap: 7px;
    }
    .pf-stitle i { color: #4f46e5; }

    /* ── Company grid ── */
    .pf-companies { display: grid; grid-template-columns: repeat(auto-fill,minmax(170px,1fr)); gap: 10px; }
    .pf-comp-btn {
        border: 2px solid #e5e9f2; border-radius: 10px;
        padding: 14px 16px; cursor: pointer; transition: all .17s;
        display: flex; flex-direction: column; gap: 5px; position: relative;
        background: #fff; text-align: left;
    }
    .pf-comp-btn:hover:not(:disabled) { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,.1); }
    .pf-comp-btn.is-active { border-color: #4f46e5; background: #f5f3ff; }
    .pf-comp-btn.is-active::after {
        content: '✓'; position: absolute; top: 8px; right: 10px;
        width: 18px; height: 18px; background: #4f46e5; color: #fff;
        border-radius: 50%; font-size: 10px; font-weight: 700;
        display: flex; align-items: center; justify-content: center;
    }
    .pf-comp-btn:disabled { opacity: .55; cursor: not-allowed; }
    .pf-comp-name { font-weight: 700; font-size: 14px; color: #1a1f36; }
    .pf-comp-code { font-size: 11px; font-weight: 600; padding: 2px 7px; border-radius: 5px; background: #e0e7ff; color: #3730a3; width: fit-content; }
    .pf-comp-reset { border: 2px dashed #e5e9f2; color: #9ca3af; align-items: center; justify-content: center; text-align: center; }
    .pf-comp-reset:hover:not(:disabled) { border-color: #ef4444 !important; color: #ef4444 !important; }

    /* ── Accounts table ── */
    .pf-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .pf-table th {
        text-align: left; padding: 8px 10px; background: #f9fafb;
        font-size: 10.5px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .06em; color: #6b7a99; border-bottom: 2px solid #e5e9f2;
    }
    .pf-table td  { padding: 9px 10px; border-bottom: 1px solid #f0f2f7; color: #1a1f36; vertical-align: middle; }
    .pf-table tr:last-child td { border-bottom: none; }
    .pf-table tr:hover td { background: #fafbff; }
    .tag-sus { background: #fef3c7; color: #92400e; padding: 2px 7px; border-radius: 5px; font-size: 10.5px; font-weight: 600; }
    .tag-nos { background: #e0e7ff; color: #3730a3; padding: 2px 7px; border-radius: 5px; font-size: 10.5px; font-weight: 600; }
    .tag-cur { background: #d1fae5; color: #065f46; padding: 2px 7px; border-radius: 5px; font-size: 10.5px; font-weight: 600; font-family: monospace; }

    /* ── Add account form ── */
    .pf-add-form {
        display: grid; grid-template-columns: 2fr 1fr 1fr auto;
        gap: 9px; align-items: end;
        margin-top: 15px; padding-top: 15px; border-top: 1px solid #e5e9f2;
    }
    .pf-fg { display: flex; flex-direction: column; gap: 3px; }
    .pf-lbl { font-size: 10.5px; font-weight: 600; color: #6b7a99; text-transform: uppercase; letter-spacing: .05em; }
    .pf-inp, .pf-sel {
        border: 1px solid #e5e9f2; border-radius: 7px;
        padding: 7px 10px; font-size: 13px; outline: none;
        background: #fff; color: #1a1f36; transition: border-color .15s; width: 100%;
    }
    .pf-inp:focus, .pf-sel:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,.1); }

    /* ── Buttons ── */
    .pf-btn-add {
        background: #4f46e5; color: #fff; border: none; border-radius: 7px;
        padding: 8px 15px; font-size: 13px; font-weight: 600;
        cursor: pointer; transition: background .15s;
        display: inline-flex; align-items: center; gap: 5px; white-space: nowrap;
    }
    .pf-btn-add:hover { background: #4338ca; }
    .pf-btn-add:disabled { opacity: .5; cursor: not-allowed; }
    .pf-btn-del {
        background: #fef2f2; color: #ef4444; border: 1px solid #fecaca;
        border-radius: 5px; padding: 4px 9px; font-size: 11.5px; font-weight: 600;
        cursor: pointer; transition: all .15s;
    }
    .pf-btn-del:hover:not(:disabled) { background: #ef4444; color: #fff; }
    .pf-btn-del:disabled { opacity: .5; }

    /* ── States ── */
    .pf-warn  { background: #fef3c7; border: 1px solid #fde68a; border-radius: 9px; padding: 11px 15px; color: #92400e; font-size: 13px; display: flex; align-items: center; gap: 8px; }
    .pf-info  { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 9px; padding: 11px 15px; color: #1e40af; font-size: 13px; display: flex; align-items: center; gap: 8px; }
    .pf-empty { text-align: center; padding: 28px; color: #9ca3af; font-size: 13px; }
    .pf-empty i { font-size: 28px; margin-bottom: 8px; display: block; color: #d1d5db; }

    @media(max-width:640px) {
        .pf-add-form { grid-template-columns: 1fr 1fr; }
        .pf-add-form .pf-btn-add { grid-column: span 2; }
        .pf-head { flex-wrap: wrap; }
        .pf-cbadge { margin-left: 0; }
    }
</style>

<!-- ════════════════════════════════════════
     Vue2 Profile App
     ════════════════════════════════════════ -->
<div id="profile-app">

    <!-- Header -->
    <div class="pf-head">
        <div class="pf-ava">{{ user.username.charAt(0).toUpperCase() }}</div>
        <div class="pf-meta">
            <h2>{{ user.username }}</h2>
            <p>{{ user.email }} &nbsp;·&nbsp; ID&nbsp;{{ user.id }}</p>
        </div>
        <span v-if="currentCompany" class="pf-cbadge">
            <i class="fas fa-building me-1"></i>{{ currentCompany.name }}
        </span>
        <span v-else class="pf-cbadge none">
            <i class="fas fa-exclamation-triangle me-1"></i>Нет компании
        </span>
    </div>

    <!-- Flash alert -->
    <transition name="fade">
        <div v-if="flash.msg"
             :class="['alert', flash.type === 'success' ? 'alert-success' : 'alert-danger',
                      'd-flex align-items-center mb-3']"
             style="border-radius:10px">
            <i :class="['fas me-2', flash.type === 'success' ? 'fa-check-circle' : 'fa-times-circle']"></i>
            <span>{{ flash.msg }}</span>
            <button type="button" class="btn-close ms-auto" @click="flash.msg=null"></button>
        </div>
    </transition>

    <!-- ── Company section ── -->
    <div class="pf-sec">
        <div class="pf-stitle"><i class="fas fa-building"></i>Компания</div>
        <div class="pf-companies">
            <button v-for="comp in companies" :key="comp.id"
                    class="pf-comp-btn"
                    :class="{ 'is-active': user.companyId === comp.id }"
                    :disabled="busy"
                    @click="selectCompany(comp)">
                <span class="pf-comp-name">{{ comp.name }}</span>
                <span class="pf-comp-code">{{ comp.code }}</span>
            </button>
            <button v-if="user.companyId"
                    class="pf-comp-btn pf-comp-reset"
                    :disabled="busy"
                    @click="resetCompany">
                <i class="fas fa-times-circle" style="font-size:17px;margin-bottom:3px;color:#9ca3af"></i>
                <span>Сбросить</span>
            </button>
        </div>
    </div>

    <!-- ── Accounts section ── -->
    <div class="pf-sec" id="accounts">
        <div class="pf-stitle">
            <i class="fas fa-university"></i>Счета (ностробанки)
            <span v-if="currentCompany"
                  style="margin-left:auto;font-weight:500;text-transform:none;letter-spacing:0;font-size:12px;color:#9ca3af">
                {{ currentCompany.name }}&nbsp;·&nbsp;
                <strong style="color:#4f46e5">{{ accounts.length }}</strong>
            </span>
        </div>

        <!-- Нет компании -->
        <div v-if="!user.companyId" class="pf-warn">
            <i class="fas fa-exclamation-triangle"></i>
            Выберите компанию выше.
        </div>

        <!-- Загрузка после смены компании -->
        <div v-else-if="loadingAccounts" class="pf-empty">
            <i class="fas fa-spinner fa-spin" style="font-size:24px;color:#6b7a99"></i>
            <span style="margin-top:8px;display:block">Загрузка...</span>
        </div>

        <!-- Данные есть -->
        <template v-else>

            <!-- Таблица счетов -->
            <div v-if="accounts.length > 0" style="overflow-x:auto">
                <table class="pf-table">
                    <thead><tr>
                        <th>#</th>
                        <th>Название</th>
                        <th>Пул</th>
                        <th>Валюта</th>
                        <th>Тип</th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <tr v-for="acc in accounts" :key="acc.id">
                        <td style="color:#9ca3af;font-size:12px">{{ acc.id }}</td>
                        <td><strong>{{ acc.name }}</strong></td>
                        <td style="color:#9ca3af">{{ acc.pool_name || '—' }}</td>
                        <td>
                            <span v-if="acc.currency" class="tag-cur">{{ acc.currency }}</span>
                            <span v-else style="color:#d1d5db">—</span>
                        </td>
                        <td>
                            <span v-if="acc.is_suspense" class="tag-sus">Suspense</span>
                            <span v-else class="tag-nos">Nostro</span>
                        </td>
                        <td>
                            <button class="pf-btn-del"
                                    :disabled="deletingId === acc.id"
                                    @click="deleteAccount(acc)">
                                <i class="fas"
                                   :class="deletingId === acc.id ? 'fa-spinner fa-spin' : 'fa-trash'"></i>
                            </button>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="pf-empty">
                <i class="fas fa-university"></i>
                Счетов пока нет — добавьте первый ниже.
            </div>

            <!-- Форма добавления -->
            <template v-if="pools.length > 0">
                <div class="pf-add-form">
                    <div class="pf-fg">
                        <label class="pf-lbl">Название *</label>
                        <input class="pf-inp"
                               v-model="newAcc.name"
                               placeholder="NOSTRO_USD_01"
                               @keyup.enter="createAccount">
                    </div>
                    <div class="pf-fg">
                        <label class="pf-lbl">Пул *</label>
                        <select class="pf-sel" v-model="newAcc.pool_id">
                            <option value="">— выбрать —</option>
                            <option v-for="p in pools" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </div>
                    <div class="pf-fg">
                        <label class="pf-lbl">Валюта</label>
                        <select class="pf-sel" v-model="newAcc.currency">
                            <option value="">—</option>
                            <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
                        </select>
                    </div>
                    <div class="pf-fg">
                        <label class="pf-lbl">&nbsp;</label>
                        <button class="pf-btn-add" :disabled="saving" @click="createAccount">
                            <i class="fas" :class="saving ? 'fa-spinner fa-spin' : 'fa-plus'"></i>
                            Добавить
                        </button>
                    </div>
                </div>
                <div style="margin-top:10px">
                    <label style="display:inline-flex;align-items:center;gap:7px;font-size:12.5px;color:#6b7a99;cursor:pointer">
                        <input type="checkbox" v-model="newAcc.is_suspense">
                        Suspense-счёт (для INV)
                    </label>
                </div>
            </template>
            <div v-else class="pf-info" style="margin-top:13px">
                <i class="fas fa-info-circle"></i>
                Сначала создайте пул в разделе «Группы».
            </div>

        </template>
    </div>

</div>

<style>
    .fade-enter-active, .fade-leave-active { transition: opacity .3s; }
    .fade-enter, .fade-leave-to { opacity: 0; }
</style>

<script>
    (function () {

        var _init = <?= json_encode($initData, JSON_UNESCAPED_UNICODE) ?>;

        // axios уже настроен в app.js (CSRF, Content-Type)
        // Но app.js не инициализируется (нет #app), поэтому настраиваем здесь
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) {
            axios.defaults.headers.common['X-CSRF-Token'] = csrfMeta.getAttribute('content');
        }
        axios.defaults.headers.post['Content-Type'] = 'application/x-www-form-urlencoded';
        axios.defaults.transformRequest = [function (data) {
            if (data && typeof data === 'object') {
                return Object.keys(data).map(function (k) {
                    var v = data[k];
                    if (v === null || v === undefined) v = '';
                    if (v === true)  v = 1;
                    if (v === false) v = 0;
                    return encodeURIComponent(k) + '=' + encodeURIComponent(v);
                }).join('&');
            }
            return data;
        }];

        document.addEventListener('DOMContentLoaded', function () {

            new Vue({
                el: '#profile-app',

                data: {
                    user:      _init.user,
                    companies: _init.companies,
                    pools:     _init.pools,
                    accounts:  _init.accounts,
                    routes:    _init.routes,

                    currencies: ['USD', 'EUR', 'RUB', 'GBP', 'CHF', 'CNY', 'JPY'],

                    newAcc: { name: '', pool_id: '', currency: '', is_suspense: false },

                    busy:           false,   // смена компании
                    loadingAccounts:false,   // загрузка счетов после смены компании
                    saving:         false,   // создание счёта
                    deletingId:     null,    // id удаляемого счёта

                    flash: { msg: null, type: 'success' },
                },

                computed: {
                    currentCompany: function () {
                        var self = this;
                        if (!self.user.companyId) return null;
                        return self.companies.find(function (c) { return c.id === self.user.companyId; }) || null;
                    },
                },

                methods: {

                    // ── Компании ─────────────────────────────────────────

                    selectCompany: function (comp) {
                        var self = this;
                        if (self.busy || self.user.companyId === comp.id) return;
                        self.busy = true;

                        axios.post(self.routes.selectCompany, { id: comp.id })
                            .then(function (r) {
                                if (r.data.success) {
                                    self.user.companyId = comp.id;
                                    self.showFlash('success', r.data.message);
                                    self.reloadCompanyData();
                                } else {
                                    self.showFlash('error', r.data.message || 'Ошибка');
                                }
                            })
                            .catch(function () { self.showFlash('error', 'Ошибка сети'); })
                            .finally(function () { self.busy = false; });
                    },

                    resetCompany: function () {
                        var self = this;
                        if (self.busy) return;
                        self.busy = true;

                        axios.post(self.routes.resetCompany)
                            .then(function (r) {
                                if (r.data.success) {
                                    self.user.companyId = null;
                                    self.pools    = [];
                                    self.accounts = [];
                                    self.showFlash('success', r.data.message);
                                } else {
                                    self.showFlash('error', r.data.message || 'Ошибка');
                                }
                            })
                            .catch(function () { self.showFlash('error', 'Ошибка сети'); })
                            .finally(function () { self.busy = false; });
                    },

                    reloadCompanyData: function () {
                        var self = this;
                        self.loadingAccounts = true;
                        self.pools    = [];
                        self.accounts = [];

                        Promise.all([
                            axios.get(self.routes.getPools),
                            axios.get(self.routes.getAccounts),
                        ]).then(function (res) {
                            self.pools    = (res[0].data && res[0].data.data) ? res[0].data.data : [];
                            self.accounts = (res[1].data && res[1].data.data) ? res[1].data.data : [];
                        }).catch(function () {
                            self.showFlash('error', 'Не удалось загрузить данные компании');
                        }).finally(function () {
                            self.loadingAccounts = false;
                        });
                    },

                    // ── Счета ────────────────────────────────────────────

                    createAccount: function () {
                        var self = this;
                        if (!self.newAcc.name.trim()) {
                            self.showFlash('error', 'Укажите название счёта'); return;
                        }
                        if (!self.newAcc.pool_id) {
                            self.showFlash('error', 'Выберите пул'); return;
                        }
                        self.saving = true;

                        axios.post(self.routes.createAccount, {
                            name:        self.newAcc.name.trim(),
                            pool_id:     self.newAcc.pool_id,
                            currency:    self.newAcc.currency,
                            is_suspense: self.newAcc.is_suspense ? '1' : '0',
                        }).then(function (r) {
                            if (r.data.success) {
                                self.accounts.push(r.data.data);
                                self.newAcc = { name: '', pool_id: '', currency: '', is_suspense: false };
                                self.showFlash('success', r.data.message);
                            } else {
                                self.showFlash('error', r.data.message || 'Ошибка');
                            }
                        })
                            .catch(function () { self.showFlash('error', 'Ошибка сети'); })
                            .finally(function () { self.saving = false; });
                    },

                    deleteAccount: function (acc) {
                        var self = this;
                        if (!confirm('Удалить счёт «' + acc.name + '»?')) return;
                        self.deletingId = acc.id;

                        axios.post(self.routes.deleteAccount, { id: acc.id })
                            .then(function (r) {
                                if (r.data.success) {
                                    self.accounts = self.accounts.filter(function (a) { return a.id !== acc.id; });
                                    self.showFlash('success', r.data.message);
                                } else {
                                    self.showFlash('error', r.data.message);
                                }
                            })
                            .catch(function () { self.showFlash('error', 'Ошибка сети'); })
                            .finally(function () { self.deletingId = null; });
                    },

                    // ── Утилиты ──────────────────────────────────────────

                    showFlash: function (type, msg) {
                        var self = this;
                        self.flash.type = type;
                        self.flash.msg  = msg;
                        clearTimeout(self._flashTimer);
                        self._flashTimer = setTimeout(function () {
                            self.flash.msg = null;
                        }, type === 'success' ? 3000 : 5000);
                    },
                },
            });

        });

    })();
</script>