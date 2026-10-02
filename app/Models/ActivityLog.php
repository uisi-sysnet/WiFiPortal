<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * One thing a person did in the system (see the migration). Write with
 * ActivityLog::record(); models using Concerns\LogsActivity record their own
 * adds, edits and deletes.
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = [
        'created' => 'Added',
        'updated' => 'Edited',
        'deleted' => 'Deleted',
        'settings' => 'Changed settings',
        'generated' => 'Generated report',
        'sent' => 'Sent',
        'provisioned' => 'Configured router',
        'checked' => 'Checked now',
        'signed_in' => 'Signed in',
        'signed_out' => 'Signed out',
        'sign_in_failed' => 'Failed sign-in',
    ];

    /** Field names whose values are never written to the log. */
    public const SECRET = ['password', 'community', 'v3_auth_password', 'v3_priv_password', 'token', 'secret', 'remember_token'];

    protected $fillable = ['user_id', 'user_name', 'user_role', 'action', 'subject_type', 'subject_id', 'subject_label', 'description', 'changes', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Logs an action by the signed-in user (or $as, e.g. on sign-in). Never breaks the
     * action itself: a failure to log is reported and ignored.
     */
    public static function record(string $action, string $description, array $subject = [], ?array $changes = null, ?User $as = null, ?string $name = null): ?self
    {
        try {
            $user = $as ?? auth()->user();
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

            return static::create([
                'user_id' => $user?->id,
                'user_name' => $name ?? $user?->name,
                'user_role' => $user?->role,
                'action' => $action,
                'subject_type' => $subject['type'] ?? null,
                'subject_id' => $subject['id'] ?? null,
                'subject_label' => isset($subject['label']) ? mb_substr((string) $subject['label'], 0, 190) : null,
                'description' => mb_substr($description, 0, 500),
                'changes' => $changes ?: null,
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Settings saved: logs only what changed, as {setting: [old, new]}. Secrets show
     * as "(changed)". Nothing is logged when nothing changed.
     */
    public static function settings(string $what, array $before, array $after): ?self
    {
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if (self::same($old, $new)) {
                continue;
            }
            $changes[$key] = self::isSecret($key) ? ['(hidden)', '(changed)'] : [self::show($old), self::show($new)];
        }

        return $changes ? self::record('settings', 'Changed '.$what.' settings', ['type' => 'settings', 'label' => $what], $changes) : null;
    }

    public static function isSecret(string $field): bool
    {
        foreach (self::SECRET as $s) {
            if (str_contains(strtolower($field), $s)) {
                return true;
            }
        }

        return false;
    }

    /** A value as it is stored in the log: lists joined, long text summarised. */
    public static function show(mixed $v): mixed
    {
        if (is_bool($v)) {
            return $v ? 'yes' : 'no';
        }
        if (is_array($v)) {
            $v = array_is_list($v) ? implode(', ', array_map(fn ($x) => is_scalar($x) ? (string) $x : json_encode($x), $v)) : json_encode($v);
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d H:i');
        }
        if (is_string($v) && mb_strlen($v) > 200) {
            return '('.number_format(mb_strlen($v)).' characters)';
        }

        return $v;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        $norm = fn ($v) => is_array($v) ? json_encode($v) : (is_bool($v) ? ($v ? '1' : '0') : (string) ($v ?? ''));

        return $norm($a) === $norm($b);
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? ucfirst($this->action);
    }
}
