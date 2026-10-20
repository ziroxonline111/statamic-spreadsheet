<?php

namespace KareliaLabs\Spreadsheet\Tests;

use KareliaLabs\Spreadsheet\Columns;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Entry;

class SpreadsheetTest extends TestCase
{
    private function makePost(string $id, array $data)
    {
        $entry = Entry::make()->collection('blog')->id($id)->slug($id)->data($data);
        $entry->save();

        return $entry;
    }

    #[Test]
    public function columns_come_from_the_blueprint_with_the_right_editors()
    {
        $columns = (new Columns($this->blog()))->all();

        $this->assertSame('text', $columns['title']['kind']);
        $this->assertTrue($columns['title']['pinned']);
        $this->assertSame('longtext', $columns['excerpt']['kind']);
        $this->assertSame('number', $columns['price']['kind']);
        $this->assertSame('toggle', $columns['featured']['kind']);
        $this->assertSame('options', $columns['colour']['kind']);
        $this->assertSame([['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']], $columns['colour']['options']);
        $this->assertSame('relationship', $columns['tags']['kind']);
        $this->assertSame('terms', $columns['tags']['relationship']);
        $this->assertSame('readonly', $columns['body']['kind']);
        $this->assertTrue($columns['notes']['readonly']);
        $this->assertArrayHasKey('published', $columns->all());
    }

    #[Test]
    public function the_rows_endpoint_returns_values_and_relationship_titles()
    {
        $this->blog();
        $this->makePost('a', ['title' => 'Apples', 'price' => 5, 'tags' => ['news'], 'body' => [['type' => 'paragraph']]]);
        $this->makePost('b', ['title' => 'Bananas', 'related' => 'a']);

        $response = $this->actingAs($this->super())
            ->getJson(cp_route('karelia-spreadsheet.rows', 'blog'))
            ->assertOk()
            ->assertJsonPath('total', 2);

        $rows = collect($response->json('rows'))->keyBy('_id');
        $this->assertSame(5, $rows['a']['price']);
        $this->assertSame(['tags::news'], $rows['a']['tags']);
        $this->assertSame(['a'], $rows['b']['related']);
        $this->assertSame('1 item', $rows['a']['body']);
        $this->assertSame('News', $response->json('labels.terms')['tags::news']['title']);
        $this->assertSame('Apples', $response->json('labels.entries')['a']['title']);
    }

    #[Test]
    public function changes_are_saved_through_the_blueprint()
    {
        $this->blog();
        $this->makePost('a', ['title' => 'Apples', 'price' => 5]);

        $this->actingAs($this->super())
            ->postJson(cp_route('karelia-spreadsheet.save', 'blog'), ['changes' => [
                ['id' => 'a', 'values' => [
                    'title' => 'Green apples',
                    'price' => '7',
                    'featured' => true,
                    'colour' => 'blue',
                    'tags' => ['tags::guides', 'tags::news'],
                    'excerpt' => '',
                ]],
            ]])
            ->assertOk()
            ->assertJsonPath('results.a.ok', true)
            ->assertJsonPath('results.a.row.title', 'Green apples');

        $entry = Entry::find('a');
        $this->assertSame('Green apples', $entry->get('title'));
        $this->assertSame(7, $entry->get('price'));
        $this->assertTrue($entry->get('featured'));
        $this->assertSame('blue', $entry->get('colour'));
        $this->assertSame(['guides', 'news'], $entry->get('tags'));
        $this->assertFalse($entry->has('excerpt'));
        $this->assertNotNull($entry->get('updated_by'));
    }

    #[Test]
    public function validation_errors_come_back_per_cell_and_nothing_is_saved_for_that_entry()
    {
        $this->blog();
        $this->makePost('a', ['title' => 'Apples', 'price' => 5]);
        $this->makePost('b', ['title' => 'Bananas']);

        $response = $this->actingAs($this->super())
            ->postJson(cp_route('karelia-spreadsheet.save', 'blog'), ['changes' => [
                ['id' => 'a', 'values' => ['title' => '', 'price' => '-3']],
                ['id' => 'b', 'values' => ['title' => 'Ripe bananas']],
            ]])
            ->assertOk()
            ->assertJsonPath('results.a.ok', false)
            ->assertJsonPath('results.b.ok', true);

        $this->assertArrayHasKey('title', $response->json('results.a.errors'));
        $this->assertArrayHasKey('price', $response->json('results.a.errors'));
        $this->assertSame('Apples', Entry::find('a')->get('title'));
        $this->assertSame('Ripe bananas', Entry::find('b')->get('title'));
    }

    #[Test]
    public function read_only_and_unsupported_fields_are_refused()
    {
        $this->blog();
        $this->makePost('a', ['title' => 'Apples']);

        $response = $this->actingAs($this->super())
            ->postJson(cp_route('karelia-spreadsheet.save', 'blog'), ['changes' => [
                ['id' => 'a', 'values' => ['notes' => 'x', 'body' => 'y']],
            ]])
            ->assertJsonPath('results.a.ok', false);

        $this->assertArrayHasKey('notes', $response->json('results.a.errors'));
        $this->assertArrayHasKey('body', $response->json('results.a.errors'));
    }

    #[Test]
    public function slug_and_status_and_unique_urls()
    {
        $this->blog();
        $this->makePost('a', ['title' => 'Apples']);
        $this->makePost('b', ['title' => 'Bananas']);

        $this->actingAs($this->super())
            ->postJson(cp_route('karelia-spreadsheet.save', 'blog'), ['changes' => [
                ['id' => 'a', 'values' => ['slug' => 'fresh-apples', 'published' => false]],
            ]])
            ->assertJsonPath('results.a.ok', true);

        $this->assertSame('fresh-apples', Entry::find('a')->slug());
        $this->assertFalse(Entry::find('a')->published());

        $response = $this->actingAs($this->super())
            ->postJson(cp_route('karelia-spreadsheet.save', 'blog'), ['changes' => [
                ['id' => 'b', 'values' => ['slug' => 'fresh-apples']],
            ]])
            ->assertJsonPath('results.b.ok', false);

        $this->assertArrayHasKey('slug', $response->json('results.b.errors'));
        $this->assertSame('b', Entry::find('b')->slug());
    }

    #[Test]
    public function with_revisions_on_a_published_entry_gets_a_working_copy()
    {
        config(['statamic.revisions.enabled' => true, 'statamic.revisions.path' => __DIR__.'/__fixtures__/revisions']);

        $collection = $this->blog();
        $collection->revisionsEnabled(true)->save();
        $this->makePost('a', ['title' => 'Apples']);

        $this->actingAs($this->super())
            ->postJson(cp_route('karelia-spreadsheet.save', 'blog'), ['changes' => [['id' => 'a', 'values' => ['title' => 'Apples v2']]]])
            ->assertJsonPath('results.a.ok', true)
            ->assertJsonPath('results.a.note', 'Saved as a working copy. Publish it from the entry page.');

        $entry = Entry::find('a');
        $this->assertSame('Apples', $entry->get('title'));
        $this->assertTrue($entry->hasWorkingCopy());
        $this->assertSame('Apples v2', $entry->fromWorkingCopy()->get('title'));

        \Illuminate\Support\Facades\File::deleteDirectory(__DIR__.'/__fixtures__/revisions');
    }

    #[Test]
    public function the_spreadsheet_needs_its_own_permission()
    {
        $this->blog();
        $editor = $this->editor(['view blog entries', 'edit blog entries']);

        $this->actingAs($editor)->getJson(cp_route('karelia-spreadsheet.rows', 'blog'))->assertForbidden();
    }

    #[Test]
    public function collection_permissions_still_apply()
    {
        $this->blog();
        $this->makePost('a', ['title' => 'Apples']);

        $viewer = $this->editor(['use spreadsheet', 'view blog entries']);

        $this->actingAs($viewer)->getJson(cp_route('karelia-spreadsheet.rows', 'blog'))
            ->assertOk()
            ->assertJsonPath('rows.0._can_edit', false);

        $this->actingAs($viewer)
            ->postJson(cp_route('karelia-spreadsheet.save', 'blog'), ['changes' => [['id' => 'a', 'values' => ['title' => 'Hacked']]]])
            ->assertJsonPath('results.a.ok', false);

        $this->assertSame('Apples', Entry::find('a')->get('title'));
    }

    #[Test]
    public function changing_the_status_needs_the_publish_permission()
    {
        $this->blog();
        $this->makePost('a', ['title' => 'Apples']);

        $editor = $this->editor(['use spreadsheet', 'view blog entries', 'edit blog entries']);

        $this->actingAs($editor)
            ->postJson(cp_route('karelia-spreadsheet.save', 'blog'), ['changes' => [['id' => 'a', 'values' => ['published' => false]]]])
            ->assertJsonPath('results.a.ok', false);

        $this->assertTrue(Entry::find('a')->published());
    }

    #[Test]
    public function relationship_search_finds_terms_and_entries()
    {
        $this->blog();
        $this->makePost('a', ['title' => 'Apple pie']);

        $this->actingAs($this->super())
            ->getJson(cp_route('karelia-spreadsheet.lookup', ['handle' => 'blog', 'field' => 'tags', 'q' => 'gui']))
            ->assertOk()
            ->assertJsonPath('results.0.id', 'tags::guides');

        $this->actingAs($this->super())
            ->getJson(cp_route('karelia-spreadsheet.lookup', ['handle' => 'blog', 'field' => 'related', 'q' => 'pie']))
            ->assertJsonPath('results.0.id', 'a');
    }

    #[Test]
    public function the_page_renders()
    {
        $this->blog();

        $this->actingAs($this->super())
            ->get(cp_route('karelia-spreadsheet.show', 'blog'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('statamic-spreadsheet::SpreadsheetEditor')->where('collection.handle', 'blog'));

        $this->actingAs($this->super())
            ->get(cp_route('karelia-spreadsheet.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('statamic-spreadsheet::SpreadsheetIndex'));
    }

    #[Test]
    public function the_edit_in_spreadsheet_action_opens_the_selection()
    {
        $this->blog();
        $this->makePost('a', ['title' => 'A']);
        $this->makePost('b', ['title' => 'B']);

        $response = $this->actingAs($this->super())
            ->postJson(cp_route('collections.entries.actions.run', 'blog'), [
                'action' => 'edit_in_spreadsheet',
                'selections' => ['a', 'b'],
                'values' => [],
                'context' => ['collection' => 'blog', 'view' => 'list'],
            ])
            ->assertOk();

        $this->assertStringContainsString('/spreadsheet/blog', $response->json('redirect'));
        $this->assertStringContainsString('ids=a%2Cb', $response->json('redirect'));
    }
}
