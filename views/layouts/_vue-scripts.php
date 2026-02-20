<?php
/** @var yii\web\View $this */
use yii\helpers\Url;
?>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        new Vue({
            el: '#app',
            data: {
                isSidebarCollapsed: false,
                loadingGroups: false,
                loadingAccounts: false,
                groups: [],
                accounts: [],
                selectedGroup: null,
                selectedPool: null,

                // Группа
                newGroup: { name: '', description: '' },
                editingGroup: { id: null, name: '', description: '' },

                // Пул
                newPool: { group_id: null, name: '', description: '', is_active: true },
                editingPool: {
                    id: null, name: '', description: '', is_active: true,
                    filter_criteria: { currency: '', account_type: '', bank_code: '', country: '', is_suspense: false }
                },

                // Запись выверки (NostroEntry)
                editingEntry: {
                    id: null,
                    account_id: null,
                    ls: 'L',
                    dc: 'Debit',
                    amount: '',
                    currency: '',
                    value_date: '',
                    post_date: '',
                    instruction_id: '',
                    end_to_end_id: '',
                    transaction_id: '',
                    message_id: '',
                    comment: ''
                },

                // Inline-редактирование комментария
                editingCommentId: null,
                editingCommentValue: ''
            },

            computed: {
                isAccountPage: function () {
                    return this.selectedPool !== null;
                }
            },

            mounted: function () {
                this.loadGroups();
            },

            methods: {

                // ── Сайдбар ──────────────────────────────────────────────
                toggleSidebar: function () {
                    this.isSidebarCollapsed = !this.isSidebarCollapsed;
                },

                // ── Группы ───────────────────────────────────────────────
                loadGroups: function () {
                    var self = this;
                    self.loadingGroups = true;
                    axios.get('<?= Url::to(['/account-group/get-groups']) ?>')
                        .then(function (response) {
                            if (response.data.success) {
                                self.groups = response.data.data.map(function (group) {
                                    return Object.assign({}, group, { expanded: false });
                                });
                            } else {
                                Swal.fire('Ошибка', response.data.message || 'Не удалось загрузить группы', 'error');
                            }
                        })
                        .catch(function () {
                            Swal.fire('Ошибка', 'Не удалось загрузить группы', 'error');
                        })
                        .finally(function () {
                            self.loadingGroups = false;
                        });
                },

                toggleGroup: function (group) {
                    var index = this.groups.findIndex(function (g) { return g.id === group.id; });
                    if (this.selectedGroup && this.selectedGroup.id === group.id) {
                        if (index !== -1) this.groups[index].expanded = !this.groups[index].expanded;
                    } else {
                        this.selectedGroup = group;
                        this.selectedPool = null;
                        this.accounts = [];
                        if (index !== -1) this.groups[index].expanded = true;
                    }
                },

                showAddGroupModal: function () {
                    this.newGroup = { name: '', description: '' };
                    var modal = document.getElementById('addGroupModal');
                    if (modal) new bootstrap.Modal(modal).show();
                },

                closeAddGroupModal: function () {
                    var modal = document.getElementById('addGroupModal');
                    if (modal) { var m = bootstrap.Modal.getInstance(modal); if (m) m.hide(); }
                },

                createGroup: function () {
                    var self = this;
                    if (!self.newGroup.name || !self.newGroup.name.trim()) {
                        Swal.fire('Ошибка', 'Название группы обязательно', 'error');
                        return;
                    }
                    axios.post('<?= Url::to(['/account-group/create']) ?>', self.newGroup)
                        .then(function (response) {
                            if (response.data.success) {
                                Swal.fire('Успех', response.data.message, 'success');
                                self.closeAddGroupModal();
                                self.loadGroups();
                            } else {
                                Swal.fire('Ошибка', response.data.message || 'Не удалось создать группу', 'error');
                            }
                        })
                        .catch(function () { Swal.fire('Ошибка', 'Не удалось создать группу', 'error'); });
                },

                editGroup: function (group) {
                    this.editingGroup = { id: group.id, name: group.name, description: group.description || '' };
                    var modal = document.getElementById('editGroupModal');
                    if (modal) new bootstrap.Modal(modal).show();
                },

                closeEditGroupModal: function () {
                    var modal = document.getElementById('editGroupModal');
                    if (modal) { var m = bootstrap.Modal.getInstance(modal); if (m) m.hide(); }
                },

                updateGroup: function () {
                    var self = this;
                    if (!self.editingGroup.name || !self.editingGroup.name.trim()) {
                        Swal.fire('Ошибка', 'Название группы обязательно', 'error');
                        return;
                    }
                    axios.post('<?= Url::to(['/account-group/update']) ?>', self.editingGroup)
                        .then(function (response) {
                            if (response.data.success) {
                                Swal.fire('Успех', response.data.message, 'success');
                                self.closeEditGroupModal();
                                self.loadGroups();
                            } else {
                                Swal.fire('Ошибка', response.data.message || 'Не удалось обновить группу', 'error');
                            }
                        })
                        .catch(function () { Swal.fire('Ошибка', 'Не удалось обновить группу', 'error'); });
                },

                deleteGroup: function (group) {
                    var self = this;
                    Swal.fire({
                        title: 'Вы уверены?',
                        text: 'Удалить группу "' + group.name + '"? Все связанные пулы и счета будут отвязаны.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#d33',
                        cancelButtonColor: '#3085d6',
                        confirmButtonText: 'Да, удалить',
                        cancelButtonText: 'Отмена'
                    }).then(function (result) {
                        if (result.isConfirmed) {
                            axios.post('<?= Url::to(['/account-group/delete']) ?>', { id: group.id })
                                .then(function (response) {
                                    if (response.data.success) {
                                        Swal.fire('Удалено', response.data.message, 'success');
                                        self.loadGroups();
                                        if (self.selectedGroup && self.selectedGroup.id === group.id) {
                                            self.selectedGroup = null;
                                            self.selectedPool = null;
                                            self.accounts = [];
                                        }
                                    } else {
                                        Swal.fire('Ошибка', response.data.message, 'error');
                                    }
                                })
                                .catch(function () { Swal.fire('Ошибка', 'Не удалось удалить группу', 'error'); });
                        }
                    });
                },

                // ── Пулы ─────────────────────────────────────────────────
                selectPool: function (pool, group) {
                    this.selectedPool = pool;
                    this.selectedGroup = group;
                    this.loadAccounts(pool.id);
                },

                loadAccounts: function (poolId) {
                    var self = this;
                    self.loadingAccounts = true;
                    self.accounts = [];
                    axios.get('<?= Url::to(['/account-pool/get-accounts']) ?>', { params: { id: poolId } })
                        .then(function (response) {
                            if (response.data.success) {
                                self.accounts = response.data.data;
                            } else {
                                Swal.fire('Ошибка', response.data.message || 'Не удалось загрузить данные', 'error');
                            }
                        })
                        .catch(function () { Swal.fire('Ошибка', 'Не удалось загрузить данные', 'error'); })
                        .finally(function () { self.loadingAccounts = false; });
                },

                refreshAccounts: function () {
                    if (this.selectedPool) this.loadAccounts(this.selectedPool.id);
                },

                showAddPoolModal: function (group) {
                    this.newPool = { group_id: group.id, name: '', description: '', is_active: true };
                    var modal = document.getElementById('addPoolModal');
                    if (modal) new bootstrap.Modal(modal).show();
                },

                closeAddPoolModal: function () {
                    var modal = document.getElementById('addPoolModal');
                    if (modal) { var m = bootstrap.Modal.getInstance(modal); if (m) m.hide(); }
                },

                createPool: function () {
                    var self = this;
                    if (!self.newPool.name || !self.newPool.name.trim()) {
                        Swal.fire('Ошибка', 'Название пула обязательно', 'error');
                        return;
                    }
                    axios.post('<?= Url::to(['/account-pool/create']) ?>', self.newPool)
                        .then(function (response) {
                            if (response.data.success) {
                                Swal.fire('Успех', response.data.message, 'success');
                                self.closeAddPoolModal();
                                self.loadGroups();
                            } else {
                                Swal.fire('Ошибка', response.data.message || 'Не удалось создать пул', 'error');
                            }
                        })
                        .catch(function () { Swal.fire('Ошибка', 'Не удалось создать пул', 'error'); });
                },

                editPool: function (pool) {
                    this.editingPool = {
                        id: pool.id, name: pool.name,
                        description: pool.description || '',
                        is_active: pool.is_active,
                        filter_criteria: pool.filter_criteria ? JSON.parse(pool.filter_criteria) : {
                            currency: '', account_type: '', bank_code: '', country: '', is_suspense: false
                        }
                    };
                    var modal = document.getElementById('editPoolModal');
                    if (modal) new bootstrap.Modal(modal).show();
                },

                closeEditPoolModal: function () {
                    var modal = document.getElementById('editPoolModal');
                    if (modal) { var m = bootstrap.Modal.getInstance(modal); if (m) m.hide(); }
                },

                updatePool: function () {
                    var self = this;
                    if (!self.editingPool.name || !self.editingPool.name.trim()) {
                        Swal.fire('Ошибка', 'Название пула обязательно', 'error');
                        return;
                    }
                    axios.post('<?= Url::to(['/account-pool/update']) ?>', self.editingPool)
                        .then(function (response) {
                            if (response.data.success) {
                                Swal.fire('Успех', response.data.message, 'success');
                                self.closeEditPoolModal();
                                self.closeConfigurePoolModal();
                                self.loadGroups();
                                if (self.selectedPool && self.selectedPool.id === self.editingPool.id) {
                                    self.loadAccounts(self.selectedPool.id);
                                }
                            } else {
                                Swal.fire('Ошибка', response.data.message || 'Не удалось обновить пул', 'error');
                            }
                        })
                        .catch(function () { Swal.fire('Ошибка', 'Не удалось обновить пул', 'error'); });
                },

                configurePool: function (pool) {
                    this.editingPool = {
                        id: pool.id, name: pool.name,
                        description: pool.description || '',
                        is_active: pool.is_active,
                        filter_criteria: pool.filter_criteria ? JSON.parse(pool.filter_criteria) : {
                            currency: '', account_type: '', bank_code: '', country: '', is_suspense: false
                        }
                    };
                    var modal = document.getElementById('configurePoolModal');
                    if (modal) new bootstrap.Modal(modal).show();
                },

                closeConfigurePoolModal: function () {
                    var modal = document.getElementById('configurePoolModal');
                    if (modal) { var m = bootstrap.Modal.getInstance(modal); if (m) m.hide(); }
                },

                deletePool: function (pool) {
                    var self = this;
                    Swal.fire({
                        title: 'Вы уверены?',
                        text: 'Удалить пул "' + pool.name + '"?',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#d33',
                        cancelButtonColor: '#3085d6',
                        confirmButtonText: 'Да, удалить',
                        cancelButtonText: 'Отмена'
                    }).then(function (result) {
                        if (result.isConfirmed) {
                            axios.post('<?= Url::to(['/account-pool/delete']) ?>', { id: pool.id })
                                .then(function (response) {
                                    if (response.data.success) {
                                        Swal.fire('Удалено', response.data.message, 'success');
                                        self.loadGroups();
                                        if (self.selectedPool && self.selectedPool.id === pool.id) {
                                            self.selectedPool = null;
                                            self.accounts = [];
                                        }
                                    } else {
                                        Swal.fire('Ошибка', response.data.message, 'error');
                                    }
                                })
                                .catch(function () { Swal.fire('Ошибка', 'Не удалось удалить пул', 'error'); });
                        }
                    });
                },

                // ── Записи выверки (NostroEntry) ─────────────────────────
                showAddEntryModal: function (account) {
                    this.editingEntry = {
                        id: null,
                        account_id: account ? account.id : null,
                        ls: 'L',
                        dc: 'Debit',
                        amount: '',
                        currency: account && account.currency ? account.currency : '',
                        value_date: '',
                        post_date: '',
                        instruction_id: '',
                        end_to_end_id: '',
                        transaction_id: '',
                        message_id: '',
                        comment: ''
                    };
                    var modal = document.getElementById('entryModal');
                    if (modal) new bootstrap.Modal(modal).show();
                },

                editEntry: function (entry, account) {
                    this.editingEntry = {
                        id: entry.id,
                        account_id: account.id,
                        ls: entry.ls,
                        dc: entry.dc,
                        amount: entry.amount_raw,
                        currency: entry.currency,
                        value_date: entry.value_date || '',
                        post_date: entry.post_date || '',
                        instruction_id: entry.instruction_id || '',
                        end_to_end_id: entry.end_to_end_id || '',
                        transaction_id: entry.transaction_id || '',
                        message_id: entry.message_id || '',
                        comment: entry.comment || ''
                    };
                    var modal = document.getElementById('entryModal');
                    if (modal) new bootstrap.Modal(modal).show();
                },

                closeEntryModal: function () {
                    var modal = document.getElementById('entryModal');
                    if (modal) { var m = bootstrap.Modal.getInstance(modal); if (m) m.hide(); }
                },

                saveEntry: function () {
                    var self = this;
                    if (!self.editingEntry.account_id) {
                        Swal.fire('Ошибка', 'Выберите Ностро банк', 'error'); return;
                    }
                    if (!self.editingEntry.amount || isNaN(self.editingEntry.amount)) {
                        Swal.fire('Ошибка', 'Укажите корректную сумму', 'error'); return;
                    }
                    if (!self.editingEntry.currency) {
                        Swal.fire('Ошибка', 'Укажите валюту', 'error'); return;
                    }
                    var url = self.editingEntry.id
                        ? '<?= Url::to(['/nostro-entry/update']) ?>'
                        : '<?= Url::to(['/nostro-entry/create']) ?>';
                    axios.post(url, self.editingEntry)
                        .then(function (response) {
                            if (response.data.success) {
                                Swal.fire('Успех', response.data.message, 'success');
                                self.closeEntryModal();
                                self.refreshAccounts();
                            } else {
                                var errText = response.data.errors
                                    ? Object.values(response.data.errors).join('\n')
                                    : (response.data.message || 'Ошибка');
                                Swal.fire('Ошибка', errText, 'error');
                            }
                        })
                        .catch(function () { Swal.fire('Ошибка', 'Не удалось сохранить запись', 'error'); });
                },

                deleteEntry: function (entry, account) {
                    var self = this;
                    Swal.fire({
                        title: 'Удалить запись?',
                        text: (entry.match_id || '(нет Match ID)') + ' | ' + entry.amount + ' ' + entry.currency,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#d33',
                        cancelButtonColor: '#3085d6',
                        confirmButtonText: 'Удалить',
                        cancelButtonText: 'Отмена'
                    }).then(function (result) {
                        if (result.isConfirmed) {
                            axios.post('<?= Url::to(['/nostro-entry/delete']) ?>', { id: entry.id })
                                .then(function (response) {
                                    if (response.data.success) {
                                        Swal.fire('Удалено', response.data.message, 'success');
                                        self.refreshAccounts();
                                    } else {
                                        Swal.fire('Ошибка', response.data.message, 'error');
                                    }
                                })
                                .catch(function () { Swal.fire('Ошибка', 'Не удалось удалить запись', 'error'); });
                        }
                    });
                },

                // ── Inline-редактирование комментария ────────────────────
                startEditComment: function (entry) {
                    this.editingCommentId = entry.id;
                    this.editingCommentValue = entry.comment || '';
                },

                cancelEditComment: function () {
                    this.editingCommentId = null;
                    this.editingCommentValue = '';
                },

                saveComment: function (entry) {
                    var self = this;
                    axios.post('<?= Url::to(['/nostro-entry/update-comment']) ?>', {
                        id: entry.id,
                        comment: self.editingCommentValue
                    })
                        .then(function (response) {
                            if (response.data.success) {
                                entry.comment = response.data.comment;
                                self.cancelEditComment();
                            } else {
                                Swal.fire('Ошибка', 'Не удалось сохранить комментарий', 'error');
                            }
                        })
                        .catch(function () { Swal.fire('Ошибка', 'Ошибка при сохранении', 'error'); });
                },

                // ── Утилиты ──────────────────────────────────────────────
                formatDate: function (dateString) {
                    var date = new Date(dateString);
                    return date.toLocaleDateString('ru-RU', {
                        year: 'numeric', month: 'short', day: 'numeric',
                        hour: '2-digit', minute: '2-digit'
                    });
                }
            }
        });
    });
</script>