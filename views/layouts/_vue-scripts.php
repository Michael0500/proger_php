<?php
/** @var yii\web\View $this */
use yii\helpers\Url;
?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
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
            newGroup: {
            name: '',
                description: ''
        },
            editingGroup: {
                id: null,
                    name: '',
                    description: ''
            },
            newPool: {
                group_id: null,
                    name: '',
                    description: '',
                    is_active: true
            },
            editingPool: {
                id: null,
                    name: '',
                    description: '',
                    is_active: true,
                    filter_criteria: {
                    currency: '',
                        account_type: '',
                        bank_code: '',
                        country: '',
                        is_suspense: false
                }
            }
        },
        computed: {
            isAccountPage() {
                return this.selectedPool !== null;
            }
        },
        mounted() {
            this.loadGroups();
        },
        methods: {
            // Методы закрытия модальных окон
            closeAddGroupModal() {
                const modal = document.getElementById('addGroupModal');
                if (modal) {
                    const bsModal = bootstrap.Modal.getInstance(modal);
                    if (bsModal) bsModal.hide();
                }
            },

            closeEditGroupModal() {
                const modal = document.getElementById('editGroupModal');
                if (modal) {
                    const bsModal = bootstrap.Modal.getInstance(modal);
                    if (bsModal) bsModal.hide();
                }
            },

            closeAddPoolModal() {
                const modal = document.getElementById('addPoolModal');
                if (modal) {
                    const bsModal = bootstrap.Modal.getInstance(modal);
                    if (bsModal) bsModal.hide();
                }
            },

            closeEditPoolModal() {
                const modal = document.getElementById('editPoolModal');
                if (modal) {
                    const bsModal = bootstrap.Modal.getInstance(modal);
                    if (bsModal) bsModal.hide();
                }
            },

            closeConfigurePoolModal() {
                const modal = document.getElementById('configurePoolModal');
                if (modal) {
                    const bsModal = bootstrap.Modal.getInstance(modal);
                    if (bsModal) bsModal.hide();
                }
            },

            toggleSidebar() {
                this.isSidebarCollapsed = !this.isSidebarCollapsed;
            },

            loadGroups() {
                this.loadingGroups = true;
                axios.get('<?= Url::to(['/account-group/get-groups']) ?>')
                    .then(response => {
                        if (response.data.success) {
                            this.groups = response.data.data.map(group => ({
                                ...group,
                                expanded: false
                            }));
                        } else {
                            Swal.fire('Ошибка', response.data.message || 'Не удалось загрузить группы', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error loading groups:', error);
                        Swal.fire('Ошибка', 'Не удалось загрузить группы', 'error');
                    })
                    .finally(() => {
                        this.loadingGroups = false;
                    });
            },

            toggleGroup(group) {
                if (this.selectedGroup && this.selectedGroup.id === group.id) {
                    const index = this.groups.findIndex(g => g.id === group.id);
                    if (index !== -1) {
                        this.groups[index].expanded = !this.groups[index].expanded;
                    }
                } else {
                    this.selectedGroup = group;
                    this.selectedPool = null;
                    this.accounts = [];

                    const index = this.groups.findIndex(g => g.id === group.id);
                    if (index !== -1) {
                        this.groups[index].expanded = true;
                    }
                }
            },

            selectPool(pool, group) {
                this.selectedPool = pool;
                this.selectedGroup = group;
                this.loadAccounts(pool.id);
            },

            loadAccounts(poolId) {
                this.loadingAccounts = true;
                this.accounts = [];
                axios.get('<?= Url::to(['/account-pool/get-accounts']) ?>', { params: { id: poolId } })
                    .then(response => {
                        if (response.data.success) {
                            this.accounts = response.data.data;
                        } else {
                            Swal.fire('Ошибка', response.data.message || 'Не удалось загрузить счета', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error loading accounts:', error);
                        Swal.fire('Ошибка', 'Не удалось загрузить счета', 'error');
                    })
                    .finally(() => {
                        this.loadingAccounts = false;
                    });
            },

            refreshAccounts() {
                if (this.selectedPool) {
                    this.loadAccounts(this.selectedPool.id);
                }
            },

            formatDate(dateString) {
                const date = new Date(dateString);
                return date.toLocaleDateString('ru-RU', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            },

            showAddGroupModal() {
                console.log('showAddGroupModal called');
                this.newGroup = { name: '', description: '' };
                const modal = document.getElementById('addGroupModal');
                if (modal) {
                    const bsModal = new bootstrap.Modal(modal);
                    bsModal.show();
                    console.log('Add group modal shown');
                } else {
                    console.error('Add group modal element not found');
                }
            },

            createGroup() {
                console.log('createGroup method CALLED!');
                console.log('Group data:', this.newGroup);

                if (!this.newGroup.name || !this.newGroup.name.trim()) {
                    console.log('Validation failed');
                    Swal.fire('Ошибка', 'Название группы обязательно', 'error');
                    return;
                }

                console.log('About to send POST request...');

                axios.post('<?= Url::to(['/account-group/create']) ?>', this.newGroup)
                    .then(response => {
                        console.log('Response received:', response);
                        if (response.data.success) {
                            Swal.fire('Успех', response.data.message, 'success');
                            this.closeAddGroupModal();
                            this.loadGroups();
                        } else {
                            Swal.fire('Ошибка', response.data.message || 'Не удалось создать группу', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error creating group:', error);
                        if (error.response && error.response.data) {
                            Swal.fire('Ошибка', error.response.data.message || 'Не удалось создать группу', 'error');
                        } else {
                            Swal.fire('Ошибка', 'Не удалось создать группу', 'error');
                        }
                    });
            },

            editGroup(group) {
                console.log('editGroup called with:', group);
                this.editingGroup = {
                    id: group.id,
                    name: group.name,
                    description: group.description || ''
                };
                const modal = document.getElementById('editGroupModal');
                if (modal) {
                    const bsModal = new bootstrap.Modal(modal);
                    bsModal.show();
                    console.log('Edit group modal shown');
                }
            },

            updateGroup() {
                console.log('updateGroup method CALLED!');
                console.log('Editing group data:', this.editingGroup);

                if (!this.editingGroup.name || !this.editingGroup.name.trim()) {
                    Swal.fire('Ошибка', 'Название группы обязательно', 'error');
                    return;
                }

                axios.post('<?= Url::to(['/account-group/update']) ?>', this.editingGroup)
                    .then(response => {
                        console.log('Update response:', response);
                        if (response.data.success) {
                            Swal.fire('Успех', response.data.message, 'success');
                            this.closeEditGroupModal();
                            this.loadGroups();
                        } else {
                            Swal.fire('Ошибка', response.data.message || 'Не удалось обновить группу', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error updating group:', error);
                        if (error.response && error.response.data) {
                            Swal.fire('Ошибка', error.response.data.message || 'Не удалось обновить группу', 'error');
                        } else {
                            Swal.fire('Ошибка', 'Не удалось обновить группу', 'error');
                        }
                    });
            },

            deleteGroup(group) {
                Swal.fire({
                    title: 'Вы уверены?',
                    text: `Удалить группу "${group.name}"? Все связанные пулы и счета будут отвязаны.`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Да, удалить',
                    cancelButtonText: 'Отмена'
                }).then((result) => {
                    if (result.isConfirmed) {
                        axios.post('<?= Url::to(['/account-group/delete']) ?>', { id: group.id })
                            .then(response => {
                                if (response.data.success) {
                                    Swal.fire('Удалено', response.data.message, 'success');
                                    this.loadGroups();
                                    if (this.selectedGroup && this.selectedGroup.id === group.id) {
                                        this.selectedGroup = null;
                                        this.selectedPool = null;
                                        this.accounts = [];
                                    }
                                } else {
                                    Swal.fire('Ошибка', response.data.message || 'Не удалось удалить группу', 'error');
                                }
                            })
                            .catch(error => {
                                console.error('Error deleting group:', error);
                                Swal.fire('Ошибка', 'Не удалось удалить группу', 'error');
                            });
                    }
                });
            },

            showAddPoolModal(group) {
                console.log('showAddPoolModal called for group:', group);
                this.newPool = {
                    group_id: group.id,
                    name: '',
                    description: '',
                    is_active: true
                };
                const modal = document.getElementById('addPoolModal');
                if (modal) {
                    const bsModal = new bootstrap.Modal(modal);
                    bsModal.show();
                }
            },

            createPool() {
                console.log('createPool method CALLED!');
                console.log('Pool data:', this.newPool);

                if (!this.newPool.name || !this.newPool.name.trim()) {
                    Swal.fire('Ошибка', 'Название пула обязательно', 'error');
                    return;
                }

                axios.post('<?= Url::to(['/account-pool/create']) ?>', this.newPool)
                    .then(response => {
                        console.log('Create pool response:', response);
                        if (response.data.success) {
                            Swal.fire('Успех', response.data.message, 'success');
                            this.closeAddPoolModal();
                            this.loadGroups();
                        } else {
                            Swal.fire('Ошибка', response.data.message || 'Не удалось создать пул', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error creating pool:', error);
                        if (error.response && error.response.data) {
                            Swal.fire('Ошибка', error.response.data.message || 'Не удалось создать пул', 'error');
                        } else {
                            Swal.fire('Ошибка', 'Не удалось создать пул', 'error');
                        }
                    });
            },

            editPool(pool) {
                console.log('editPool called with:', pool);
                this.editingPool = {
                    id: pool.id,
                    name: pool.name,
                    description: pool.description || '',
                    is_active: pool.is_active,
                    filter_criteria: pool.filter_criteria ? JSON.parse(pool.filter_criteria) : {
                        currency: '',
                        account_type: '',
                        bank_code: '',
                        country: '',
                        is_suspense: false
                    }
                };
                const modal = document.getElementById('editPoolModal');
                if (modal) {
                    const bsModal = new bootstrap.Modal(modal);
                    bsModal.show();
                }
            },

            updatePool() {
                console.log('updatePool method CALLED!');
                console.log('Editing pool data:', this.editingPool);

                if (!this.editingPool.name || !this.editingPool.name.trim()) {
                    Swal.fire('Ошибка', 'Название пула обязательно', 'error');
                    return;
                }

                axios.post('<?= Url::to(['/account-pool/update']) ?>', this.editingPool)
                    .then(response => {
                        console.log('Update pool response:', response);
                        if (response.data.success) {
                            Swal.fire('Успех', response.data.message, 'success');
                            this.closeEditPoolModal();
                            this.closeConfigurePoolModal();
                            this.loadGroups();
                            if (this.selectedPool && this.selectedPool.id === this.editingPool.id) {
                                this.loadAccounts(this.selectedPool.id);
                            }
                        } else {
                            Swal.fire('Ошибка', response.data.message || 'Не удалось обновить пул', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error updating pool:', error);
                        if (error.response && error.response.data) {
                            Swal.fire('Ошибка', error.response.data.message || 'Не удалось обновить пул', 'error');
                        } else {
                            Swal.fire('Ошибка', 'Не удалось обновить пул', 'error');
                        }
                    });
            },

            configurePool(pool) {
                console.log('configurePool called with:', pool);
                this.editingPool = {
                    id: pool.id,
                    name: pool.name,
                    description: pool.description || '',
                    is_active: pool.is_active,
                    filter_criteria: pool.filter_criteria ? JSON.parse(pool.filter_criteria) : {
                        currency: '',
                        account_type: '',
                        bank_code: '',
                        country: '',
                        is_suspense: false
                    }
                };
                const modal = document.getElementById('configurePoolModal');
                if (modal) {
                    const bsModal = new bootstrap.Modal(modal);
                    bsModal.show();
                }
            },

            deletePool(pool) {
                Swal.fire({
                    title: 'Вы уверены?',
                    text: `Удалить пул "${pool.name}"? Все связанные счета будут отвязаны.`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Да, удалить',
                    cancelButtonText: 'Отмена'
                }).then((result) => {
                    if (result.isConfirmed) {
                        axios.post('<?= Url::to(['/account-pool/delete']) ?>', { id: pool.id })
                            .then(response => {
                                if (response.data.success) {
                                    Swal.fire('Удалено', response.data.message, 'success');
                                    this.loadGroups();
                                    if (this.selectedPool && this.selectedPool.id === pool.id) {
                                        this.selectedPool = null;
                                        this.accounts = [];
                                    }
                                } else {
                                    Swal.fire('Ошибка', response.data.message || 'Не удалось удалить пул', 'error');
                                }
                            })
                            .catch(error => {
                                console.error('Error deleting pool:', error);
                                Swal.fire('Ошибка', 'Не удалось удалить пул', 'error');
                            });
                    }
                });
            }
        }
    });
    });
</script>