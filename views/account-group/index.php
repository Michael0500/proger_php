<?php
/** @var yii\web\View $this */
use yii\helpers\Url;

$this->title = 'Группы ностробанков';
?>
<?php $this->registerJsFile('https://cdn.jsdelivr.net/npm/vue@2.6.14/dist/vue.js', ['position' => $this->POS_HEAD]); ?>
<?php $this->registerJsFile('https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js', ['position' => $this->POS_HEAD]); ?>
<?php $this->registerJsFile('https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js', ['position' => $this->POS_HEAD]); ?>
<?php $this->registerCssFile('https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css'); ?>

    <style>
        .sidebar {
            position: fixed;
            top: 56px;
            left: 0;
            bottom: 0;
            width: 300px;
            background-color: #f8f9fa;
            border-right: 1px solid #dee2e6;
            overflow-y: auto;
            transition: all 0.3s ease;
            z-index: 1000;
        }
        .sidebar.collapsed {
            width: 70px;
        }
        .main-content {
            margin-left: 300px;
            transition: margin-left 0.3s ease;
            padding-top: 20px;
        }
        .main-content.sidebar-collapsed {
            margin-left: 70px;
        }
        .sidebar-toggle {
            position: absolute;
            top: 10px;
            right: -15px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .sidebar-toggle i {
            transition: transform 0.3s ease;
        }
        .sidebar.collapsed .sidebar-toggle i {
            transform: rotate(180deg);
        }
        .sidebar-item {
            padding: 12px 15px;
            cursor: pointer;
            transition: background-color 0.2s;
            position: relative;
            border-left: 3px solid transparent;
        }
        .sidebar-item:hover {
            background-color: #e9ecef;
        }
        .sidebar-item.active {
            background-color: #0d6efd;
            color: white;
            border-left-color: #0d6efd;
        }
        .sidebar-item.active:hover {
            background-color: #0b5ed7;
        }
        .pool-item {
            padding-left: 30px;
            border-left: 3px solid transparent;
        }
        .pool-item.active {
            border-left-color: #198754;
        }
        .action-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            opacity: 0;
            transition: opacity 0.2s;
        }
        .sidebar-item:hover .action-btn {
            opacity: 1;
        }
        .badge-suspense {
            background-color: #ffc107;
            color: #000;
        }
        .table-container {
            background-color: white;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
        }
        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 200px;
        }
    </style>

    <div id="app" class="d-flex">
        <!-- Sidebar -->
        <div class="sidebar" :class="{ 'collapsed': isSidebarCollapsed }">
            <div class="sidebar-toggle" @click="toggleSidebar">
                <i class="fas fa-chevron-left"></i>
            </div>

            <div class="p-3">
                <h6 class="mb-3" v-if="!isSidebarCollapsed">
                    <i class="fas fa-layer-group me-2"></i>Группы ностробанков
                </h6>
                <hr v-if="!isSidebarCollapsed">

                <div v-if="loadingGroups" class="text-center py-3">
                    <div class="spinner-border spinner-border-sm" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>

                <div v-for="group in groups" :key="group.id" class="mb-2">
                    <!-- Group Header -->
                    <div class="sidebar-item"
                         :class="{ 'active': selectedGroup && selectedGroup.id === group.id }"
                         @click="selectGroup(group)">
                        <div class="d-flex justify-content-between align-items-center">
                            <span v-if="!isSidebarCollapsed">{{ group.name }}</span>
                            <i v-if="!isSidebarCollapsed" class="fas fa-folder me-2"></i>
                            <i v-else class="fas fa-folder"></i>
                        </div>
                        <div class="dropdown" v-if="!isSidebarCollapsed">
                            <button class="btn btn-sm btn-outline-secondary action-btn dropdown-toggle"
                                    type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end">
                                <a class="dropdown-item" href="#" @click.stop="editGroup(group)">
                                    <i class="fas fa-edit me-2"></i>Редактировать
                                </a>
                                <a class="dropdown-item" href="#" @click.stop="deleteGroup(group)">
                                    <i class="fas fa-trash me-2"></i>Удалить
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Pools List -->
                    <div v-if="!isSidebarCollapsed">
                        <div v-for="pool in group.pools" :key="pool.id"
                             class="sidebar-item pool-item"
                             :class="{ 'active': selectedPool && selectedPool.id === pool.id }"
                             @click.stop="selectPool(pool, group)">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>{{ pool.name }}</span>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary action-btn dropdown-toggle"
                                            type="button" data-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-end">
                                        <a class="dropdown-item" href="#" @click.stop="editPool(pool)">
                                            <i class="fas fa-edit me-2"></i>Редактировать
                                        </a>
                                        <a class="dropdown-item" href="#" @click.stop="configurePool(pool)">
                                            <i class="fas fa-cog me-2"></i>Настроить
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger" href="#" @click.stop="deletePool(pool)">
                                            <i class="fas fa-trash me-2"></i>Удалить
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="p-2" v-if="selectedGroup && selectedGroup.id === group.id">
                            <button class="btn btn-sm btn-outline-primary w-100" @click="showAddPoolModal(group)">
                                <i class="fas fa-plus me-1"></i>Добавить пул
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mt-3" v-if="!isSidebarCollapsed">
                    <button class="btn btn-primary w-100" @click="showAddGroupModal">
                        <i class="fas fa-plus me-1"></i>Добавить группу
                    </button>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content flex-grow-1" :class="{ 'sidebar-collapsed': isSidebarCollapsed }">
            <div class="container-fluid">
                <div class="table-container">
                    <div v-if="selectedPool">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5>
                                <i class="fas fa-university me-2"></i>
                                Счета пула "{{ selectedPool.name }}"
                            </h5>
                            <button class="btn btn-sm btn-outline-secondary" @click="refreshAccounts">
                                <i class="fas fa-sync"></i>
                            </button>
                        </div>

                        <div v-if="loadingAccounts" class="loading">
                            <div class="spinner-border" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>

                        <div v-else-if="accounts.length === 0" class="text-center py-5">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted">В этом пуле нет счетов</p>
                        </div>

                        <div v-else>
                            <table class="table table-hover">
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Название</th>
                                    <th>Валюта</th>
                                    <th>Тип</th>
                                    <th>Банк</th>
                                    <th>Страна</th>
                                    <th>Suspense</th>
                                    <th>Дата создания</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr v-for="account in accounts" :key="account.id">
                                    <td>{{ account.id }}</td>
                                    <td>{{ account.name }}</td>
                                    <td>{{ account.currency || '-' }}</td>
                                    <td>{{ account.account_type || '-' }}</td>
                                    <td>{{ account.bank_code || '-' }}</td>
                                    <td>{{ account.country || '-' }}</td>
                                    <td>
                                        <span v-if="account.is_suspense" class="badge badge-suspense">INV</span>
                                        <span v-else class="badge bg-secondary">NRE</span>
                                    </td>
                                    <td>{{ formatDate(account.created_at) }}</td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-else class="text-center py-5">
                        <i class="fas fa-arrow-left fa-3x text-muted mb-3"></i>
                        <p class="text-muted">Выберите пул в меню слева, чтобы увидеть список счетов</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <!-- Add Group Modal -->
    <div class="modal fade" id="addGroupModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Добавить группу</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Название группы</label>
                        <input type="text" class="form-control" v-model="newGroup.name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Описание</label>
                        <textarea class="form-control" v-model="newGroup.description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" @click="createGroup">Сохранить</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Group Modal -->
    <div class="modal fade" id="editGroupModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Редактировать группу</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Название группы</label>
                        <input type="text" class="form-control" v-model="editingGroup.name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Описание</label>
                        <textarea class="form-control" v-model="editingGroup.description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" @click="updateGroup">Сохранить</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Pool Modal -->
    <div class="modal fade" id="addPoolModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Добавить пул</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Название пула</label>
                        <input type="text" class="form-control" v-model="newPool.name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Описание</label>
                        <textarea class="form-control" v-model="newPool.description" rows="2"></textarea>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="poolActive" v-model="newPool.is_active">
                        <label class="form-check-label" for="poolActive">Активен</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" @click="createPool">Сохранить</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Pool Modal -->
    <div class="modal fade" id="editPoolModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Редактировать пул</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Название пула</label>
                        <input type="text" class="form-control" v-model="editingPool.name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Описание</label>
                        <textarea class="form-control" v-model="editingPool.description" rows="2"></textarea>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="editPoolActive" v-model="editingPool.is_active">
                        <label class="form-check-label" for="editPoolActive">Активен</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" @click="updatePool">Сохранить</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Configure Pool Modal -->
    <div class="modal fade" id="configurePoolModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Настройки фильтрации пула "{{ editingPool.name }}"</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Настройте критерии для автоматического включения счетов в пул</p>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Валюта</label>
                            <input type="text" class="form-control" v-model="editingPool.filter_criteria.currency" placeholder="USD, EUR...">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Тип счета</label>
                            <input type="text" class="form-control" v-model="editingPool.filter_criteria.account_type" placeholder="NRE, INV...">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Код банка</label>
                            <input type="text" class="form-control" v-model="editingPool.filter_criteria.bank_code" placeholder="SWIFT/BIC...">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Страна</label>
                            <input type="text" class="form-control" v-model="editingPool.filter_criteria.country" placeholder="US, DE...">
                        </div>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="filterSuspense" v-model="editingPool.filter_criteria.is_suspense">
                        <label class="form-check-label" for="filterSuspense">Только suspense счета</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" @click="updatePool">Сохранить настройки</button>
                </div>
            </div>
        </div>
    </div>

<?php $this->registerJs(<<<JS
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
    mounted() {
        this.loadGroups();
    },
    methods: {
        toggleSidebar() {
            this.isSidebarCollapsed = !this.isSidebarCollapsed;
        },
        
        loadGroups() {
            this.loadingGroups = true;
            axios.get('/account-group/get-groups')
                .then(response => {
                    this.groups = response.data.data;
                })
                .catch(error => {
                    console.error('Error loading groups:', error);
                    Swal.fire('Ошибка', 'Не удалось загрузить группы', 'error');
                })
                .finally(() => {
                    this.loadingGroups = false;
                });
        },
        
        selectGroup(group) {
            this.selectedGroup = group;
            this.selectedPool = null;
            this.accounts = [];
        },
        
        selectPool(pool, group) {
            this.selectedPool = pool;
            this.selectedGroup = group;
            this.loadAccounts(pool.id);
        },
        
        loadAccounts(poolId) {
            this.loadingAccounts = true;
            this.accounts = [];
            axios.get('/account-pool/get-accounts', { params: { id: poolId } })
                .then(response => {
                    if (response.data.success) {
                        this.accounts = response.data.data;
                    } else {
                        Swal.fire('Ошибка', response.data.message, 'error');
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
            this.newGroup = { name: '', description: '' };
            $('#addGroupModal').modal('show');
        },
        
        createGroup() {
            axios.post('/account-group/create', this.newGroup)
                .then(response => {
                    if (response.data.success) {
                        Swal.fire('Успех', response.data.message, 'success');
                        $('#addGroupModal').modal('hide');
                        this.loadGroups();
                    } else {
                        Swal.fire('Ошибка', response.data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error creating group:', error);
                    Swal.fire('Ошибка', 'Не удалось создать группу', 'error');
                });
        },
        
        editGroup(group) {
            this.editingGroup = { ...group };
            $('#editGroupModal').modal('show');
        },
        
        updateGroup() {
            axios.post('/account-group/update', this.editingGroup)
                .then(response => {
                    if (response.data.success) {
                        Swal.fire('Успех', response.data.message, 'success');
                        $('#editGroupModal').modal('hide');
                        this.loadGroups();
                    } else {
                        Swal.fire('Ошибка', response.data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error updating group:', error);
                    Swal.fire('Ошибка', 'Не удалось обновить группу', 'error');
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
                    axios.post('/account-group/delete', { id: group.id })
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
                                Swal.fire('Ошибка', response.data.message, 'error');
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
            this.newPool = {
                group_id: group.id,
                name: '',
                description: '',
                is_active: true
            };
            $('#addPoolModal').modal('show');
        },
        
        createPool() {
            axios.post('/account-pool/create', this.newPool)
                .then(response => {
                    if (response.data.success) {
                        Swal.fire('Успех', response.data.message, 'success');
                        $('#addPoolModal').modal('hide');
                        this.loadGroups();
                    } else {
                        Swal.fire('Ошибка', response.data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error creating pool:', error);
                    Swal.fire('Ошибка', 'Не удалось создать пул', 'error');
                });
        },
        
        editPool(pool) {
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
            $('#editPoolModal').modal('show');
        },
        
        updatePool() {
            axios.post('/account-pool/update', this.editingPool)
                .then(response => {
                    if (response.data.success) {
                        Swal.fire('Успех', response.data.message, 'success');
                        $('#editPoolModal').modal('hide');
                        $('#configurePoolModal').modal('hide');
                        this.loadGroups();
                        if (this.selectedPool && this.selectedPool.id === this.editingPool.id) {
                            this.loadAccounts(this.selectedPool.id);
                        }
                    } else {
                        Swal.fire('Ошибка', response.data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error updating pool:', error);
                    Swal.fire('Ошибка', 'Не удалось обновить пул', 'error');
                });
        },
        
        configurePool(pool) {
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
            $('#configurePoolModal').modal('show');
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
                    axios.post('/account-pool/delete', { id: pool.id })
                        .then(response => {
                            if (response.data.success) {
                                Swal.fire('Удалено', response.data.message, 'success');
                                this.loadGroups();
                                if (this.selectedPool && this.selectedPool.id === pool.id) {
                                    this.selectedPool = null;
                                    this.accounts = [];
                                }
                            } else {
                                Swal.fire('Ошибка', response.data.message, 'error');
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
JS
); ?>