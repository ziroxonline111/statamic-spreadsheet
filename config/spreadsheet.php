<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rows per sheet
    |--------------------------------------------------------------------------
    |
    | The most entries loaded into one sheet. Larger collections can still be
    | edited by searching or by opening a selection from the entry listing
    | (Edit in Spreadsheet).
    |
    */

    'limit' => env('STATAMIC_SPREADSHEET_LIMIT', 2000),

];
