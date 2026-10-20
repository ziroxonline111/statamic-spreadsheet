<?php

namespace KareliaLabs\Spreadsheet;

use KareliaLabs\Spreadsheet\Actions\EditInSpreadsheet;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $config = false;

    protected $vite = [
        'input' => [
            'resources/js/addon.js',
            'resources/css/addon.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $actions = [
        EditInSpreadsheet::class,
    ];

    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/spreadsheet.php', 'statamic.spreadsheet');
    }

    public function bootAddon()
    {
        $this->publishes([
            __DIR__.'/../config/spreadsheet.php' => config_path('statamic/spreadsheet.php'),
        ], 'statamic-spreadsheet-config');

        Permission::extend(function () {
            Permission::group('spreadsheet', __('Spreadsheet'), function () {
                Permission::register('use spreadsheet')
                    ->label(__('Use the spreadsheet'))
                    ->description(__('Edit many entries at once. The usual collection permissions still decide what can be seen and changed.'));
            });
        });

        Nav::extend(function ($nav) {
            $nav->content(__('Spreadsheet'))
                ->route('karelia-spreadsheet.index')
                ->icon('fieldtype-table')
                ->can('use spreadsheet');
        });
    }
}
