<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use ovidiuro\myfinance2\App\Models\Account;
use ovidiuro\myfinance2\App\Models\Order;
use ovidiuro\myfinance2\App\Models\PriceAlert;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;

/**
 * Orders and RELATIVE price alerts: a relative alert whose moving target happens to equal an
 * order's limit price is never treated as the order's companion alert (never shown as "Linked to
 * this order", never rewritten or deleted by an order edit or fill). Synthetic TST.* rows only,
 * rolled back on both connections.
 */
class OrdersRelativeAlertsTest extends TestCase
{
    use DatabaseTransactions;

    private const SYMBOL = 'TST.ORDREL';

    private ?User $_user = null;

    private ?Account $_account = null;

    public function connectionsToTransact(): array
    {
        return [null, config('myfinance2.db_connection', 'myfinance2_mysql')];
    }

    protected function setUp(): void
    {
        parent::setUp();

        AssignedToUserScope::enable();
        $this->_user = User::first();

        if (!$this->_user) {
            $this->markTestSkipped('Requires at least 1 user in database');
        }

        $this->actingAs($this->_user);
        $this->_account = Account::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->_user->id)
            ->first();
    }

    protected function tearDown(): void
    {
        AssignedToUserScope::enable();
        Auth::forgetGuards();
        parent::tearDown();
    }

    private function _placedBuyOrder(): Order
    {
        if (!$this->_account) {
            $this->markTestSkipped('Requires at least 1 account for the test user');
        }

        return Order::create([
            'symbol'      => self::SYMBOL,
            'action'      => 'BUY',
            'status'      => 'PLACED',
            'account_id'  => $this->_account->id,
            'quantity'    => '10.00000000',
            'limit_price' => '100.0000',
            'placed_at'   => now(),
        ]);
    }

    /**
     * An ACTIVE alert at the order's limit price (100), PRICE_BELOW like a BUY companion alert.
     */
    private function _alertAtLimitPrice(array $fields): PriceAlert
    {
        $id = DB::connection(config('myfinance2.db_connection'))->table('price_alerts')->insertGetId(array_merge([
            'user_id'               => $this->_user->id,
            'symbol'                => self::SYMBOL,
            'alert_type'            => 'PRICE_BELOW',
            'target_price'          => 100.0,
            'target_mode'           => 'RELATIVE',
            'reference_type'        => 'HIGH',
            'reference_window'      => '2y',
            'offset_pct'            => 20,
            'reference_price'       => 125.0,
            'reference_resolved_at' => now(),
            'status'                => 'ACTIVE',
            'source'                => 'manual',
            'notification_channel'  => 'email',
            'trigger_count'         => 0,
            'expires_at'            => now()->addDays(5),
            'created_at'            => now(),
            'updated_at'            => now(),
        ], $fields));

        return PriceAlert::findOrFail($id);
    }

    public function test_relative_alert_at_the_limit_price_is_not_linked_to_the_order(): void
    {
        $this->_placedBuyOrder();
        $relative = $this->_alertAtLimitPrice([]);

        $html = $this->withoutMiddleware()->actingAs($this->_user)
            ->get(route('myfinance2::orders.index'))
            ->assertOk()
            ->getContent();

        $editUrl = preg_quote(route('myfinance2::price-alerts.edit', $relative->id), '/');
        $this->assertMatchesRegularExpression('/<a href="' . $editUrl . '"[^>]*title="([^"]*)"/s', $html);
        preg_match('/<a href="' . $editUrl . '"[^>]*title="([^"]*)"/s', $html, $matches);

        $this->assertStringNotContainsString('Linked to this order', $matches[1]);
        $this->assertStringContainsString('20% below 2Y high', html_entity_decode($matches[1]));
    }

    public function test_filling_the_order_keeps_a_relative_alert_and_deletes_the_fixed_companion(): void
    {
        $order = $this->_placedBuyOrder();
        // Even with source = order (only possible through a direct write), a RELATIVE alert is
        // never treated as the companion alert.
        $relative  = $this->_alertAtLimitPrice(['source' => 'order']);
        $companion = $this->_alertAtLimitPrice([
            'source' => 'order', 'target_mode' => 'FIXED', 'reference_type' => null,
            'reference_window' => null, 'offset_pct' => null, 'reference_price' => null,
            'reference_resolved_at' => null,
        ]);

        $this->withoutMiddleware()->actingAs($this->_user)
            ->post(route('myfinance2::orders.fill', $order->id))
            ->assertSessionHas('success');

        $this->assertNotNull(PriceAlert::find($relative->id));
        $this->assertNull(PriceAlert::find($companion->id));
    }

    public function test_open_alerts_banner_shows_the_relative_label(): void
    {
        $this->_alertAtLimitPrice([]);

        $this->withoutMiddleware()->actingAs($this->_user)
            ->get(route('myfinance2::orders.open-alerts-for-symbol', ['symbol' => self::SYMBOL]))
            ->assertOk()
            ->assertSee('(20% below 2Y high)');
    }
}
