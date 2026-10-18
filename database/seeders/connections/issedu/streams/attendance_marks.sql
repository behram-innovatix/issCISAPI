-- The daily AM / PM register marks (the copy already holds only those, for the school year).
SELECT
    CONCAT(a.student_uid, ':', a.date, ':', a.session_type) AS id,
    a.student_uid AS student_id,
    a.date AS date,
    a.session_type AS session,
    m.mark_type AS code,
    a._cis_synced_at AS updated_at
FROM {{tbl_school_student_attendances}} a
JOIN {{tbl_mark_types}} m ON m.uid = a.mark_type_uid
WHERE a.deleted_at IS NULL
