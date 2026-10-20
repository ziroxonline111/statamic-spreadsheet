<?php

namespace KareliaLabs\Spreadsheet;

use Statamic\Contracts\Auth\User;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User as Users;
use Statamic\Support\Str;

/**
 * Search for the relationship picker: entries, terms, users and assets,
 * limited to what the field allows and what the user may view.
 */
class Lookup
{
    public function __construct(private User $user)
    {
    }

    public function search(array $column, ?string $query, ?string $site, int $limit = 25): array
    {
        $query = trim((string) $query);

        return match ($column['relationship'] ?? null) {
            'entries' => $this->entries((array) ($column['collections'] ?? []), $query, $site, $limit),
            'terms' => $this->terms((array) ($column['taxonomies'] ?? []), $query, $limit),
            'users' => $this->users($query, $limit),
            'assets' => $this->assets($column['container'] ?? null, $query, $limit),
            default => [],
        };
    }

    private function entries(array $collections, string $query, ?string $site, int $limit): array
    {
        $collections = collect($collections ?: Collection::handles()->all())
            ->filter(fn ($handle) => $this->user->can('view', Collection::findByHandle($handle)))
            ->values()
            ->all();

        if (! $collections) {
            return [];
        }

        $builder = Entry::query()->whereIn('collection', $collections)->orderBy('title');

        if ($site) {
            $builder->where('site', $site);
        }

        if ($query !== '') {
            $builder->where('title', 'like', '%'.$query.'%');
        }

        return $builder->limit($limit)->get()->map(fn ($entry) => [
            'id' => (string) $entry->id(),
            'title' => (string) ($entry->value('title') ?? $entry->slug()),
            'hint' => $entry->collection()->title(),
        ])->values()->all();
    }

    private function terms(array $taxonomies, string $query, int $limit): array
    {
        $taxonomies = collect($taxonomies ?: Taxonomy::handles()->all())
            ->filter(fn ($handle) => $this->user->can('view', Taxonomy::findByHandle($handle)))
            ->values()
            ->all();

        if (! $taxonomies) {
            return [];
        }

        $builder = Term::query()->whereIn('taxonomy', $taxonomies)->orderBy('title');

        if ($query !== '') {
            $builder->where('title', 'like', '%'.$query.'%');
        }

        return $builder->limit($limit)->get()->map(fn ($term) => [
            'id' => $term->taxonomyHandle().'::'.$term->slug(),
            'title' => (string) $term->title(),
            'hint' => $term->taxonomy()->title(),
        ])->values()->all();
    }

    private function users(string $query, int $limit): array
    {
        if (! $this->user->can('view users') && ! $this->user->isSuper()) {
            return [];
        }

        $builder = Users::query()->orderBy('email');

        if ($query !== '') {
            $builder->where('email', 'like', '%'.$query.'%');
        }

        return $builder->limit($limit)->get()->map(fn ($user) => [
            'id' => (string) $user->id(),
            'title' => (string) ($user->name() ?: $user->email()),
            'hint' => $user->email(),
        ])->values()->all();
    }

    private function assets(?string $container, string $query, int $limit): array
    {
        $container = $container ? AssetContainer::findByHandle($container) : AssetContainer::all()->first();

        if (! $container || ! $this->user->can('view', $container)) {
            return [];
        }

        $builder = Asset::query()->where('container', $container->handle());

        if ($query !== '') {
            $builder->where('path', 'like', '%'.$query.'%');
        }

        return $builder->limit($limit)->get()->map(fn ($asset) => [
            'id' => $asset->id(),
            'title' => $asset->basename(),
            'hint' => Str::beforeLast($asset->path(), '/') !== $asset->path() ? Str::beforeLast($asset->path(), '/') : null,
            'thumb' => $asset->isImage() ? $asset->thumbnailUrl('small') : null,
        ])->values()->all();
    }
}
