-- One row per school. intake is Girls, Boys or Co-educational.
SELECT
    s.school_code AS id,
    s.school_name AS name,
    LTRIM(RTRIM(REPLACE(REPLACE(REPLACE(s.school_name, ' Academy For ', ' '), ' Academy for ', ' '), ' Academy', ''))) AS short_name,
    CASE s.school_intake_sex WHEN 'Girls Only' THEN 'Girls' WHEN 'Boys Only' THEN 'Boys' ELSE 'Co-educational' END AS intake,
    s.updated_at
FROM tbl_schools s
WHERE s.deleted_at IS NULL
