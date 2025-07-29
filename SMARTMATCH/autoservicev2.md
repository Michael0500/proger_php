### Таблица `transactions`

Вот структура вашей таблицы `transactions`:

```sql
CREATE TABLE transactions (
    id SERIAL PRIMARY KEY,
    transaction_id VARCHAR(255),
    instruction_id VARCHAR(255),
    endtoend_id VARCHAR(255),
    message_id VARCHAR(255),
    amount NUMERIC(15, 2),
    value_date DATE,
    debit_credit CHAR(1), -- 'D' для дебета, 'C' для кредита
    section VARCHAR(10), -- 'NRE' или 'INV'
    type VARCHAR(10) NOT NULL CHECK (type IN ('Ledger', 'Statement')), -- тип записи
    matched BOOLEAN DEFAULT FALSE, -- флаг для отметки о квитировании
    UNIQUE (transaction_id, instruction_id, endtoend_id, message_id) -- уникальность по идентификаторам транзакции
);
```

### Настройки автоквитирования (`matching_rules`)

Структура таблицы настроек автоквитирования:

```sql
CREATE TABLE matching_rules (
    id SERIAL PRIMARY KEY,
    section VARCHAR(10), -- 'NRE' или 'INV'
    type_pair VARCHAR(20), -- 'Ledger with Statement', 'Ledger with Ledger', 'Statement with Statement'
    debit_credit_match BOOLEAN, -- совпадение признака дебет/кредит
    amount_match BOOLEAN, -- совпадение суммы
    value_date_match BOOLEAN, -- совпадение даты валютирования
    transaction_id_match BOOLEAN, -- совпадение transaction_id
    instruction_id_match BOOLEAN, -- совпадение instruction_id
    endtoend_id_match BOOLEAN, -- совпадение endtoend_id
    message_id_match BOOLEAN, -- совпадение message_id
    other_id_match BOOLEAN, -- совпадение other_id
    description TEXT -- описание правила
);
```

### Итоговый код сервиса автоквитования

Сервис будет использовать настройки из таблицы `matching_rules` для гибкой настройки правил квитирования. Он будет строить SQL-запросы динамически в зависимости от настроек.

#### Класс `AutoMatchingService`

```php
<?php
// components/AutoMatchingService.php

namespace app\components;

use Yii;
use app\models\MatchingRule;
use yii\db\Query;

class AutoMatchingService
{
    /**
     * Выполнить автоматическое квитирование по настроенным правилам
     */
    public function performAutoMatching()
    {
        $rules = MatchingRule::find()->all();
        $matchedCount = 0;

        foreach ($rules as $rule) {
            $matchedCount += $this->matchByRule($rule);
        }

        return $matchedCount;
    }

    /**
     * Квитирование по конкретному правилу
     */
    private function matchByRule($rule)
    {
        $matchedCount = 0;

        // Построение условия сопоставления
        $conditions = $this->buildMatchingConditions($rule);

        // Построение SQL запроса
        $sql = $this->buildMatchingQuery($conditions, $rule);

        try {
            $result = Yii::$app->db->createCommand($sql)->execute();
            $matchedCount = $result / 2; // Делим на 2, так как обновляем две записи
        } catch (\Exception $e) {
            Yii::error('Ошибка при автоматическом квитировании: ' . $e->getMessage());
        }

        return $matchedCount;
    }

    /**
     * Построение условий сопоставления
     */
    private function buildMatchingConditions($rule)
    {
        $conditions = [];

        // Совпадение суммы
        if ($rule->amount_match) {
            $conditions[] = 'l.amount = s.amount';
        }

        // Совпадение даты валютирования
        if ($rule->value_date_match) {
            $conditions[] = 'l.value_date = s.value_date';
        }

        // Различные признаки дебета/кредита
        if ($rule->debit_credit_match) {
            $conditions[] = "(l.debit_credit = 'D' AND s.debit_credit = 'C') OR (l.debit_credit = 'C' AND s.debit_credit = 'D')";
        }

        // Совпадение идентификаторов транзакций
        $idConditions = [];
        if ($rule->transaction_id_match) {
            $idConditions[] = 'l.transaction_id = s.transaction_id';
        }
        if ($rule->instruction_id_match) {
            $idConditions[] = 'l.instruction_id = s.instruction_id';
        }
        if ($rule->endtoend_id_match) {
            $idConditions[] = 'l.endtoend_id = s.endtoend_id';
        }
        if ($rule->message_id_match) {
            $idConditions[] = 'l.message_id = s.message_id';
        }

        if (!empty($idConditions)) {
            $conditions[] = '(' . implode(' OR ', $idConditions) . ')';
        }

        return $conditions;
    }

    /**
     * Построение SQL запроса на основе правил
     */
    private function buildMatchingQuery($conditions, $rule)
    {
        // Основные условия JOIN
        $whereClause = implode(' AND ', $conditions);

        // Условия для типа записи
        $typeCondition = '';
        switch ($rule->type_pair) {
            case 'Ledger with Statement':
                $typeCondition = "l.type = 'Ledger' AND s.type = 'Statement'";
                break;
            case 'Ledger with Ledger':
                $typeCondition = "l.type = 'Ledger' AND s.type = 'Ledger'";
                break;
            case 'Statement with Statement':
                $typeCondition = "l.type = 'Statement' AND s.type = 'Statement'";
                break;
        }

        // Финальный SQL запрос
        return "
            WITH matched_transactions AS (
                SELECT 
                    l.id AS ledger_id,
                    s.id AS statement_id
                FROM 
                    transactions l
                JOIN 
                    transactions s ON $whereClause
                WHERE 
                    $typeCondition
                    AND l.section = :section
                    AND l.matched = FALSE
                    AND s.matched = FALSE
            )
            UPDATE transactions l
            SET matched = TRUE
            FROM matched_transactions mt
            WHERE l.id = mt.ledger_id;

            UPDATE transactions s
            SET matched = TRUE
            FROM matched_transactions mt
            WHERE s.id = mt.statement_id;
        ";
    }
}
```

### Объяснение работы сервиса:

1. **Получение правил**: Сначала мы получаем все правила автоквитирования из таблицы `matching_rules`.
2. **Для каждого правила**:
    - Строим условия сопоставления (`buildMatchingConditions`) на основе настроек (`amount_match`, `value_date_match`, `debit_credit_match`, идентификаторы транзакций).
    - Строим SQL-запрос (`buildMatchingQuery`) с учетом типов записей (`Ledger`, `Statement`) и разделов (`section`).
    - Выполняем SQL-запрос для квитирования.
3. **Обновление флага `matched`**: После успешного сопоставления устанавливаем `matched = TRUE` для соответствующих записей.

### Пример использования сервиса

#### Консольная команда для автоквитирования

```php
<?php
// commands/MatchingController.php

namespace app\commands;

use Yii;
use yii\console\Controller;
use app\components\AutoMatchingService;

class MatchingController extends Controller
{
    /**
     * Выполнить автоматическое квитирование
     */
    public function actionAutoMatch()
    {
        $service = new AutoMatchingService();
        $matchedCount = $service->performAutoMatching();

        echo "Автоматическое квитирование завершено. Сопоставлено записей: $matchedCount\n";

        return Controller::EXIT_CODE_NORMAL;
    }
}
```

#### Запуск команды:

```bash
yii matching/auto-match
```

### Пример настроек в таблице `matching_rules`

| id | section | type_pair          | debit_credit_match | amount_match | value_date_match | transaction_id_match | instruction_id_match | endtoend_id_match | message_id_match | other_id_match | description                          |
|----|---------|--------------------|--------------------|--------------|------------------|----------------------|----------------------|-------------------|------------------|----------------|---------------------------------------|
| 1  | NRE     | Ledger with Statement | true             | true         | true             | true                 | true                 | true              | true             | false          | Автоматическое квитирование для NRE   |
| 2  | INV     | Ledger with Ledger      | true             | true         | true             | true                 | true                 | true              | true             | false          | Автоматическое квитирование для INV   |

### Как работает SQL-запрос:

1. **WITH Clause**: Создается временное представление `matched_transactions`, где выбираются пары записей, удовлетворяющие всем условиям сопоставления.
2. **UPDATE**: Для каждой найденной пары обновляется флаг `matched` в обеих записях (`Ledger` и `Statement`).

### Пример SQL-запроса для правила:

```sql
WITH matched_transactions AS (
    SELECT 
        l.id AS ledger_id,
        s.id AS statement_id
    FROM 
        transactions l
    JOIN 
        transactions s ON l.amount = s.amount AND l.value_date = s.value_date
                       AND ((l.debit_credit = 'D' AND s.debit_credit = 'C') OR (l.debit_credit = 'C' AND s.debit_credit = 'D'))
                       AND (l.transaction_id = s.transaction_id OR l.instruction_id = s.instruction_id OR l.endtoend_id = s.endtoend_id OR l.message_id = s.message_id)
    WHERE 
        l.type = 'Ledger' AND s.type = 'Statement'
        AND l.section = 'NRE'
        AND l.matched = FALSE
        AND s.matched = FALSE
)
UPDATE transactions l
SET matched = TRUE
FROM matched_transactions mt
WHERE l.id = mt.ledger_id;

UPDATE transactions s
SET matched = TRUE
FROM matched_transactions mt
WHERE s.id = mt.statement_id;
```

### Заключение:

1. Сервис `AutoMatchingService` полностью адаптирован под вашу таблицу `transactions` и таблицу настроек `matching_rules`.
2. Он позволяет гибко настраивать правила автоквитирования через интерфейс Yii2.
3. Автоматическое квитирование можно запустить через консольную команду `yii matching/auto-match`.

Это решение обеспечивает полную автоматизацию процесса квитирования с возможностью гибкой настройки правил.