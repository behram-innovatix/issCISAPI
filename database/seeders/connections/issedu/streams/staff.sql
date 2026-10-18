-- Staff names, for form tutors and who recorded behaviour.
SELECT
    st.uid AS id,
    TRIM(CONCAT(COALESCE(st.first_name, ''), ' ', COALESCE(st.last_name, ''))) AS name,
    NULL AS school,
    d.designation AS role,
    GREATEST(st._cis_synced_at, COALESCE(d._cis_synced_at, st._cis_synced_at)) AS updated_at
FROM {{tbl_staff}} st
LEFT JOIN {{tbl_designations}} d ON d.uid = st.designation_uid
WHERE st.deleted_at IS NULL
