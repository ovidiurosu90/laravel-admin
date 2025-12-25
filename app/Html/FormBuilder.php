<?php

namespace App\Html;

use Spatie\Html\Html;
use Illuminate\Support\HtmlString;

/**
 * FormBuilder - Compatibility wrapper for laravelcollective/html API
 * Uses Spatie\Html\Html under the hood
 */
class FormBuilder
{
    protected $html;
    protected $formAttributes = [];
    protected $inForm = false;

    public function __construct(Html $html)
    {
        $this->html = $html;
    }

    /**
     * Open a form
     */
    public function open(array $attributes = []): HtmlString
    {
        $this->formAttributes = $attributes;
        $this->inForm = true;

        $method = $attributes['method'] ?? 'GET';
        $route = $attributes['route'] ?? null;
        $action = $attributes['action'] ?? null;

        if ($route) {
            if (is_array($route)) {
                $action = route($route[0], $route[1] ?? null);
            } else {
                $action = route($route);
            }
        }

        $method = strtoupper($method);

        $html = '<form';
        if ($action) {
            $html .= ' action="' . htmlspecialchars($action) . '"';
        }
        if ($method && $method !== 'GET') {
            $html .= ' method="POST"';
        } else {
            $html .= ' method="' . $method . '"';
        }

        // Add other attributes
        foreach ($attributes as $key => $value) {
            if (! in_array($key, ['method', 'route', 'action'])) {
                $html .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($value) . '"';
            }
        }

        $html .= '>';

        return new HtmlString($html);
    }

    /**
     * Close a form
     */
    public function close(): HtmlString
    {
        $this->inForm = false;
        return new HtmlString('</form>');
    }

    /**
     * Create a text input
     */
    public function text(string $name = null, $value = null, array $attributes = []): HtmlString
    {
        $html = '<input type="text"';
        if ($name) {
            $html .= ' name="' . htmlspecialchars($name) . '"';
        }

        // Get the value: prioritize old input for form resubmits, then explicit value
        $displayValue = old($name) ?? $value;

        if ($displayValue !== null) {
            $html .= ' value="' . htmlspecialchars($displayValue) . '"';
        }

        foreach ($attributes as $key => $val) {
            if ($val !== null) {
                $html .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($val) . '"';
            }
        }

        $html .= '>';

        return new HtmlString($html);
    }

    /**
     * Create an email input
     */
    public function email(string $name = null, $value = null, array $attributes = []): HtmlString
    {
        // Get the value: prioritize old input, then explicit value
        $displayValue = old($name) ?? $value;
        $attributes['type'] = 'email';
        return $this->text($name, $displayValue, $attributes);
    }

    /**
     * Create a password input
     */
    public function password(string $name = null, array $attributes = []): HtmlString
    {
        $attributes['type'] = 'password';
        return $this->text($name, null, $attributes);
    }

    /**
     * Create a hidden input
     */
    public function hidden(string $name = null, $value = null, array $attributes = []): HtmlString
    {
        $html = '<input type="hidden"';
        if ($name) {
            $html .= ' name="' . htmlspecialchars($name) . '"';
        }
        if ($value !== null) {
            $html .= ' value="' . htmlspecialchars($value) . '"';
        }

        foreach ($attributes as $key => $val) {
            if ($val !== null) {
                $html .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($val) . '"';
            }
        }

        $html .= '>';

        return new HtmlString($html);
    }

    /**
     * Create a method field (hidden input for spoofing HTTP methods)
     */
    public function method(string $method = 'POST'): HtmlString
    {
        return $this->hidden('_method', strtoupper($method));
    }

    /**
     * Create a button
     */
    public function button($value = '', array $attributes = []): HtmlString
    {
        $html = '<button';

        foreach ($attributes as $key => $val) {
            if ($val !== null && $val !== false) {
                if (is_bool($val)) {
                    $html .= ' ' . htmlspecialchars($key);
                } else {
                    $html .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($val) . '"';
                }
            }
        }

        $html .= '>' . $value . '</button>';

        return new HtmlString($html);
    }

    /**
     * Create a textarea
     */
    public function textarea(string $name = null, $value = null, array $attributes = []): HtmlString
    {
        $html = '<textarea';
        if ($name) {
            $html .= ' name="' . htmlspecialchars($name) . '"';
        }

        foreach ($attributes as $key => $val) {
            if ($val !== null && $val !== false) {
                if (is_bool($val)) {
                    $html .= ' ' . htmlspecialchars($key);
                } else {
                    $html .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($val) . '"';
                }
            }
        }

        $html .= '>';
        if ($value !== null) {
            $html .= htmlspecialchars($value);
        }
        $html .= '</textarea>';

        return new HtmlString($html);
    }

    /**
     * Create a select
     */
    public function select(string $name = null, array $options = [], $selected = null, array $attributes = []): HtmlString
    {
        $html = '<select';
        if ($name) {
            $html .= ' name="' . htmlspecialchars($name) . '"';
        }

        foreach ($attributes as $key => $val) {
            if ($val !== null && $val !== false) {
                if (is_bool($val)) {
                    $html .= ' ' . htmlspecialchars($key);
                } else {
                    $html .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($val) . '"';
                }
            }
        }

        $html .= '>';

        foreach ($options as $value => $label) {
            $html .= '<option value="' . htmlspecialchars($value) . '"';
            if ($value == $selected) {
                $html .= ' selected="selected"';
            }
            $html .= '>' . htmlspecialchars($label) . '</option>';
        }

        $html .= '</select>';

        return new HtmlString($html);
    }

    /**
     * Magic method to forward unknown calls to the underlying Html instance
     */
    public function __call($method, $parameters)
    {
        return $this->html->$method(...$parameters);
    }
}
