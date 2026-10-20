<?php

namespace KareliaLabs\Spreadsheet;

use Illuminate\Support\Collection as LaravelCollection;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Asset;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\Term;
use Statamic\Facades\User as Users;

/**
 * Turns entries into grid rows. Values are in the same shape the
 * Control Panel's publish form uses (the fieldtype's preProcess output),
 * so saving can go through the same validation and processing.
 */
class Rows
{
    /** @var array<string, array<string, true>> ids per relationship kind */
    private array $referenced = [];

    public function __construct(private LaravelCollection $columns, private User $user)
    {
    }

    public function row(Entry $entry): array
    {
        $blueprint = $entry->blueprint();
        $hasOrigin = $entry->hasOrigin();
        $locked = [];

        $row = [
            '_id' => (string) $entry->id(),
            '_blueprint' => $blueprint?->handle(),
            '_edit_url' => $entry->editUrl(),
            '_url' => $entry->absoluteUrl(),
            '_status' => $entry->status(),
            '_has_origin' => $hasOrigin,
            '_can_edit' => $this->user->can('edit', $entry),
            '_can_publish' => $this->user->can('publish', $entry),
            '_updated_at' => $entry->lastModified()?->getTimestamp(),
        ];

        foreach ($this->columns as $handle => $column) {
            if ($column['builtin'] ?? false) {
                $row[$handle] = match ($handle) {
                    'title' => $entry->value('title'),
                    'slug' => $entry->slug(),
                    'published' => (bool) $entry->published(),
                    'date' => $entry->hasDate() ? $entry->date()?->format($column['time'] ? 'Y-m-d H:i' : 'Y-m-d') : null,
                };

                continue;
            }

            $field = $blueprint?->field($handle);

            if (! $field) {
                $row[$handle] = null;
                $locked[] = $handle;

                continue;
            }

            if ($hasOrigin && ! $field->isLocalizable()) {
                $locked[] = $handle;
            }

            $raw = $hasOrigin ? $entry->value($handle) : $entry->get($handle);
            $row[$handle] = $this->uiValue($field, $raw, $column);
        }

        $row['_locked'] = $locked;

        return $row;
    }

    public function uiValue($field, $raw, array $column)
    {
        if ($column['kind'] === 'readonly') {
            return $this->summary($raw);
        }

        if ($column['kind'] === 'date') {
            return $raw instanceof \DateTimeInterface ? $raw->format($column['time'] ? 'Y-m-d H:i' : 'Y-m-d') : $raw;
        }

        try {
            $value = $field->newInstance()->setValue($raw)->preProcess()->value();
        } catch (\Throwable) {
            $value = $raw;
        }

        if ($column['kind'] === 'relationship') {
            $value = array_values(array_filter((array) $value, fn ($v) => $v !== null && $v !== ''));

            foreach ($value as $id) {
                $this->referenced[$column['relationship']][(string) $id] = true;
            }
        }

        if ($column['kind'] === 'options' && ($column['multiple'] ?? false)) {
            $value = array_values((array) ($value ?? []));
        }

        return $value;
    }

    /**
     * Titles (and thumbnails) for every related item used by the rows built so far.
     *
     * @return array<string, array<string, array{title: string, thumb?: string|null}>>
     */
    public function labels(): array
    {
        $labels = [];

        foreach ($this->referenced as $kind => $ids) {
            $labels[$kind] = static::describe($kind, array_keys($ids));
        }

        return $labels;
    }

    public static function describe(string $kind, array $ids): array
    {
        $out = [];

        if ($kind === 'entries' && $ids) {
            foreach (Entries::query()->whereIn('id', $ids)->get() as $entry) {
                $out[(string) $entry->id()] = ['title' => (string) ($entry->value('title') ?? $entry->slug())];
            }
        }

        foreach ($ids as $id) {
            if (isset($out[$id])) {
                continue;
            }

            $out[$id] = match ($kind) {
                'terms' => ($term = Term::find($id)) ? ['title' => (string) $term->title()] : ['title' => (string) $id, 'missing' => true],
                'users' => ($u = Users::find($id)) ? ['title' => (string) ($u->name() ?: $u->email())] : ['title' => (string) $id, 'missing' => true],
                'assets' => static::assetLabel($id),
                default => ['title' => (string) $id, 'missing' => true],
            };
        }

        return $out;
    }

    private static function assetLabel(string $id): array
    {
        $asset = Asset::find($id);

        if (! $asset) {
            return ['title' => $id, 'missing' => true];
        }

        return [
            'title' => $asset->basename(),
            'thumb' => $asset->isImage() ? $asset->thumbnailUrl('small') : null,
        ];
    }

    /** Short text for fields the grid cannot edit (Bard, Replicator, Grid and so on). */
    private function summary($raw): ?string
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        if (is_scalar($raw)) {
            return mb_strimwidth(strip_tags((string) $raw), 0, 120, '…');
        }

        if (is_array($raw) && array_is_list($raw)) {
            return trans_choice(':count item|:count items', count($raw), ['count' => count($raw)]);
        }

        return '…';
    }
}
