-- Students with an active enrolment in a section of the academic year.
SELECT
    stu.uid AS id,
    LTRIM(RTRIM(CONCAT(p.first_name, ' ', p.last_name))) AS name,
    CASE p.sex WHEN 1 THEN 'F' ELSE 'M' END AS gender,
    s.school_code AS school,
    CASE
        WHEN yg.year_group_code IN ('NR', 'NUR', 'N', 'Nursery') THEN 'NR'
        WHEN yg.year_group_code IN ('RE', 'R', 'Rc', 'REC', 'Reception') THEN 'RE'
        WHEN yg.year_group_code NOT LIKE '%[^0-9]%' AND LEN(yg.year_group_code) BETWEEN 1 AND 2 THEN RIGHT('0' + yg.year_group_code, 2)
        ELSE yg.year_group_code
    END AS year_group,
    st.educational_stage AS stage,
    sec.section AS section,
    sec.uid AS section_key,
    (
        SELECT TOP 1 h.house_name
        FROM tbl_house_students hs
        JOIN tbl_houses h ON h.uid = hs.house_uid
        WHERE hs.student_uid = stu.uid AND hs.deleted_at IS NULL
        ORDER BY hs.created_at DESC
    ) AS house,
    n.nationality AS nationality,
    (SELECT MAX(v) FROM (VALUES (stu.updated_at), (e.updated_at), (p.updated_at)) AS changed(v)) AS updated_at
FROM tbl_enrollments e
JOIN tbl_students stu ON stu.uid = e.student_uid
JOIN tbl_profiles p ON p.uid = stu.profile_uid
JOIN tbl_sections sec ON sec.uid = e.section_uid
JOIN tbl_year_groups yg ON yg.uid = sec.year_group_uid
JOIN tbl_educational_stages st ON st.uid = yg.educational_stage_uid
JOIN tbl_academic_years y ON y.uid = st.academic_year_uid
JOIN tbl_schools s ON s.uid = y.school_uid
LEFT JOIN tbl_nationalities n ON n.uid = p.nationality_uid
WHERE y.academic_year = :academic_year
    AND e.is_active = 1 AND e.deleted_at IS NULL AND stu.deleted_at IS NULL AND sec.deleted_at IS NULL
