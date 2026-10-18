-- Behaviour records per student. Points are signed: positive severities add, negative ones subtract.
SELECT
    CONCAT(b.uid, ':', bs.student_uid) AS id,
    bs.student_uid AS student_id,
    DATE(b.datetime) AS date,
    b.behaviour_type_uid AS type,
    (CASE sv.type WHEN 'positive' THEN 1 WHEN 'negative' THEN -1 ELSE 0 END) * COALESCE(sv.points, 0) AS points,
    r.room AS location,
    b.recorded_by AS recorded_by,
    GREATEST(b._cis_synced_at, bs._cis_synced_at) AS updated_at
FROM {{tbl_behaviours}} b
JOIN {{tbl_behaviour_students}} bs ON bs.behaviour_uid = b.uid
LEFT JOIN {{tbl_behaviour_severities}} sv ON sv.uid = b.behaviour_severity_uid
LEFT JOIN {{tbl_rooms}} r ON r.uid = b.room_uid
WHERE b.deleted_at IS NULL AND bs.deleted_at IS NULL
