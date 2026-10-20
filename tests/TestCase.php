<?php

namespace KareliaLabs\Spreadsheet\Tests;

use Illuminate\Support\Facades\File;
use KareliaLabs\Spreadsheet\ServiceProvider;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Role;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = __DIR__.'/__fixtures__/blueprints';
        File::deleteDirectory($dir);
        File::ensureDirectoryExists($dir);
        app(\Statamic\Fields\BlueprintRepository::class)->setDirectory($dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(__DIR__.'/__fixtures__/blueprints');

        parent::tearDown();
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('statamic.editions.pro', true);
    }

    /** A blog with a typical article blueprint. */
    protected function blog(array $extraFields = []): \Statamic\Contracts\Entries\Collection
    {
        Taxonomy::make('tags')->title('Tags')->save();
        Term::make()->taxonomy('tags')->slug('news')->dataForLocale('default', ['title' => 'News'])->save();
        Term::make()->taxonomy('tags')->slug('guides')->dataForLocale('default', ['title' => 'Guides'])->save();

        $collection = Collection::make('blog')->title('Blog')->routes('/blog/{slug}')->taxonomies(['tags']);
        $collection->save();

        Blueprint::make('article')->setNamespace('collections.blog')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => array_merge([
                ['handle' => 'title', 'field' => ['type' => 'text', 'validate' => 'required']],
                ['handle' => 'slug', 'field' => ['type' => 'slug']],
                ['handle' => 'excerpt', 'field' => ['type' => 'textarea', 'validate' => 'max:200']],
                ['handle' => 'price', 'field' => ['type' => 'integer', 'validate' => 'nullable|integer|min:0']],
                ['handle' => 'featured', 'field' => ['type' => 'toggle']],
                ['handle' => 'colour', 'field' => ['type' => 'select', 'options' => ['red' => 'Red', 'blue' => 'Blue']]],
                ['handle' => 'tags', 'field' => ['type' => 'terms', 'taxonomies' => ['tags'], 'mode' => 'select']],
                ['handle' => 'related', 'field' => ['type' => 'entries', 'collections' => ['blog'], 'max_items' => 1]],
                ['handle' => 'body', 'field' => ['type' => 'bard']],
                ['handle' => 'notes', 'field' => ['type' => 'text', 'visibility' => 'read_only']],
            ], $extraFields)]]]],
        ])->save();

        return Collection::findByHandle('blog');
    }

    protected function super()
    {
        $user = User::make()->email('super@example.com')->makeSuper();
        $user->save();

        return $user;
    }

    protected function editor(array $permissions)
    {
        Role::make('editor')->title('Editor')->permissions(array_merge(['access cp'], $permissions))->save();
        $user = User::make()->email('editor@example.com')->assignRole('editor');
        $user->save();

        return $user;
    }
}
