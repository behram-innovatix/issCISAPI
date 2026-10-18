-- Students with an active enrolment in a section of the academic year.
SELECT
    stu.uid AS id,
    TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))) AS name,
    CASE p.sex WHEN 1 THEN 'F' ELSE 'M' END AS gender,
    s.school_code AS school,
    CASE
        WHEN yg.year_group_code IN ('NR', 'NUR', 'N', 'Nursery') THEN 'NR'
        WHEN yg.year_group_code IN ('RE', 'R', 'Rc', 'REC', 'Reception') THEN 'RE'
        WHEN yg.year_group_code REGEXP '^[0-9]{1,2}$' THEN LPAD(yg.year_group_code, 2, '0')
        ELSE yg.year_group_code
    END AS year_group,
    st.educational_stage AS stage,
    sec.section AS section,
    sec.uid AS section_key,
    (
        SELECT h.house_name
        FROM {{tbl_house_students}} hs
        JOIN {{tbl_houses}} h ON h.uid = hs.house_uid
        WHERE hs.student_uid = stu.uid AND hs.deleted_at IS NULL
        ORDER BY hs.created_at DESC
        LIMIT 1
    ) AS house,
    n.nationality AS nationality,
    GREATEST(stu._cis_synced_at, e._cis_synced_at, p._cis_synced_at, sec._cis_synced_at) AS updated_at
FROM {{tbl_enrollments}} e
JOIN {{tbl_students}} stu ON stu.uid = e.student_uid
JOIN {{tbl_profiles}} p ON p.uid = stu.profile_uid
JOIN {{tbl_sections}} sec ON sec.uid = e.section_uid
JOIN {{tbl_year_groups}} yg ON yg.uid = sec.year_group_uid
JOIN {{tbl_educational_stages}} st ON st.uid = yg.educational_stage_uid
JOIN {{tbl_academic_years}} y ON y.uid = st.academic_year_uid
JOIN {{tbl_schools}} s ON s.uid = y.school_uid
LEFT JOIN {{tbl_nationalities}} n ON n.uid = p.nationality_uid
WHERE y.academic_year = :academic_year
    AND e.is_active = 1 AND e.deleted_at IS NULL AND stu.deleted_at IS NULL AND sec.deleted_at IS NULL
