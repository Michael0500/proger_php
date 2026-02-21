<?php /** @var yii\web\View $this */ ?>

<div id="sidebar" :class="{ 'collapsed': isSidebarCollapsed }">

    <!-- Тоггл -->
    <div id="sidebar-toggle" @click="toggleSidebar" title="Свернуть/развернуть">
        <i class="fas fa-chevron-left"></i>
    </div>

    <!-- Заголовок (скрывается при collapsed) -->
    <div class="sidebar-head">
        <h6><i class="fas fa-layer-group me-1"></i> Группы ностробанков</h6>
    </div>

    <!-- Дерево -->
    <div class="sidebar-scroll">

        <div v-if="loadingGroups" style="text-align:center;padding:20px">
            <div class="spinner-border spinner-border-sm" style="color:#6366f1;width:18px;height:18px;border-width:2px"></div>
        </div>

        <div v-else-if="groups.length === 0" style="padding:20px 8px;text-align:center;color:#9ca3af;font-size:12px">
            <i class="fas fa-folder-open" style="font-size:22px;margin-bottom:8px;display:block;opacity:.3"></i>
            Нет групп
        </div>

        <!-- Группы -->
        <div v-for="group in groups" :key="group.id" class="tree-group">

            <div class="tree-group-row"
                 :class="{
                     active: selectedGroup && selectedGroup.id === group.id,
                     'has-active-pool': selectedPool && group.pools &&
                         group.pools.some(function(p){ return p.id === selectedPool.id; })
                 }"
                 :data-name="group.name"
                 @click="toggleGroup(group)">

                <!-- Стрелка (скрыта в collapsed) -->
                <span class="tree-chevron" :class="{ open: group.expanded }">
                    <i class="fas fa-chevron-right"></i>
                </span>

                <!-- Иконка (в collapsed — единственное что видно) -->
                <span class="tree-group-icon">
                    <i :class="group.expanded ? 'fas fa-folder-open' : 'fas fa-folder'"></i>
                </span>

                <!-- Название + счётчик (скрыты в collapsed) -->
                <span class="tree-group-name" :title="group.name">{{ group.name }}</span>
                <span class="tree-group-count" v-if="group.pools && group.pools.length">
                    {{ group.pools.length }}
                </span>

                <!-- Кнопки (скрыты в collapsed) -->
                <span class="tree-actions" @click.stop>
                    <button class="tree-btn primary" @click.stop="editGroup(group)" title="Редактировать">
                        <i class="fas fa-pen"></i>
                    </button>
                    <button class="tree-btn danger" @click.stop="deleteGroup(group)" title="Удалить">
                        <i class="fas fa-trash"></i>
                    </button>
                </span>
            </div>

            <!-- Пулы — только в развёрнутом виде, v-show скрывает при collapsed тоже  -->
            <div class="tree-pools" v-show="group.expanded">
                <div v-if="!group.pools || group.pools.length === 0"
                     style="font-size:11px;color:#9ca3af;padding:3px 6px 3px 4px">
                    Нет пулов
                </div>

                <div v-for="pool in group.pools" :key="pool.id"
                     class="tree-pool-row"
                     :class="{ active: selectedPool && selectedPool.id === pool.id }"
                     @click.stop="selectPool(pool, group)">

                    <span class="tree-pool-dot"></span>
                    <span class="tree-pool-name" :title="pool.name">{{ pool.name }}</span>
                    <span v-if="!pool.is_active"
                          style="font-size:9px;background:#e5e7eb;color:#6b7280;
                                 border-radius:4px;padding:1px 5px;margin-left:3px;flex-shrink:0">
                        Откл
                    </span>

                    <span class="tree-actions" @click.stop>
                        <button class="tree-btn primary" @click.stop="editPool(pool)" title="Редактировать">
                            <i class="fas fa-pen"></i>
                        </button>
                        <button class="tree-btn warning" @click.stop="configurePool(pool)" title="Настройки">
                            <i class="fas fa-sliders-h"></i>
                        </button>
                        <button class="tree-btn danger" @click.stop="deletePool(pool)" title="Удалить">
                            <i class="fas fa-trash"></i>
                        </button>
                    </span>
                </div>

                <!-- Добавить пул -->
                <button class="tree-add-pool" @click.stop="showAddPoolModal(group)">
                    <i class="fas fa-plus" style="font-size:9px"></i>
                    Добавить пул
                </button>
            </div>

        </div>
        <!-- /v-for group -->

    </div><!-- /sidebar-scroll -->

    <!-- Кнопка «Добавить группу» -->
    <div class="sidebar-footer">
        <button class="btn-add-group" @click="showAddGroupModal" title="Добавить группу">
            <i class="fas fa-plus" style="font-size:11px"></i>
            <span>Добавить группу</span>
        </button>
    </div>

</div>