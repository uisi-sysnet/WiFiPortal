<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\SystemEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Logs (administrators):
 *   User activity   what people did: adds, edits, deletes, settings, reports, sign-ins (ActivityLog)
 *   Network events  what the network did: devices down and back, capacity, reports sent (SystemEvent)
 */
class LogController extends Controller
{
    public function index(Request $request)
    {
        $tab = $request->query('tab') === 'events' ? 'events' : 'activity';

        if ($tab === 'events') {
            $f = $request->validate([
                'level' => ['nullable', 'in:'.implode(',', array_keys(SystemEvent::LEVELS))],
                'kind' => ['nullable', 'in:'.implode(',', array_keys(SystemEvent::KINDS))],
                'q' => ['nullable', 'string', 'max:100'],
            ]);
            $events = SystemEvent::query()
                ->when($f['level'] ?? null, fn ($q, $v) => $q->where('level', $v))
                ->when($f['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
                ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                    ->whereRaw('lower(title) like ?', ['%'.mb_strtolower($v).'%'])
                    ->orWhereRaw('lower(detail) like ?', ['%'.mb_strtolower($v).'%'])))
                ->latest('id')
                ->paginate(100)->withQueryString();

            return view('logs.index', ['tab' => $tab, 'events' => $events, 'filters' => $f]);
        }

        $f = $this->activityFilters($request);

        return view('logs.index', [
            'tab' => $tab,
            'filters' => $f,
            'activity' => $this->activityQuery($f)->with('user:id,name')->paginate(50)->withQueryString(),
            'people' => User::query()->orderBy('name')->get(['id', 'name']),
            'types' => ActivityLog::query()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type'),
        ]);
    }

    /** User activity as a spreadsheet (follows the filters on the page). Logged too. */
    public function activityCsv(Request $request)
    {
        $f = $this->activityFilters($request);
        $tz = (string) config('hotspot.history.timezone');
        $rows = $this->activityQuery($f);

        ActivityLog::record('generated', 'Exported the user activity log (CSV)', ['type' => 'report', 'label' => 'User activity log'],
            array_map(fn ($v) => [null, $v], array_filter($f)) ?: null);

        return response()->streamDownload(function () use ($rows, $tz) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Log ID', 'Date and time ('.$tz.')', 'User', 'Role', 'Action', 'Type', 'Item', 'Description', 'Changes', 'IP address']);
            $rows->chunk(1000, function ($chunk) use ($out, $tz) {
                foreach ($chunk as $a) {
                    fputcsv($out, [
                        $a->id, $a->created_at->copy()->setTimezone($tz)->format('Y-m-d H:i:s'), $a->user_name,
                        User::ROLES[$a->user_role]['label'] ?? $a->user_role, $a->actionLabel(), $a->subject_type, $a->subject_label, $a->description,
                        collect($a->changes ?? [])->map(fn ($c, $field) => $field.': '.self::text($c[0] ?? null).' -> '.self::text($c[1] ?? null))->join('; '),
                        $a->ip,
                    ]);
                }
            });
            fclose($out);
        }, 'user-activity-log-'.now($tz)->format('Y-m-d-Hi').'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    private function activityFilters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'user' => ['nullable', 'integer'],
            'action' => ['nullable', 'in:'.implode(',', array_keys(ActivityLog::ACTIONS))],
            'type' => ['nullable', 'string', 'max:30'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], ['to.after_or_equal' => 'The end date must be on or after the start date.']);
    }

    private function activityQuery(array $f): Builder
    {
        $tz = (string) config('hotspot.history.timezone');

        return ActivityLog::query()
            ->when($f['user'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v, $tz)->startOfDay()->utc()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', Carbon::parse($v, $tz)->endOfDay()->utc()))
            ->when($f['q'] ?? null, function ($q, $v) {
                $like = '%'.mb_strtolower(trim($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(description) like ?', [$like])
                    ->orWhereRaw('lower(subject_label) like ?', [$like])
                    ->orWhereRaw('lower(user_name) like ?', [$like])
                    ->orWhere('ip', 'like', $like)
                    ->when(ctype_digit(ltrim(trim($v), '#')), fn ($w) => $w->orWhere('id', (int) ltrim(trim($v), '#'))));
            })
            ->latest('id');
    }

    public static function text(mixed $v): string
    {
        return $v === null || $v === '' ? '(empty)' : (is_scalar($v) ? (string) $v : json_encode($v));
    }
}
