<?php
/** @var yii\web\View $this */
?>

<!-- Add Group Modal -->
<div class="modal fade" id="addGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Добавить группу</h5>
                <button type="button" class="btn-close" @click="closeAddGroupModal" aria-label="Close"></button>
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
                <button type="button" class="btn btn-secondary" @click="closeAddGroupModal">Отмена</button>
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
                <button type="button" class="btn-close" @click="closeEditGroupModal" aria-label="Close"></button>
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
                <button type="button" class="btn btn-secondary" @click="closeEditGroupModal">Отмена</button>
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
                <button type="button" class="btn-close" @click="closeAddPoolModal" aria-label="Close"></button>
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
                <button type="button" class="btn btn-secondary" @click="closeAddPoolModal">Отмена</button>
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
                <button type="button" class="btn-close" @click="closeEditPoolModal" aria-label="Close"></button>
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
                <button type="button" class="btn btn-secondary" @click="closeEditPoolModal">Отмена</button>
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
                <button type="button" class="btn-close" @click="closeConfigurePoolModal" aria-label="Close"></button>
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
                <button type="button" class="btn btn-secondary" @click="closeConfigurePoolModal">Отмена</button>
                <button type="button" class="btn btn-primary" @click="updatePool">Сохранить настройки</button>
            </div>
        </div>
    </div>
</div>