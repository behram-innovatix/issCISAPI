-- Sections (classes) of the academic year. Year groups are normalised to NR, RE, 01 … 13.
SELECT
    sec.uid AS id,
    s.school_code AS school,
    CASE
        WHEN yg.year_group_code IN ('NR', 'NUR', 'N', 'Nursery') THEN 'NR'
        WHEN yg.year_group_code IN ('RE', 'R', 'Rc', 'REC', 'Reception') THEN 'RE'
        WHEN yg.year_group_code NOT LIKE '%[^0-9]%' AND LEN(yg.year_group_code) BETWEEN 1 AND 2 THEN RIGHT('0' + yg.year_group_code, 2)
        ELSE yg.year_group_code
    END AS year_group,
    st.educational_stage AS stage,
    sec.section AS section,
    NULL AS capacity,
    sec.homeroom_teacher_uid AS form_tutor_id,
    sec.updated_at
FROM tbl_sections sec
JOIN tbl_year_groups yg ON yg.uid = sec.year_group_uid
JOIN tbl_educational_stages st ON st.uid = yg.educational_stage_uid
JOIN tbl_academic_years y ON y.uid = st.academic_year_uid
JOIN tbl_schools s ON s.uid = y.school_uid
WHERE y.academic_year = :academic_year
    AND sec.deleted_at IS NULL AND yg.deleted_at IS NULL AND st.deleted_at IS NULL
