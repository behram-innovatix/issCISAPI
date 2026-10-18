<?php

/*
|--------------------------------------------------------------------------
| Canonical streams (the cis-export contract)
|--------------------------------------------------------------------------
|
| Every platform the CIS reports on, the record streams its sources provide,
| and the mart table each stream is built into. Whatever the connector (API,
| SQL query, spreadsheet, push), a source returns records in exactly this
| shape: an `id` that is unique within the stream, the fields below, and
| (for incremental syncs) an `updated_at` timestamp.
|
| IDs are strings, so sources can use their own keys (GUIDs, codes, numbers).
|
| `key` is the mart column that holds the record `id`. Columns are written as
| "cast" or "cast:payload_field" when the payload field has another name.
| Casts: string, int, bool, money (14,2), decimal (12,3), date, month (YYYY-MM)
| and json. `identity` registers the key in entity_xref under that kind.
| `required` streams must be present for the platform's dashboards to load.
|
| Adding a stream: add it here and add a migration that calls
| MartSchema::create('<platform>.<stream>'). Changing the columns of an
| existing stream needs a new migration that alters its mart table.
|
*/

return [

    'edu' => [
        // reference data
        'schools' => [
            'key' => 'school_id:string',
            'columns' => [
                'name' => 'string',
                'short_name' => 'string',
                'intake' => 'string',
            ],
        ],
        'mark_codes' => [
            'key' => 'code:string',
            'columns' => [
                'label' => 'string',
                // present, late, auth, unauth, or none (attendance not required / no mark)
                'category' => 'string',
            ],
        ],
        'behaviour_types' => [
            'key' => 'type_id:string',
            'columns' => [
                'name' => 'string',
                'polarity' => 'string',
            ],
        ],
        'staff' => [
            'key' => 'staff_id:string',
            'columns' => [
                'name' => 'string',
                'school' => 'string',
                'role' => 'string',
            ],
        ],

        // records
        'sections' => [
            'key' => 'section_key:string',
            'columns' => [
                'school' => 'string',
                'year_group' => 'string',
                'stage' => 'string',
                'section' => 'string',
                'capacity' => 'int',
                'form_tutor_id' => 'string',
                'subject_teacher_ids' => 'json',
            ],
            'indexes' => [['school']],
        ],
        'students' => [
            'key' => 'student_id:string',
            'identity' => 'student',
            'columns' => [
                'name' => 'string',
                'gender' => 'string',
                'school' => 'string',
                'year_group' => 'string',
                'stage' => 'string',
                'section' => 'string',
                'section_key' => 'string',
                'house' => 'string',
                'nationality' => 'string',
            ],
            'indexes' => [['school', 'year_group'], ['section_key']],
        ],
        'attendance_marks' => [
            'key' => 'mark_key:string',
            'columns' => [
                'student_id' => 'string',
                'date' => 'date',
                'session' => 'string',
                'code' => 'string',
            ],
            // covering index: the dashboards read a date window of (student, session, code)
            'indexes' => [['date', 'student_id', 'session', 'code'], ['student_id', 'date']],
        ],
        'behaviour_events' => [
            'key' => 'event_key:string',
            'columns' => [
                'student_id' => 'string',
                'date' => 'date',
                'type' => 'string',
                'points' => 'int',
                'location' => 'string',
                'recorded_by' => 'string',
            ],
            'indexes' => [['student_id'], ['date']],
        ],
    ],

    'hr' => [
        'employees' => [
            'key' => 'employee_id:string',
            'identity' => 'employee',
            'columns' => [
                'name' => 'string',
                'gender' => 'string',
                'location' => 'string',
                'department' => 'string',
                'role' => 'string',
                'teaching' => 'bool',
                'grade' => 'string',
                'nationality' => 'string',
                'hire_date' => 'date',
                'manager_id' => 'string',
                'entitlement_annual' => 'decimal',
                'entitlement_sick' => 'decimal',
                'section_keys' => 'json',
                'form_section' => 'string',
            ],
            'indexes' => [['location', 'department']],
        ],
        'staff_attendance' => [
            'key' => 'attendance_key:string',
            'columns' => [
                'employee_id' => 'string',
                'date' => 'date',
                'code' => 'string',
                'late_minutes' => 'int',
            ],
            'indexes' => [['employee_id', 'date'], ['date']],
        ],
        'leave_taken' => [
            'key' => 'leave_taken_key:string',
            'columns' => [
                'employee_id' => 'string',
                'month' => 'month',
                'type' => 'string',
                'days' => 'decimal',
            ],
            'indexes' => [['employee_id'], ['month']],
        ],
        'leave_requests' => [
            'key' => 'leave_request_key:string',
            'columns' => [
                'employee_id' => 'string',
                'type' => 'string',
                'starts_on' => 'date:from',
                'days' => 'decimal',
                'status' => 'string',
            ],
            'indexes' => [['employee_id'], ['starts_on']],
        ],
        'appraisals' => [
            'key' => 'appraisal_key:string',
            'columns' => [
                'employee_id' => 'string',
                'cycle' => 'string',
                'label' => 'string',
                'status' => 'string',
                'scores' => 'json',
                'overall' => 'decimal',
                'reviewer_id' => 'string',
            ],
            'indexes' => [['employee_id'], ['cycle']],
        ],
        'kpis' => [
            'key' => 'kpi_key:string',
            'columns' => [
                'employee_id' => 'string',
                'name' => 'string',
                'target' => 'decimal',
                'actual' => 'decimal',
                'unit' => 'string',
            ],
            'indexes' => [['employee_id']],
        ],
    ],

    'fin' => [
        'fee_accounts' => [
            'key' => 'account_id:string',
            'columns' => [
                'student_id' => 'string',
                'name' => 'string',
                'school' => 'string',
                'stage' => 'string',
                'year_group' => 'string',
                'section' => 'string',
                'transport' => 'bool',
                'discount' => 'string',
                'profile' => 'string',
            ],
            'indexes' => [['student_id'], ['school', 'stage']],
        ],
        'invoices' => [
            'key' => 'invoice_key:string',
            'columns' => [
                'account_id' => 'string',
                'term' => 'string',
                'issued' => 'date',
                'tuition' => 'money',
                'resources' => 'money',
                'transport' => 'money',
                'discount' => 'money',
                'total' => 'money',
                'instalments' => 'json',
            ],
            'indexes' => [['account_id'], ['term']],
        ],
        'payments' => [
            'key' => 'payment_key:string',
            'columns' => [
                'invoice_key' => 'string:invoice_id',
                'account_id' => 'string',
                'date' => 'date',
                'amount' => 'money',
                'method' => 'string',
            ],
            'indexes' => [['invoice_key'], ['account_id'], ['date']],
        ],
        'payroll_employees' => [
            'key' => 'employee_id:string',
            'columns' => [
                'name' => 'string',
                'role' => 'string',
                'location' => 'string',
                'department' => 'string',
                'teaching' => 'bool',
                'grade' => 'string',
                'hire_date' => 'date',
                'basic' => 'money',
                'housing' => 'money',
                'transport' => 'money',
            ],
            'indexes' => [['location', 'department']],
        ],
        'payroll_monthly' => [
            'key' => 'payroll_key:string',
            'columns' => [
                'employee_id' => 'string',
                'month' => 'month',
                'gross' => 'money',
            ],
            'indexes' => [['employee_id'], ['month']],
        ],
        'budgets' => [
            'key' => 'budget_key:string',
            'columns' => [
                'location' => 'string',
                'category' => 'string',
                'annual' => 'money',
                'months' => 'json',
            ],
            'indexes' => [['location', 'category']],
        ],
        'budget_actuals' => [
            'key' => 'actual_key:string',
            'columns' => [
                'location' => 'string',
                'category' => 'string',
                'month' => 'month',
                'amount' => 'money',
            ],
            'indexes' => [['location', 'category'], ['month']],
        ],
        'income' => [
            'key' => 'income_key:string',
            'columns' => [
                'location' => 'string',
                'stream' => 'string',
                'month' => 'month',
                'amount' => 'money',
            ],
            'indexes' => [['location'], ['month']],
        ],
        'cash_balances' => [
            'key' => 'balance_key:string',
            'columns' => [
                'date' => 'date',
                'amount' => 'money',
            ],
        ],
    ],

];
