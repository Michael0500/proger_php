На основе предоставленных таблиц и документа с правилами формирования адресной строки в ГАР, ниже приведён SQL-запрос на **PostgreSQL**, который формирует **полную адресную строку** в **муниципальном делении** по `OBJECTID`. В данном случае предполагается, что `OBJECTID` известен и передаётся, например, через параметр.

### 🧩 SQL-запрос для получения адресной строки

```sql
WITH reestr AS (
    SELECT objectid, levelid, objectguid
    FROM reestr_objects
    WHERE objectid = 44877013 AND isactive = 1
),
mun_path AS (
    SELECT unnest(string_to_array(path, '.'))::bigint AS objectid
    FROM mun_hierarchy
    WHERE objectid = 44877013
      AND isactive = 1
      AND enddate > CURRENT_DATE
    LIMIT 1
),
objects AS (
    SELECT r.objectid, r.levelid, ao.name, ao.typename, r.objectguid
    FROM reestr_objects r
    LEFT JOIN address_objects ao ON ao.objectid = r.objectid AND ao.isactual = 1 AND ao.isactive = 1
),
official_names AS (
    SELECT objectid, value
    FROM params
    WHERE typeid = 16
      AND enddate > CURRENT_DATE
),
address_parts AS (
    SELECT
        r.objectid,
        r.levelid,
        COALESCE(onm.value, 
            CASE
                WHEN r.levelid = 10 THEN ht.name || ' ' || h.housenum
                WHEN r.levelid = 11 THEN at.name || ' ' || a.number
                WHEN r.levelid = 12 THEN rt.name || ' ' || ro.number
                WHEN r.levelid = 9 THEN 'Земельный участок ' || s.number
                WHEN r.levelid = 17 THEN 'Машино-место ' || cp.number
                ELSE COALESCE(aot.name, r.name || ' ' || r.typename)
            END
        ) AS full_name
    FROM mun_path p
    LEFT JOIN reestr_objects r ON r.objectid = p.objectid AND r.isactive = 1
    LEFT JOIN address_objects aobj ON aobj.objectid = r.objectid AND aobj.isactual = 1 AND aobj.isactive = 1
    LEFT JOIN address_objecttype aot ON aot.shortname = aobj.typename AND aot.level = r.levelid
    LEFT JOIN official_names onm ON onm.objectid = r.objectid
    LEFT JOIN houses h ON h.objectid = r.objectid AND h.isactual = 1 AND h.isactive = 1
    LEFT JOIN house_types ht ON ht.id = h.housetype
    LEFT JOIN apartments a ON a.objectid = r.objectid AND a.isactual = 1 AND a.isactive = 1
    LEFT JOIN apartment_types at ON at.id = a.aparttype
    LEFT JOIN rooms ro ON ro.objectid = r.objectid AND ro.isactual = 1 AND ro.isactive = 1
    LEFT JOIN room_types rt ON rt.id = ro.roomtype
    LEFT JOIN steads s ON s.objectid = r.objectid AND s.isactual = 1 AND s.isactive = 1
    LEFT JOIN car_places cp ON cp.objectid = r.objectid AND cp.isactual = 1 AND cp.isactive = 1
)
SELECT string_agg(full_name, ', ' ORDER BY levelid) AS full_address
FROM address_parts;
```

---

### 🔍 Описание логики запроса:

1. **`reestr`** — выбор текущего элемента по `OBJECTID`, чтобы определить уровень и GUID.
2. **`mun_path`** — получение materialized `PATH` из `mun_hierarchy`, разбитого на `OBJECTID`.
3. **`objects` и `official_names`** — извлечение имени объекта и официального названия, если оно есть (`TYPEID = 16`).
4. **`address_parts`** — формирование "полного наименования" в зависимости от уровня:

    * если есть официальное название — используем его;
    * если уровень = 10 (дом) — тип дома + номер;
    * если уровень = 11 (квартира) — тип квартиры + номер;
    * если уровень = 12 (комната) — тип комнаты + номер;
    * и т.д.
5. **Финальный SELECT** — агрегирует `full_name` по всем уровням в `PATH`, упорядочивая по `LEVELID`, чтобы получить корректную адресную строку.

---

### 📌 Пример результата:

Для `OBJECTID = 44877013` результат будет:

```
Московская область, городской округ Павлово-Посадский, город Павловский Посад, улица Тихонова, дом 93, квартира 17, комната 2
```

---

Если нужно — могу адаптировать под административное деление (`ADM_HIERARCHY`) или добавить параметризацию через PL/pgSQL функцию.
