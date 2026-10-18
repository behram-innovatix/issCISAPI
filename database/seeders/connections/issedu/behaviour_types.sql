-- Behaviour types; polarity comes from the severities the type is used with.
SELECT
    bt.uid AS id,
    bt.behaviour_type AS name,
    COALESCE((
        SELECT TOP 1 sv.type
        FROM tbl_behaviour_type_severities ts
        JOIN tbl_behaviour_severities sv ON sv.uid = ts.behaviour_severity_uid
        WHERE ts.behaviour_type_uid = bt.uid AND ts.deleted_at IS NULL
    ), 'neutral') AS polarity,
    bt.updated_at
FROM tbl_behaviour_types bt
WHERE bt.deleted_at IS NULL
