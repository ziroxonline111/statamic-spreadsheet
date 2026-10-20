<?php

namespace KareliaLabs\Spreadsheet\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use KareliaLabs\Spreadsheet\Columns;
use KareliaLabs\Spreadsheet\Lookup;
use KareliaLabs\Spreadsheet\Rows;
use KareliaLabs\Spreadsheet\Saver;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

class SpreadsheetController extends CpController
{
    public function index()
    {
        $user = $this->user();

        $collections = Collection::all()
            ->filter(fn ($collection) => $user->can('view', $collection))
            ->sortBy(fn ($collection) => $collection->title())
            ->map(fn ($collection) => [
                'handle' => $collection->handle(),
                'title' => __($collection->title()),
                'icon' => $collection->icon() ?: 'collections',
                'count' => $collection->queryEntries()->count(),
                'url' => cp_route('karelia-spreadsheet.show', $collection->handle()),
            ])
            ->values();

        return Inertia::render('statamic-spreadsheet::SpreadsheetIndex', [
            'collections' => $collections,
        ]);
    }

    public function show(Request $request, string $handle)
    {
        $user = $this->user();
        $collection = $this->collection($handle);

        $sites = $collection->sites()
            ->filter(fn ($handle) => Site::get($handle) && $user->can('view', Site::get($handle)))
            ->map(fn ($handle) => ['handle' => $handle, 'name' => Site::get($handle)->name()])
            ->values();

        $site = $request->input('site', Site::selected()->handle());

        if (! $sites->contains('handle', $site)) {
            $site = $sites->first()['handle'] ?? Site::default()->handle();
        }

        return Inertia::render('statamic-spreadsheet::SpreadsheetEditor', [
            'collection' => [
                'handle' => $collection->handle(),
                'title' => __($collection->title()),
                'icon' => $collection->icon() ?: 'collections',
                'url' => cp_route('collections.show', $collection->handle()),
            ],
            'columns' => (new Columns($collection))->all()->values(),
            'sites' => Site::multiEnabled() ? $sites : [],
            'site' => $site,
            'ids' => array_values(array_filter(explode(',', (string) $request->input('ids')))),
            'limit' => (int) config('statamic.spreadsheet.limit', 2000),
            'rowsUrl' => cp_route('karelia-spreadsheet.rows', $collection->handle()),
            'saveUrl' => cp_route('karelia-spreadsheet.save', $collection->handle()),
            'lookupUrl' => cp_route('karelia-spreadsheet.lookup', $collection->handle()),
            'indexUrl' => cp_route('karelia-spreadsheet.index'),
        ]);
    }

    public function rows(Request $request, string $handle)
    {
        $user = $this->user();
        $collection = $this->collection($handle);
        $columns = (new Columns($collection))->all();
        $limit = max(1, (int) config('statamic.spreadsheet.limit', 2000));

        $query = $collection->queryEntries();

        if ($site = $request->input('site')) {
            $query->where('site', $site);
        }

        if ($ids = array_filter((array) $request->input('ids', []))) {
            $query->whereIn('id', $ids);
        }

        if ($search = trim((string) $request->input('search'))) {
            $query->where('title', 'like', '%'.$search.'%');
        }

        if ($collection->orderable()) {
            $query->orderBy('order');
        } elseif ($collection->dated()) {
            $query->orderBy('date', 'desc');
        } else {
            $query->orderBy('title');
        }

        $total = (clone $query)->count();
        $presenter = new Rows($columns, $user);

        $rows = $query->limit($limit)->get()
            ->filter(fn ($entry) => $user->can('view', $entry))
            ->map(fn ($entry) => $presenter->row($entry))
            ->values();

        return [
            'rows' => $rows,
            'labels' => $presenter->labels(),
            'total' => $total,
            'truncated' => $total > $limit,
        ];
    }

    public function save(Request $request, string $handle)
    {
        $user = $this->user();
        $collection = $this->collection($handle);
        $columns = (new Columns($collection))->all();

        $data = $request->validate([
            'changes' => 'required|array|max:5000',
            'changes.*.id' => 'required|string',
            'changes.*.values' => 'required|array',
        ]);

        $saver = new Saver($columns, $user);
        $presenter = new Rows($columns, $user);
        $results = [];

        foreach ($data['changes'] as $change) {
            $entry = Entry::find($change['id']);

            if (! $entry || $entry->collectionHandle() !== $collection->handle()) {
                $results[$change['id']] = ['ok' => false, 'errors' => ['_row' => [__('This entry no longer exists.')]], 'note' => null];

                continue;
            }

            try {
                $result = $saver->save($entry, $change['values']);
            } catch (\Throwable $e) {
                report($e);
                $result = ['ok' => false, 'errors' => ['_row' => [$e->getMessage() ?: __('Something went wrong')]], 'note' => null];
            }

            if ($result['ok']) {
                $result['row'] = $presenter->row(Entry::find($change['id'])->fromWorkingCopy());
            }

            $results[$change['id']] = $result;
        }

        $saved = collect($results)->where('ok', true)->count();
        $failed = count($results) - $saved;

        return [
            'results' => $results,
            'labels' => $presenter->labels(),
            'message' => $failed
                ? __(':saved saved, :failed need attention', ['saved' => $saved, 'failed' => $failed])
                : trans_choice('Saved :count entry|Saved :count entries', $saved, ['count' => $saved]),
        ];
    }

    public function lookup(Request $request, string $handle)
    {
        $user = $this->user();
        $collection = $this->collection($handle);
        $column = (new Columns($collection))->all()->get((string) $request->input('field'));

        abort_unless($column && $column['kind'] === 'relationship', 404);

        if ($ids = array_filter((array) $request->input('ids', []))) {
            return ['labels' => Rows::describe($column['relationship'], $ids)];
        }

        return ['results' => (new Lookup($user))->search($column, $request->input('q'), $request->input('site'))];
    }

    private function user()
    {
        $user = User::current();

        abort_unless($user && ($user->isSuper() || $user->hasPermission('use spreadsheet')), 403);

        return $user;
    }

    private function collection(string $handle)
    {
        $collection = Collection::findByHandle($handle);

        abort_unless($collection, 404);
        abort_unless($this->user()->can('view', $collection), 403);

        return $collection;
    }
}
