<?php

if (! function_exists('form')) {
    /**
     * Get the FormBuilder instance.
     * Provides laravelcollective/html compatible API with spatie/laravel-html backend.
     *
     * @return \App\Html\FormBuilder
     */
    function form()
    {
        return app(\App\Html\FormBuilder::class);
    }
}
