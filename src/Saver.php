<?php

namespace KareliaLabs\Spreadsheet;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection as LaravelCollection;
use Illuminate\Validation\ValidationException;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;

/**
 * Saves the cells changed in the grid, one entry at a time, through the
 * same steps as the entry publish form: permissions, blueprint
 * validation, fieldtype processing, revisions and last-modified.
 */
class Saver
{
    public function __construct(private LaravelCollection $columns, private User $user)
    {
    }

    /**
     * @return array{ok: bool, errors: array<string, string[]>, note: ?string}
     */
    public function save(EntryContract $entry, array $values): array
    {
        $collection = $entry->collection();

        if (! $this->user->can('edit', $entry)) {
            return $this->fail('_row', __('You are not allowed to edit this entry.'));
        }

        // Work on a copy, so a failed save leaves the cached entry untouched.
        $entry = clone $entry->fromWorkingCopy();
        $blueprint = $entry->blueprint();
        $hasOrigin = $entry->hasOrigin();
        $errors = [];

        $values = collect($values)->only($this->columns->keys())->all();

        foreach (array_keys($values) as $handle) {
            $column = $this->columns[$handle];

            if (($column['readonly'] ?? false) || $column['kind'] === 'readonly') {
                $errors[$handle][] = __('This field cannot be edited in the spreadsheet.');

                continue;
            }

            if (! ($column['builtin'] ?? false)) {
                $field = $blueprint->field($handle);

                if (! $field) {
                    $errors[$handle][] = __('This entry\'s blueprint does not have this field.');
                } elseif ($hasOrigin && ! $field->isLocalizable()) {
                    $errors[$handle][] = __('This field is shared by all sites. Change it in the original.');
                }
            }
        }

        if (array_key_exists('published', $values) && ! $this->user->can('publish', $entry)) {
            $errors['published'][] = __('You are not allowed to publish or unpublish this entry.');
        }

        if (array_key_exists('author', $values) && $this->user->cant('edit-other-authors-entries', [EntryContract::class, $collection, $blueprint])) {
            $errors['author'][] = __('You are not allowed to change the author.');
        }

        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'note' => null];
        }

        $published = Arr::pull($values, 'published');
        $hasPublished = $published !== null;
        $handles = array_keys($values);

        $blueprint->ensureField('title', ['type' => 'text']);

        if ($collection->requiresSlugs()) {
            $blueprint->ensureField('slug', ['type' => 'slug']);
        }

        if ($collection->dated()) {
            $blueprint->ensureField('date', ['type' => 'date']);
        }

        $fields = $blueprint->fields()->only($handles)->addValues($values);

        try {
            $fields->validator()
                ->withRules(Arr::only(Entry::updateRules($collection, $entry), $handles))
                ->withReplacements([
                    'id' => $entry->id(),
                    'collection' => $collection->handle(),
                    'site' => $entry->locale(),
                ])
                ->validate();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $errors[explode('.', $key)[0]] = array_merge($errors[explode('.', $key)[0]] ?? [], $messages);
            }

            return ['ok' => false, 'errors' => $errors, 'note' => null];
        }

        $processed = $fields->process()->values();

        if ($processed->has('slug')) {
            $entry->slug($processed->pull('slug'));
        }

        if ($processed->has('date')) {
            $date = $processed->pull('date');

            if ($collection->dated()) {
                $entry->date($date ? $blueprint->field('date')->fieldtype()->augment($date) : null);
            }
        }

        foreach ($processed as $handle => $value) {
            if ($value === null || $value === '' || $value === []) {
                $entry->remove($handle);
            } else {
                $entry->set($handle, $value);
            }
        }

        if ($hasPublished) {
            $entry->published((bool) $published);
        }

        if ($clash = $this->uriClash($entry)) {
            return $this->fail(array_key_exists('slug', $values) ? 'slug' : '_row', __('Another entry already uses the URL :uri.', ['uri' => $clash]));
        }

        $note = null;

        if ($entry->revisionsEnabled() && $entry->published() && ! $hasPublished) {
            $entry->makeWorkingCopy()->user($this->user)->save();
            $note = __('Saved as a working copy. Publish it from the entry page.');
        } else {
            $entry->updateLastModified($this->user)->save();
        }

        return ['ok' => true, 'errors' => [], 'note' => $note];
    }

    private function uriClash(EntryContract $entry): ?string
    {
        // The URI is cached per entry, so drop it to see the new slug.
        Blink::store('entry-uris')->forget($entry->id());

        $uri = $entry->uri();

        if (! $uri) {
            return null;
        }

        // Drafts are not always found by URI, so also check entries in the same
        // collection and site with the same slug.
        $sameSlug = Entry::query()
            ->where('collection', $entry->collectionHandle())
            ->where('site', $entry->locale())
            ->where('slug', $entry->slug())
            ->get()
            ->first(fn ($other) => (string) $other->id() !== (string) $entry->id() && $other->uri() === $uri);

        if ($sameSlug) {
            return $uri;
        }

        $other = Entry::findByUri($uri, $entry->locale());

        return $other && (string) $other->id() !== (string) $entry->id() ? $uri : null;
    }

    private function fail(string $key, string $message): array
    {
        return ['ok' => false, 'errors' => [$key => [$message]], 'note' => null];
    }
}
