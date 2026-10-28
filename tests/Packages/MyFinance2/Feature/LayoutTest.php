<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\CreatesSyntheticUsers;
use Tests\Concerns\ExercisesTheMiddleware;
use Tests\Packages\MyFinance2\MyFinance2TestCase;

/**
 * MyFinance2 pages in this app: in its layout (config/myfinance2.php: layouts.app and its section names), which shows
 * the flash messages itself, after its own bundle (jQuery, Bootstrap), with the package's assets published as built.
 * The package layout itself (sections, standalone page, asset order) is tested in the package. A throwaway finance
 * user (CreatesSyntheticUsers) opens the accounts page; rolled back.
 */
class LayoutTest extends MyFinance2TestCase
{
    use CreatesSyntheticUsers;
    use DatabaseTransactions;
    use ExercisesTheMiddleware;

    private const MESSAGE = 'TST layout flash message';

    public function connectionsToTransact(): array
    {
        return [null, config('myfinance2.db_connection', 'myfinance2_mysql')];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->exerciseTheMiddleware();
        config(['laravel2step.laravel2stepEnabled' => false]);
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        parent::tearDown();
    }

    public function test_a_page_renders_in_this_apps_layout_which_shows_the_flash_messages(): void
    {
        $html = $this->_accountsPage();

        $this->assertStringContainsString('<div id="app">', $html);
        $this->assertStringContainsString('<title>' . trans('myfinance2::accounts.titles.dashboard') . ' | ', $html);
        $this->assertStringContainsString('navbar-dropdown-myfinance2', $html);
        $this->assertSame(1, substr_count($html, self::MESSAGE), 'The flash message is shown once');
    }

    public function test_in_this_apps_layout_a_page_loads_only_the_package_bundle_after_the_apps_own(): void
    {
        $html = $this->_accountsPage();

        $this->assertSame(['myfinance2.css', 'myfinance2.js'], $this->_assets($html));
        $this->assertLessThan(strpos($html, 'vendor/myfinance2/myfinance2.js'), strpos($html, '/build/'),
            "This app's bundle (jQuery, Bootstrap) comes first");
    }

    public function test_the_published_assets_are_the_packages_current_build(): void
    {
        foreach (['myfinance2.js', 'myfinance2.css', 'myfinance2-base.js', 'myfinance2-base.css'] as $file) {
            $built     = base_path("vendor/ovidiuro/myfinance2/dist/{$file}");
            $published = public_path("vendor/myfinance2/{$file}");

            $this->assertFileExists($built, "Build the package's assets: cd vendor/ovidiuro/myfinance2 && yarn build");
            $this->assertFileExists($published, 'Publish them: php artisan vendor:publish --tag=myfinance2-assets');
            $this->assertSame(md5_file($built), md5_file($published), "public/vendor/myfinance2/{$file} is stale:"
                . ' php artisan vendor:publish --tag=myfinance2-assets --force');
        }
    }

    /**
     * The package's asset files the page loads (or names as missing), in order.
     *
     * @return list<string>
     */
    private function _assets(string $html): array
    {
        preg_match_all('#vendor/myfinance2/([\w-]+\.(?:css|js))#', $html, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function _accountsPage(): string
    {
        $this->actingAs($this->syntheticUser(['financeadmin']));

        return $this->withSession(['success' => self::MESSAGE])
            ->get(route('myfinance2::accounts.index'))
            ->assertOk()
            ->getContent();
    }
}
