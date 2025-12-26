<?php

namespace App\Providers;

use App\Logic\Macros\Macros;
use Spatie\Html\HtmlServiceProvider;

/**
 * Class MacroServiceProvider.
 */
class MacroServiceProvider extends HtmlServiceProvider
{
    /**
     * Register the application services.
     */
    public function register(): void
    {
        // Macros must be loaded after the HTMLServiceProvider's
        // register method is called. Otherwise, csrf tokens
        // will not be generated
        parent::register();

        // Load HTML Macros
        require base_path().'/app/Html/HtmlMacros.php';
    }

    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        // Register FormBuilder as a singleton after all services are booted
        $this->app->singleton(\App\Html\FormBuilder::class, function ($app) {
            return new \App\Html\FormBuilder($app->make(\Spatie\Html\Html::class));
        });

        // Load form() helper alias
        require base_path().'/app/Html/FormHelper.php';
    }
}
