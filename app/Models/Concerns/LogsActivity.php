<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Logs adds, edits and deletes of this model made by a signed-in person.
 * Changes made by the system itself (pollers, provisioning, queued jobs: no one
 * signed in) are not logged. Only the fields a person sets count (the model's
 * $fillable, minus activityIgnore()), so a status update is never an "edit".
 *
 * A model may define:
 *   activityType(): string     "access point", "router", ...
 *   activityLabel(): string    its name
 *   activityIgnore(): array    fillable fields that are not worth logging
 *   activityValue($field, $v)  a readable value, e.g. a barangay's name for barangay_id
 */
trait LogsActivity
{
    protected static function bootLogsActivity(): void
    {
        static::created(function (Model $m) {
            if (auth()->check()) {
                ActivityLog::record('created', 'Added '.$m->activityTypeName().' '.$m->activityLabelText(), $m->activitySubject(), $m->activityValues(true));
            }
        });

        static::updated(function (Model $m) {
            // Saves right after creating it in the same request (detected details...) are part of "Added"
            if (! auth()->check() || $m->wasRecentlyCreated) {
                return;
            }
            $changes = [];
            foreach ($m->activityFields() as $field) {
                if (! $m->wasChanged($field)) {
                    continue;
                }
                $changes[$field] = ActivityLog::isSecret($field)
                    ? ['(hidden)', '(changed)']
                    : [ActivityLog::show($m->activityReadable($field, $m->getOriginal($field))), ActivityLog::show($m->activityReadable($field, $m->getAttribute($field)))];
            }
            if ($changes) {
                ActivityLog::record('updated', 'Edited '.$m->activityTypeName().' '.$m->activityLabelText(), $m->activitySubject(), $changes);
            }
        });

        static::deleted(function (Model $m) {
            if (auth()->check()) {
                ActivityLog::record('deleted', 'Deleted '.$m->activityTypeName().' '.$m->activityLabelText(), $m->activitySubject(), $m->activityValues(false));
            }
        });
    }

    public function activitySubject(): array
    {
        return ['type' => $this->activityTypeName(), 'id' => $this->getKey(), 'label' => $this->activityLabelText()];
    }

    public function activityTypeName(): string
    {
        return method_exists($this, 'activityType') ? $this->activityType() : strtolower(class_basename($this));
    }

    public function activityLabelText(): string
    {
        return method_exists($this, 'activityLabel') ? (string) $this->activityLabel() : (string) ($this->getAttribute('name') ?? '#'.$this->getKey());
    }

    public function activityReadable(string $field, mixed $value): mixed
    {
        return $value !== null && method_exists($this, 'activityValue') ? $this->activityValue($field, $value) : $value;
    }

    /** @return array<int, string> */
    public function activityFields(): array
    {
        $ignore = method_exists($this, 'activityIgnore') ? $this->activityIgnore() : [];

        return array_values(array_diff($this->getFillable(), $ignore));
    }

    /** What it was when added or deleted, as {field: [old, new]} (secrets left out). */
    private function activityValues(bool $created): array
    {
        $out = [];
        foreach ($this->activityFields() as $field) {
            $v = $this->getAttribute($field);
            if ($v === null || $v === '' || $v === [] || ActivityLog::isSecret($field)) {
                continue;
            }
            $v = ActivityLog::show($this->activityReadable($field, $v));
            $out[$field] = $created ? [null, $v] : [$v, null];
        }

        return $out;
    }
}
