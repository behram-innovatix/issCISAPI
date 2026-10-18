-- Register mark codes and how they count. A code used with several meanings takes the strongest:
-- late, then present (incl. approved educational activity), authorised, unauthorised, then
-- "none" (attendance not required, no mark), which is left out of attendance.
SELECT
    m.mark_type AS id,
    MIN(m.description) AS label,
    CASE MIN(CASE
            WHEN m.category = 'late' THEN 1
            WHEN m.statistical_meaning IN ('Present', 'Approved Educational Activity') THEN 2
            WHEN m.statistical_meaning = 'Authorised Absence' THEN 3
            WHEN m.statistical_meaning = 'Unauthorised Absence' THEN 4
            ELSE 5 END)
        WHEN 1 THEN 'late' WHEN 2 THEN 'present' WHEN 3 THEN 'auth' WHEN 4 THEN 'unauth' ELSE 'none' END AS category,
    MAX(m._cis_synced_at) AS updated_at
FROM {{tbl_mark_types}} m
WHERE m.deleted_at IS NULL AND m.mark_type IS NOT NULL
GROUP BY m.mark_type
