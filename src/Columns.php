<?php

namespace KareliaLabs\Spreadsheet;

use Illuminate\Support\Collection as LaravelCollection;
use Statamic\Contracts\Entries\Collection;
use Statamic\Fields\Field;

/**
 * Works out the spreadsheet columns for a collection from all of its
 * blueprints. A field that exists in several blueprints becomes one column.
 */
class Columns
{
    /** Fieldtypes edited as plain text in a cell. */
    public const TEXT = ['text', 'textarea', 'markdown', 'slug', 'color', 'time', 'video', 'template', 'code', 'html_text'];

    public const NUMBER = ['integer', 'float', 'range'];

    public const OPTIONS = ['select', 'radio', 'button_group', 'checkboxes'];

    public const RELATIONSHIP = ['entries', 'terms', 'taxonomies', 'users', 'assets'];

    /** Fieldtypes with nothing to show or edit. */
    public const SKIP = ['section', 'spacer', 'revealer', 'info', 'html'];

    public function __construct(private Collection $collection)
    {
    }

    /** @return LaravelCollection<string, array> keyed by field handle */
    public function all(): LaravelCollection
    {
        $columns = collect([
            'title' => $this->builtIn('title', __('Title'), 'text', pinned: true),
        ]);

        if ($this->collection->requiresSlugs()) {
            $columns->put('slug', $this->builtIn('slug', __('Slug'), 'text'));
        }

        $columns->put('published', $this->builtIn('published', __('Published'), 'toggle'));

        if ($this->collection->dated()) {
            $columns->put('date', $this->builtIn('date', __('Date'), 'date'));
        }

        foreach ($this->collection->entryBlueprints() as $blueprint) {
            foreach ($blueprint->fields()->all() as $handle => $field) {
                if (in_array($handle, ['title', 'slug', 'date'], true)) {
                    continue;
                }

                if ($columns->has($handle)) {
                    $columns[$handle] = array_merge($columns[$handle], [
                        'blueprints' => array_values(array_unique([...$columns[$handle]['blueprints'], $blueprint->handle()])),
                    ]);

                    continue;
                }

                if ($column = $this->fromField($field, $blueprint->handle())) {
                    $columns->put($handle, $column);
                }
            }
        }

        return $columns;
    }

    public function fromField(Field $field, string $blueprint): ?array
    {
        $type = $field->type();

        if (in_array($type, self::SKIP, true) || $field->visibility() === 'hidden') {
            return null;
        }

        $config = $field->config();
        $column = [
            'handle' => $field->handle(),
            'label' => __($field->display()),
            'fieldtype' => $type,
            'kind' => 'readonly',
            'readonly' => in_array($field->visibility(), ['read_only', 'computed'], true),
            'required' => $field->isRequired(),
            'localizable' => $field->isLocalizable(),
            'blueprints' => [$blueprint],
            'instructions' => $field->instructions() ? strip_tags((string) __($field->instructions())) : null,
        ];

        switch (true) {
            case in_array($type, self::TEXT, true):
                $column['kind'] = $type === 'textarea' || $type === 'markdown' || $type === 'code' ? 'longtext' : 'text';
                break;

            case in_array($type, self::NUMBER, true):
                $column['kind'] = 'number';
                $column['integer'] = $type === 'integer';
                break;

            case $type === 'toggle':
                $column['kind'] = 'toggle';
                break;

            case in_array($type, self::OPTIONS, true):
                $column['kind'] = 'options';
                $column['options'] = $this->options($config['options'] ?? []);
                $column['multiple'] = $type === 'checkboxes' || ($type === 'select' && ($config['multiple'] ?? false));
                $column['max'] = $config['max_items'] ?? null;
                break;

            case $type === 'date':
                if (($config['mode'] ?? 'single') === 'range') {
                    break;
                }
                $column['kind'] = 'date';
                $column['time'] = (bool) ($config['time_enabled'] ?? false);
                break;

            case in_array($type, self::RELATIONSHIP, true):
                $column['kind'] = 'relationship';
                $column['relationship'] = $type === 'taxonomies' ? 'terms' : $type;
                $column['max'] = $config['max_items'] ?? null;
                $column['collections'] = $config['collections'] ?? null;
                $column['taxonomies'] = $config['taxonomies'] ?? ($config['taxonomy'] ?? null);
                $column['container'] = $config['container'] ?? null;
                $column['create'] = (bool) ($config['create'] ?? ($type === 'terms'));
                break;
        }

        if ($column['kind'] === 'readonly') {
            $column['readonly'] = true;
        }

        return $column;
    }

    private function builtIn(string $handle, string $label, string $kind, bool $pinned = false): array
    {
        return [
            'handle' => $handle,
            'label' => $label,
            'fieldtype' => $handle,
            'kind' => $kind,
            'readonly' => false,
            'required' => $handle === 'title',
            'localizable' => true,
            'blueprints' => null,
            'pinned' => $pinned,
            'builtin' => true,
            'time' => $handle === 'date' && $this->collection->entryBlueprint()?->field('date')?->get('time_enabled'),
        ];
    }

    private function options($options): array
    {
        if (! is_array($options)) {
            return [];
        }

        // Options can be a list, a value => label map, or a list of {key, value}.
        return collect($options)->map(function ($label, $value) {
            if (is_array($label)) {
                return ['value' => (string) ($label['key'] ?? $label['value'] ?? ''), 'label' => (string) __($label['value'] ?? $label['key'] ?? '')];
            }

            if (is_int($value)) {
                return ['value' => (string) $label, 'label' => (string) __($label)];
            }

            return ['value' => (string) $value, 'label' => (string) __($label ?? $value)];
        })->values()->all();
    }
}
