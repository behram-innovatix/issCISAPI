<?php

namespace App\Insights;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * Builds the EDU dashboard dataset from the marts of every EDU source: reference data (schools,
 * mark codes, behaviour types, staff), sections, and students with their attendance and behaviour
 * for the reporting window, in the compact shape the CIS web app analyses.
 *
 * Attendance per student is a string with one character per session (week × 5 days × AM/PM):
 * chr(48 + i) where i indexes `mark_codes`; index 0 means no mark for that session.
 */
class EduInsights
{
    /** Sunday to Thursday. */
    public const SCHOOL_DAYS = 5;

    public const SESSIONS_PER_WEEK = self::SCHOOL_DAYS * 2;

    private const CATEGORY_ORDER = ['present' => 0, 'late' => 1, 'auth' => 2, 'unauth' => 3, 'none' => 4];

    /**
     * @param  list<int>  $sourceIds
     * @return array<string, mixed>
     */
    public function build(array $sourceIds): array
    {
        if (! DB::table('mart_edu_students')->whereIn('data_source_id', $sourceIds)->exists()) {
            return ['connected' => false, 'reason' => 'No student records have been synced yet.'];
        }

        $calendar = $this->calendar($sourceIds);
        $codes = $this->markCodes($sourceIds);
        $codeIndex = array_flip(array_column($codes, 'code'));

        $types = DB::table('mart_edu_behaviour_types')->whereIn('data_source_id', $sourceIds)->get(['type_id', 'name', 'polarity']);
        $typeById = $types->keyBy('type_id');
        $behaviourTypes = [];
        $typeIndex = [];

        $students = [];
        $studentIndex = [];
        $rows = DB::table('mart_edu_students')->whereIn('data_source_id', $sourceIds)
            ->orderBy('school')->orderBy('year_group')->orderBy('section')->orderBy('name')
            ->get(['student_id', 'name', 'gender', 'school', 'year_group', 'stage', 'section', 'section_key', 'house', 'nationality']);
        $emptySessions = str_repeat(chr(48), $calendar['weeks'] * self::SESSIONS_PER_WEEK);

        foreach ($rows as $row) {
            if (isset($studentIndex[$row->student_id])) {
                continue;
            }
            $studentIndex[$row->student_id] = count($students);
            $students[] = [
                'id' => $row->student_id,
                'name' => $row->name ?? 'Unnamed student',
                'gender' => $row->gender === 'F' ? 'F' : 'M',
                'school' => $row->school,
                'yg' => $row->year_group,
                'stage' => $row->stage,
                'sec' => $row->section,
                'key' => $row->section_key,
                'house' => $row->house,
                'nat' => $row->nationality,
                's' => $emptySessions,
                'ev' => [],
            ];
        }

        $offsets = $calendar['offsets'];

        $this->stream(
            DB::table('mart_edu_attendance_marks')->whereIn('data_source_id', $sourceIds)
                ->whereBetween('date', [$calendar['start'], $calendar['as_of']])
                ->select(['student_id', 'date', 'session', 'code']),
            function ($mark) use (&$students, &$codes, &$codeIndex, $studentIndex, $offsets) {
                $i = $studentIndex[$mark->student_id] ?? null;
                $offset = $offsets[$mark->date] ?? null;
                if ($i === null || $offset === null) {
                    return;
                }
                if (! isset($codeIndex[$mark->code])) {
                    $codeIndex[$mark->code] = count($codes);
                    $codes[] = ['code' => $mark->code, 'label' => $mark->code, 'category' => 'none'];
                }
                $students[$i]['s'][$offset + (strtoupper((string) $mark->session) === 'PM' ? 1 : 0)] = chr(48 + $codeIndex[$mark->code]);
            });

        $this->stream(
            DB::table('mart_edu_behaviour_events')->whereIn('data_source_id', $sourceIds)
                ->whereBetween('date', [$calendar['start'], $calendar['as_of']])
                ->where('points', '!=', 0)
                ->select(['student_id', 'date', 'type', 'points', 'location', 'recorded_by']),
            function ($event) use (&$students, &$behaviourTypes, &$typeIndex, $studentIndex, $offsets, $typeById) {
                $i = $studentIndex[$event->student_id] ?? null;
                $offset = $offsets[$event->date] ?? null;
                if ($i === null || $offset === null) {
                    return;
                }
                $type = $typeById[$event->type] ?? null;
                $name = $type->name ?? $event->type ?? 'Other';
                if (! isset($typeIndex[$name])) {
                    $typeIndex[$name] = count($behaviourTypes);
                    $behaviourTypes[] = ['n' => $name, 's' => $event->points > 0 ? 1 : -1];
                }
                $students[$i]['ev'][] = [
                    'w' => intdiv($offset, self::SESSIONS_PER_WEEK),
                    'd' => intdiv($offset % self::SESSIONS_PER_WEEK, 2),
                    't' => $typeIndex[$name],
                    'pts' => (int) $event->points,
                    'loc' => $event->location,
                    'by' => $event->recorded_by,
                ];
            });

        // chr(48 + i) stays printable ASCII up to "~"
        if (count($codes) > 78) {
            throw new RuntimeException('Too many distinct attendance codes to encode ('.count($codes).').');
        }

        return [
            'connected' => true,
            'calendar' => array_diff_key($calendar, ['offsets' => true]),
            'schools' => DB::table('mart_edu_schools')->whereIn('data_source_id', $sourceIds)->orderBy('name')->get()
                ->unique('school_id')
                ->map(fn ($s) => ['id' => $s->school_id, 'name' => $s->name, 'short' => $s->short_name ?: $s->name, 'intake' => $s->intake ?: 'Co-educational'])
                ->values()
                ->all(),
            'mark_codes' => $codes,
            'behaviour_types' => $behaviourTypes,
            'staff' => DB::table('mart_edu_staff')->whereIn('data_source_id', $sourceIds)->get(['staff_id', 'name', 'role'])
                ->map(fn ($s) => ['id' => $s->staff_id, 'name' => $s->name, 'role' => $s->role])
                ->values()
                ->all(),
            'sections' => DB::table('mart_edu_sections')->whereIn('data_source_id', $sourceIds)->get()
                ->map(fn ($s) => [
                    'key' => $s->section_key,
                    'school' => $s->school,
                    'yg' => $s->year_group,
                    'stage' => $s->stage,
                    'sec' => $s->section,
                    'cap' => $s->capacity === null ? null : (int) $s->capacity,
                    'formTutorId' => $s->form_tutor_id,
                    'subjectTeacherIds' => json_decode($s->subject_teacher_ids ?? '[]', true) ?: [],
                ])
                ->values()
                ->all(),
            'students' => $students,
        ];
    }

    /**
     * Run a query once and hand each row to $each. On MySQL the result is read unbuffered, so a
     * large window (hundreds of thousands of marks) streams in one pass with constant memory.
     */
    private function stream(Builder $query, callable $each): void
    {
        $connection = $query->getConnection();
        $mysql = $connection->getDriverName() === 'mysql';
        $pdos = $mysql ? [$connection->getPdo(), $connection->getReadPdo()] : [];

        foreach ($pdos as $pdo) {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }

        try {
            foreach ($query->cursor() as $row) {
                $each($row);
            }
        } finally {
            foreach ($pdos as $pdo) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        }
    }

    /**
     * The reporting window: whole school weeks up to the latest mark, at most `max_weeks` of them,
     * plus each school day's session offset.
     *
     * @param  list<int>  $sourceIds
     * @return array{start: string, as_of: string, weeks: int, label: string, offsets: array<string, int>}
     */
    private function calendar(array $sourceIds): array
    {
        $marks = DB::table('mart_edu_attendance_marks')->whereIn('data_source_id', $sourceIds);
        $latest = $marks->clone()->max('date');
        $earliest = $marks->clone()->min('date');

        $asOf = $latest ? CarbonImmutable::parse($latest) : CarbonImmutable::today();
        $lastWeek = $asOf->startOfWeek(CarbonInterface::SUNDAY);
        $start = $lastWeek->subWeeks(max(1, config('insights.edu.max_weeks')) - 1);
        if ($earliest && CarbonImmutable::parse($earliest)->startOfWeek(CarbonInterface::SUNDAY)->greaterThan($start)) {
            $start = CarbonImmutable::parse($earliest)->startOfWeek(CarbonInterface::SUNDAY);
        }
        $weeks = (int) $start->diffInWeeks($lastWeek) + 1;

        $offsets = [];
        for ($w = 0; $w < $weeks; $w++) {
            for ($d = 0; $d < self::SCHOOL_DAYS; $d++) {
                $offsets[$start->addDays($w * 7 + $d)->toDateString()] = $w * self::SESSIONS_PER_WEEK + $d * 2;
            }
        }

        return [
            'start' => $start->toDateString(),
            'as_of' => $asOf->toDateString(),
            'weeks' => $weeks,
            'label' => $start->format('j M').' – '.$asOf->format('j M Y'),
            'offsets' => $offsets,
        ];
    }

    /**
     * Mark codes with their attendance category, index 0 being "no mark".
     *
     * @param  list<int>  $sourceIds
     * @return list<array{code: string, label: string, category: string}>
     */
    private function markCodes(array $sourceIds): array
    {
        $codes = DB::table('mart_edu_mark_codes')->whereIn('data_source_id', $sourceIds)->get(['code', 'label', 'category'])
            ->unique('code')
            ->map(fn ($c) => [
                'code' => $c->code,
                'label' => $c->label ?: $c->code,
                'category' => isset(self::CATEGORY_ORDER[$c->category]) ? $c->category : 'none',
            ])
            ->sortBy([fn ($a, $b) => self::CATEGORY_ORDER[$a['category']] <=> self::CATEGORY_ORDER[$b['category']], ['code', 'asc']])
            ->values()
            ->all();

        return [['code' => '', 'label' => 'No mark', 'category' => 'none'], ...$codes];
    }
}
