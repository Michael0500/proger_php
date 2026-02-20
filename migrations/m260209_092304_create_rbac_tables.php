<?php

use yii\db\Migration;
use yii\rbac\DbManager;

class m260209_092304_create_rbac_tables extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $tableOptions = null;

        // Таблица для ролей
        $this->createTable('{{%auth_item}}', [
            'name' => $this->string(64)->notNull(),
            'type' => $this->smallInteger()->notNull(),
            'description' => $this->text(),
            'rule_name' => $this->string(64),
            'data' => $this->text(),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
            'PRIMARY KEY (name)',
        ], $tableOptions);

        // Таблица для связей между ролями
        $this->createTable('{{%auth_item_child}}', [
            'parent' => $this->string(64)->notNull(),
            'child' => $this->string(64)->notNull(),
            'PRIMARY KEY (parent, child)',
        ], $tableOptions);

        // Таблица для правил
        $this->createTable('{{%auth_rule}}', [
            'name' => $this->string(64)->notNull(),
            'data' => $this->text(),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
            'PRIMARY KEY (name)',
        ], $tableOptions);

        // Таблица для назначения ролей пользователям
        $this->createTable('{{%auth_assignment}}', [
            'item_name' => $this->string(64)->notNull(),
            'user_id' => $this->string(64)->notNull(),
            'created_at' => $this->integer(),
            'PRIMARY KEY (item_name, user_id)',
        ], $tableOptions);

        // Внешние ключи для PostgreSQL
        $this->addForeignKey(
            'fk-auth_item_child-parent',
            '{{%auth_item_child}}',
            'parent',
            '{{%auth_item}}',
            'name',
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            'fk-auth_item_child-child',
            '{{%auth_item_child}}',
            'child',
            '{{%auth_item}}',
            'name',
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            'fk-auth_assignment-item_name',
            '{{%auth_assignment}}',
            'item_name',
            '{{%auth_item}}',
            'name',
            'CASCADE',
            'CASCADE'
        );

        // Индексы
        $this->createIndex('idx-auth_item-type', '{{%auth_item}}', 'type');
        $this->createIndex('idx-auth_rule-name', '{{%auth_rule}}', 'name');
        $this->createIndex('idx-auth_assignment-item_name', '{{%auth_assignment}}', 'item_name');

        $auth = Yii::$app->authManager;

// Удаление всех существующих данных
        $auth->removeAll();

// Создание ролей
        $admin = $auth->createRole('admin');
        $admin->description = 'Администратор системы';
        $auth->add($admin);

        $manager = $auth->createRole('manager');
        $manager->description = 'Менеджер';
        $auth->add($manager);

        $user = $auth->createRole('user');
        $user->description = 'Обычный пользователь';
        $auth->add($user);

// Создание разрешений
        $createPost = $auth->createPermission('createPost');
        $createPost->description = 'Создать пост';
        $auth->add($createPost);

        $updatePost = $auth->createPermission('updatePost');
        $updatePost->description = 'Редактировать пост';
        $auth->add($updatePost);

        $deletePost = $auth->createPermission('deletePost');
        $deletePost->description = 'Удалить пост';
        $auth->add($deletePost);

// Назначение разрешений ролям
        $auth->addChild($user, $createPost);
        $auth->addChild($manager, $updatePost);
        $auth->addChild($admin, $deletePost);

// Назначение ролей администратору
        $auth->assign($admin, 1); // ID пользователя-администратора

        echo "RBAC инициализирован успешно!\n";
    }

    public function safeDown()
    {
        $this->dropTable('{{%auth_assignment}}');
        $this->dropTable('{{%auth_rule}}');
        $this->dropTable('{{%auth_item_child}}');
        $this->dropTable('{{%auth_item}}');
    }
}
