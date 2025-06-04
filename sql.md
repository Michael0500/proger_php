WITH input_guid AS (
SELECT '201868ae-0db3-4c1e-bb09-8697df617a98' AS guid
),
reestr AS (
SELECT objectid, levelid, objectguid
FROM reestr_objects
WHERE objectguid = (SELECT guid FROM input_guid) AND isactive = 1
),
mun_path AS (
SELECT unnest(string_to_array(path, '.'))::bigint AS objectid
FROM mun_hierarchy
WHERE objectid = (SELECT objectid FROM reestr)
AND isactive = 1
AND enddate > CURRENT_DATE

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
             r.levelid AS lvl_id,
             COALESCE(onm.value,
                      CASE
                          WHEN r.levelid = 10 THEN ht.name || ' ' || h.housenum
                          WHEN r.levelid = 11 THEN at.name || ' ' || a.number
                          WHEN r.levelid = 12 THEN rt.name || ' ' || ro.number
                          WHEN r.levelid = 9 THEN 'Земельный участок ' || s.number
                          WHEN r.levelid = 17 THEN 'Машино-место ' || cp.number
                          ELSE COALESCE(aot.name || ' ' || ao.name, ao.typename || ' ' || ao.name)
                          END
             ) AS full_name,
             r.levelid
         FROM mun_path p
                  LEFT JOIN reestr_objects r ON r.objectid = p.objectid AND r.isactive = 1
                  LEFT JOIN address_objects ao ON ao.objectid = r.objectid AND ao.isactual = 1 AND ao.isactive = 1
                  LEFT JOIN address_types aot ON aot.shortname = ao.typename AND aot.level = r.levelid
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
SELECT string_agg(full_name, ', ' ORDER BY lvl_id) AS full_address
FROM address_parts;