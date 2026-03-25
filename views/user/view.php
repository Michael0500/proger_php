<?php

use yii\helpers\Html;
use yii\helpers\Url;

/** @var yii\web\View $this */
/** @var app\models\User     $model */
/** @var app\models\Company[] $companies */

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
        'routes' => [
                'selectCompany' => Url::to(['/user/select-company']),
                'resetCompany'  => Url::to(['/user/reset-company']),
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

    /* ── Buttons ── */
    .pf-btn-add {
        background: #4f46e5; color: #fff; border: none; border-radius: 7px;
        padding: 8px 15px; font-size: 13px; font-weight: 600;
        cursor: pointer; transition: background .15s;
        display: inline-flex; align-items: center; gap: 5px; white-space: nowrap;
    }
    .pf-btn-add:hover { background: #4338ca; }
    .pf-btn-add:disabled { opacity: .5; cursor: not-allowed; }
    /* ── States ── */
    .pf-warn  { background: #fef3c7; border: 1px solid #fde68a; border-radius: 9px; padding: 11px 15px; color: #92400e; font-size: 13px; display: flex; align-items: center; gap: 8px; }
    .pf-info  { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 9px; padding: 11px 15px; color: #1e40af; font-size: 13px; display: flex; align-items: center; gap: 8px; }
    .pf-empty { text-align: center; padding: 28px; color: #9ca3af; font-size: 13px; }
    .pf-empty i { font-size: 28px; margin-bottom: 8px; display: block; color: #d1d5db; }

    @media(max-width:640px) {
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

    <!-- Ссылка на страницу счетов -->
    <div v-if="user.companyId" class="pf-sec">
        <div class="pf-stitle"><i class="fas fa-university"></i>Счета</div>
        <a href="<?= \yii\helpers\Url::to(['/accounts']) ?>" class="pf-btn-add" style="text-decoration:none;display:inline-flex">
            <i class="fas fa-external-link-alt me-1"></i> Перейти к управлению счетами
        </a>
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

        document.addEventListener('DOMContentLoaded', function () {
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

            new Vue({
                el: '#profile-app',

                data: {
                    user:      _init.user,
                    companies: _init.companies,
                    routes:    _init.routes,

                    busy:  false,
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
                                    self.showFlash('success', r.data.message);
                                } else {
                                    self.showFlash('error', r.data.message || 'Ошибка');
                                }
                            })
                            .catch(function () { self.showFlash('error', 'Ошибка сети'); })
                            .finally(function () { self.busy = false; });
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