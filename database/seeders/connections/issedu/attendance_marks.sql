-- The daily AM / PM register marks of the academic year (lesson registers are left out).
SELECT
    CONCAT(a.student_uid, ':', CONVERT(char(10), a.date, 23), ':', a.session_type) AS id,
    a.student_uid AS student_id,
    CONVERT(char(10), a.date, 23) AS date,
    a.session_type AS session,
    m.mark_type AS code,
    a.updated_at
FROM tbl_school_student_attendances a
JOIN tbl_mark_types m ON m.uid = a.mark_type_uid
WHERE a.session_type IN ('AM', 'PM')
    AND a.deleted_at IS NULL
    AND a.date >= (SELECT MIN(y.start_date) FROM tbl_academic_years y WHERE y.academic_year = :academic_year)
