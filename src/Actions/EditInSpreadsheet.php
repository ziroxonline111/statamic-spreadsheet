<?php

namespace KareliaLabs\Spreadsheet\Actions;

use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;

/**
 * "Edit in Spreadsheet" in the entry listing: opens the selected entries
 * in the spreadsheet.
 */
class EditInSpreadsheet extends Action
{
    protected $icon = 'fieldtype-table';

    public static function title()
    {
        return __('Edit in Spreadsheet');
    }

    public function visibleTo($item)
    {
        return $item instanceof Entry && $this->context['view'] !== 'form';
    }

    public function visibleToBulk($items)
    {
        return $items->every(fn ($item) => $item instanceof Entry)
            && $items->map->collectionHandle()->unique()->count() === 1;
    }

    public function authorize($user, $item)
    {
        return ($user->isSuper() || $user->hasPermission('use spreadsheet')) && $user->can('view', $item);
    }

    public function confirmationText()
    {
        return null;
    }

    public function run($items, $values)
    {
        return false;
    }

    public function redirect($items, $values)
    {
        $first = $items->first();

        return cp_route('karelia-spreadsheet.show', [
            'handle' => $first->collectionHandle(),
            'site' => $first->locale(),
            'ids' => $items->map->id()->implode(','),
        ]);
    }
}
