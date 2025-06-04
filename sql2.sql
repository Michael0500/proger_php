WITH active_objects AS (
    SELECT objectid, levelid, objectguid
    FROM reestr_objects
    WHERE isactive = 1
),
     object_paths AS (
         SELECT
             mh.objectid,
             mh.path,
             ro.objectguid
         FROM mun_hierarchy mh
                  JOIN active_objects ro ON mh.objectid = ro.objectid
         WHERE mh.isactive = 1
           AND mh.enddate > CURRENT_DATE
     ),
     flattened_path AS (
         SELECT
             op.objectguid,
             unnest(string_to_array(op.path, '.'))::bigint AS path_element
         FROM object_paths op
     ),
     element_levels AS (
         SELECT objectid, levelid
         FROM reestr_objects
         WHERE isactive = 1
     ),
     address_parts AS (
         SELECT DISTINCT ON (fp.objectguid, r.objectid)
    fp.objectguid,
    r.objectid,
    el.levelid,
    COALESCE(onm.value,
    CASE
    WHEN el.levelid = 10 THEN ht.name || ' ' || h.housenum
    WHEN el.levelid = 11 THEN at.name || ' ' || a.number
    WHEN el.levelid = 12 THEN rt.name || ' ' || rm.number
    WHEN el.levelid = 9 THEN 'Земельный участок ' || s.number
    WHEN el.levelid = 17 THEN 'Машино-место ' || cp.number
    ELSE COALESCE(aot.name || ' ' || ao.name, ao.typename || ' ' || ao.name)
    END
    ) AS full_name
FROM flattened_path fp
    JOIN reestr_objects r ON r.objectid = fp.path_element AND r.isactive = 1
    JOIN element_levels el ON el.objectid = r.objectid
    LEFT JOIN address_objects ao ON ao.objectid = r.objectid AND ao.isactual = 1 AND ao.isactive = 1
    LEFT JOIN address_types aot ON aot.shortname = ao.typename AND aot.level = el.levelid
    LEFT JOIN params onm ON onm.objectid = r.objectid AND onm.typeid = 16 AND onm.enddate > CURRENT_DATE
    LEFT JOIN houses h ON h.objectid = r.objectid AND h.isactual = 1 AND h.isactive = 1
    LEFT JOIN house_types ht ON ht.id = h.housetype
    LEFT JOIN apartments a ON a.objectid = r.objectid AND a.isactual = 1 AND a.isactive = 1
    LEFT JOIN apartment_types at ON at.id = a.aparttype
    LEFT JOIN rooms rm ON rm.objectid = r.objectid AND rm.isactual = 1 AND rm.isactive = 1
    LEFT JOIN room_types rt ON rt.id = rm.roomtype
    LEFT JOIN steads s ON s.objectid = r.objectid AND s.isactual = 1 AND s.isactive = 1
    LEFT JOIN car_places cp ON cp.objectid = r.objectid AND cp.isactual = 1 AND cp.isactive = 1
    ),
    final_addresses AS (
SELECT DISTINCT objectguid, levelid, full_name
FROM address_parts
    )
SELECT
    objectguid,
    string_agg(full_name, ', ' ORDER BY levelid) AS full_address
FROM final_addresses
GROUP BY objectguid
ORDER BY full_address;
