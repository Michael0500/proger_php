<?php
/** @var yii\web\View $this */
use app\models\AccountGroup;
use app\models\AccountPool;
use yii\helpers\Html;
?>

<div id="sidebar" :class="{ 'collapsed': isSidebarCollapsed }">
    <div id="sidebar-toggle" @click="toggleSidebar">
        <i class="fas fa-chevron-left"></i>
    </div>

    <div class="p-3">
        <h6 class="sidebar-text mb-3">
            <i class="fas fa-layer-group me-2"></i>Группы ностробанков
        </h6>
        <hr class="sidebar-text">

        <div v-if="loadingGroups" class="text-center py-3">
            <div class="spinner-border spinner-border-sm" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>

        <div v-for="group in groups" :key="group.id" class="group-item">
            <!-- Group Header -->
            <div class="group-header"
                 :class="{ 'active': selectedGroup && selectedGroup.id === group.id }"
                 @click="toggleGroup(group)">
                <div class="d-flex align-items-center">
                    <i class="fas fa-folder group-collapse-icon"></i>
                    <span class="group-name">{{ group.name }}</span>
                </div>
                <div class="dropdown" v-if="!isSidebarCollapsed">
                    <button class="btn btn-sm btn-outline-secondary action-btn dropdown-toggle"
                            type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#" @click.stop="editGroup(group)">
                                <i class="fas fa-edit me-2"></i>Редактировать
                            </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="#" @click.stop="deleteGroup(group)">
                                <i class="fas fa-trash me-2"></i>Удалить
                            </a></li>
                    </ul>
                </div>
            </div>

            <!-- Pools List -->
            <div class="pool-list" v-if="group.expanded && !isSidebarCollapsed">
                <div v-for="pool in group.pools" :key="pool.id"
                     class="pool-item"
                     :class="{ 'active': selectedPool && selectedPool.id === pool.id }"
                     @click.stop="selectPool(pool, group)">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="pool-name">{{ pool.name }}</span>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary action-btn dropdown-toggle"
                                    type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="#" @click.stop="editPool(pool)">
                                        <i class="fas fa-edit me-2"></i>Редактировать
                                    </a></li>
                                <li><a class="dropdown-item" href="#" @click.stop="configurePool(pool)">
                                        <i class="fas fa-cog me-2"></i>Настроить
                                    </a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="#" @click.stop="deletePool(pool)">
                                        <i class="fas fa-trash me-2"></i>Удалить
                                    </a></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="p-2 border-top">
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