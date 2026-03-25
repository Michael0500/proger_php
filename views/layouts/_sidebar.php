<?php /** @var yii\web\View $this */ ?>

<div id="sidebar" :class="{ 'collapsed': isSidebarCollapsed }">

    <!-- Тоггл -->
    <div id="sidebar-toggle" @click="toggleSidebar" title="Свернуть/развернуть">
        <i class="fas fa-chevron-left"></i>
    </div>

    <!-- Заголовок (скрывается при collapsed) -->
    <div class="sidebar-head">
        <h6><i class="fas fa-layer-group me-1"></i> Категории</h6>
    </div>

    <!-- Дерево -->
    <div class="sidebar-scroll">

        <div v-if="loadingCategories" style="text-align:center;padding:20px">
            <div class="spinner-border spinner-border-sm" style="color:#6366f1;width:18px;height:18px;border-width:2px"></div>
        </div>

        <div v-else-if="categories.length === 0" style="padding:20px 8px;text-align:center;color:#9ca3af;font-size:12px">
            <i class="fas fa-folder-open" style="font-size:22px;margin-bottom:8px;display:block;opacity:.3"></i>
            Нет категорий
        </div>

        <!-- Категории -->
        <div v-for="category in categories" :key="category.id" class="tree-group">

            <div class="tree-group-row"
                 :class="{
                     active: selectedCategory && selectedCategory.id === category.id,
                     'has-active-pool': selectedGroup && category.groups &&
                         category.groups.some(function(g){ return g.id === selectedGroup.id; })
                 }"
                 :data-name="category.name"
                 @click="toggleCategory(category)">

                <!-- Стрелка (скрыта в collapsed) -->
                <span class="tree-chevron" :class="{ open: category.expanded }">
                    <i class="fas fa-chevron-right"></i>
                </span>

                <!-- Иконка (в collapsed — единственное что видно) -->
                <span class="tree-group-icon">
                    <i :class="category.expanded ? 'fas fa-folder-open' : 'fas fa-folder'"></i>
                </span>

                <!-- Название + счётчик (скрыты в collapsed) -->
                <span class="tree-group-name" :title="category.name">{{ category.name }}</span>
                <span class="tree-group-count" v-if="category.groups && category.groups.length">
                    {{ category.groups.length }}
                </span>

                <!-- Кнопки (скрыты в collapsed) -->
                <span class="tree-actions" @click.stop>
                    <button class="tree-btn primary" @click.stop="editCategory(category)" title="Редактировать">
                        <i class="fas fa-pen"></i>
                    </button>
                    <button class="tree-btn danger" @click.stop="deleteCategory(category)" title="Удалить">
                        <i class="fas fa-trash"></i>
                    </button>
                </span>
            </div>

            <!-- Группы — только в развёрнутом виде -->
            <div class="tree-pools" v-show="category.expanded">
                <div v-if="!category.groups || category.groups.length === 0"
                     style="font-size:11px;color:#9ca3af;padding:3px 6px 3px 4px">
                    Нет групп
                </div>

                <div v-for="group in category.groups" :key="group.id"
                     class="tree-pool-row"
                     :class="{ active: selectedGroup && selectedGroup.id === group.id }"
                     @click.stop="selectGroup(group, category)">

                    <span class="tree-pool-dot"></span>
                    <span class="tree-pool-name" :title="group.name">{{ group.name }}</span>
                    <span v-if="!group.is_active"
                          style="font-size:9px;background:#e5e7eb;color:#6b7280;
                                 border-radius:4px;padding:1px 5px;margin-left:3px;flex-shrink:0">
                        Откл
                    </span>

                    <span class="tree-actions" @click.stop>
                        <button class="tree-btn primary" @click.stop="editGroup(group)" title="Редактировать">
                            <i class="fas fa-pen"></i>
                        </button>
                        <button class="tree-btn warning" @click.stop="configureGroup(group)" title="Настройки">
                            <i class="fas fa-sliders-h"></i>
                        </button>
                        <button class="tree-btn danger" @click.stop="deleteGroup(group)" title="Удалить">
                            <i class="fas fa-trash"></i>
                        </button>
                    </span>
                </div>

                <!-- Добавить группу -->
                <button class="tree-add-pool" @click.stop="showAddGroupModal(category)">
                    <i class="fas fa-plus" style="font-size:9px"></i>
                    Добавить группу
                </button>
            </div>

        </div>
        <!-- /v-for category -->

    </div><!-- /sidebar-scroll -->

    <!-- Кнопка «Добавить категорию» -->
    <div class="sidebar-footer">
        <button class="btn-add-group" @click="showAddCategoryModal" title="Добавить категорию">
            <i class="fas fa-plus" style="font-size:11px"></i>
            <span>Добавить категорию</span>
        </button>
    </div>

</div>
