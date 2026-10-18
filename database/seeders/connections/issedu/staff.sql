-- Staff names, for form tutors and who recorded behaviour.
SELECT
    st.uid AS id,
    LTRIM(RTRIM(CONCAT(st.first_name, ' ', st.last_name))) AS name,
    NULL AS school,
    d.designation AS role,
    st.updated_at
FROM tbl_staff st
LEFT JOIN tbl_designations d ON d.uid = st.designation_uid
WHERE st.deleted_at IS NULL
