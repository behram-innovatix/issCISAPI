<?php

/*
| The issEduApi tables the seeded EDU connection copies: only the columns the dashboards use
| (no national ids or contact details), and only the current school year of the large tables.
| :from_date comes from the connection's parameters. Each table is keyed on uid and synced
| incrementally on updated_at.
*/

$stamps = ['updated_at', 'deleted_at'];

return [
    'tbl_schools' => ['columns' => ['uid', 'school_code', 'school_name', 'school_intake_sex', ...$stamps]],
    'tbl_mark_types' => ['columns' => ['uid', 'mark_type', 'description', 'statistical_meaning', 'category', ...$stamps]],
    'tbl_behaviour_types' => ['columns' => ['uid', 'behaviour_type', ...$stamps]],
    'tbl_behaviour_severities' => ['columns' => ['uid', 'type', 'points', ...$stamps]],
    'tbl_behaviour_type_severities' => ['columns' => ['uid', 'behaviour_type_uid', 'behaviour_severity_uid', ...$stamps]],
    'tbl_staff' => ['columns' => ['uid', 'first_name', 'last_name', 'designation_uid', ...$stamps]],
    'tbl_designations' => ['columns' => ['uid', 'designation', ...$stamps]],
    'tbl_academic_years' => ['columns' => ['uid', 'academic_year', 'school_uid', 'start_date', 'end_date', 'status', ...$stamps]],
    'tbl_educational_stages' => ['columns' => ['uid', 'academic_year_uid', 'educational_stage', ...$stamps]],
    'tbl_year_groups' => ['columns' => ['uid', 'educational_stage_uid', 'year_group_code', ...$stamps]],
    'tbl_sections' => ['columns' => ['uid', 'year_group_uid', 'homeroom_teacher_uid', 'section', ...$stamps]],
    'tbl_enrollments' => ['columns' => ['uid', 'student_uid', 'section_uid', 'is_active', 'status', ...$stamps]],
    'tbl_students' => ['columns' => ['uid', 'school_uid', 'profile_uid', 'is_active', ...$stamps]],
    'tbl_profiles' => [
        'columns' => ['uid', 'first_name', 'last_name', 'sex', 'nationality_uid', ...$stamps],
        'filter' => 'uid IN (SELECT profile_uid FROM tbl_students)',
    ],
    'tbl_nationalities' => ['columns' => ['uid', 'nationality', ...$stamps]],
    'tbl_houses' => ['columns' => ['uid', 'house_name', ...$stamps]],
    'tbl_house_students' => ['columns' => ['uid', 'house_uid', 'student_uid', 'created_at', ...$stamps]],
    'tbl_rooms' => ['columns' => ['uid', 'room', ...$stamps]],
    'tbl_school_student_attendances' => [
        'columns' => ['uid', 'student_uid', 'mark_type_uid', 'date', 'session_type', ...$stamps],
        'filter' => "session_type IN ('AM', 'PM') AND date >= :from_date",
    ],
    'tbl_behaviours' => [
        'columns' => ['uid', 'behaviour_type_uid', 'behaviour_severity_uid', 'room_uid', 'recorded_by', 'datetime', ...$stamps],
        'filter' => 'datetime >= :from_date',
    ],
    'tbl_behaviour_students' => [
        'columns' => ['uid', 'behaviour_uid', 'student_uid', ...$stamps],
        'filter' => 'behaviour_uid IN (SELECT uid FROM tbl_behaviours WHERE datetime >= :from_date)',
    ],
];
