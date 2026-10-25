<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

use ovidiuro\myfinance2\App\Models\Order;
use ovidiuro\myfinance2\App\Models\TierSnapshot;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;
use ovidiuro\myfinance2\App\Services\LiveTierReadings;
use ovidiuro\myfinance2\App\Services\OrderTierWarnings;
use ovidiuro\myfinance2\App\Services\TierCalculationService as Tiers;
use ovidiuro\myfinance2\App\Services\TierReadingBuilder;

/**
 * The tier reading saved with an order (TierSnapshot): the confirmation on save for a serious warning, the reading
 * locked once saved, the warnings re-checked on edit and a new reading when the decision changes. The live pipeline
 * is replaced by a fake (no quotes, no network); only the synthetic TST.ORD symbol is written, and everything is
 * rolled back.
 */
class OrderTierSnapshotsFeatureTest extends TestCase
{
    use DatabaseTransactions;

    private const SYMBOL = 'TST.ORD';

    private ?User $_user = null;

    private LiveTierReadings $_live;

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
        config(['watchlistsymbols.order_warnings.live_cache_seconds' => 0]);
        config(['watchlistsymbols.order_warnings.levels' => OrderTierWarnings::DEFAULT_LEVELS]);

        $this->_live = new class extends LiveTierReadings
        {
            public array $data = [];

            protected function build(): array
            {
                return $this->data;
            }
        };
        app()->instance(LiveTierReadings::class, $this->_live);
        $this->_setTier(Tiers::RUST);
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        parent::tearDown();
    }

    /**
     * Today's (fake) reading of TST.ORD: the tier in use, held for 500 EUR of a 10,000 EUR portfolio, at 50 EUR.
     */
    private function _setTier(string $tier): void
    {
        $reading = TierReadingBuilder::fromCategorization(self::SYMBOL, [
            'effective_tier' => $tier,
            'tier_tooltip'   => 'Fake ' . $tier . '.',
            'explanation'    => 'Fake.',
        ], ['mvalue_eur' => 500.0, 'mvalue_pct' => 5.0]);
        $this->_live->data = [
            'taken_at' => now()->format('Y-m-d H:i:s'),
            'as_of'    => now()->format('Y-m-d'),
            'stale'    => [],
            'symbols'  => [self::SYMBOL => [
                'reading'  => $reading,
                'facts'    => [
                    'has_reading'            => true,
                    'symbol'                 => self::SYMBOL,
                    'effective_tier'         => $tier,
                    'tier_basis'             => 'Market Settled',
                    'index_gap_settled_tier' => $tier,
                ],
                'position' => ['mvalue_eur' => 500.0, 'total_mvalue_eur' => 10000.0, 'price_native' => 50.0,
                    'currency' => 'EUR', 'eur_rate' => 1.0],
            ]],
        ];
    }

    private function _payload(array $fields = []): array
    {
        return array_merge([
            'symbol'      => self::SYMBOL,
            'action'      => 'BUY',
            'status'      => 'DRAFT',
            'quantity'    => '1',
            'limit_price' => '50',
            'description' => 'tier snapshot test',
        ], $fields);
    }

    private function _store(array $fields = []): \Illuminate\Testing\TestResponse
    {
        return $this->withoutMiddleware()->from(route('myfinance2::orders.create'))
            ->post(route('myfinance2::orders.store'), $this->_payload($fields));
    }

    private function _update(Order $order, array $fields = []): \Illuminate\Testing\TestResponse
    {
        $payload = $this->_payload($fields);
        unset($payload['status']);

        return $this->withoutMiddleware()->from(route('myfinance2::orders.edit', $order->id))
            ->put(route('myfinance2::orders.update', $order->id), $payload);
    }

    private function _order(): ?Order
    {
        return Order::with('tierSnapshot')->where('symbol', self::SYMBOL)->latest('id')->first();
    }

    private function _codes(TierSnapshot $snapshot): array
    {
        return array_column($snapshot->warnings, 'level', 'code');
    }

    public function test_store_without_confirming_a_serious_warning_goes_back_with_the_confirmation(): void
    {
        $this->_store()
            ->assertRedirect(route('myfinance2::orders.create'))
            ->assertSessionHas('tier_confirm');

        $this->assertNull($this->_order());
        $this->assertSame([OrderTierWarnings::BUY_WEAK_TIER],
            array_column(session('tier_confirm')['warnings'], 'code'));
    }

    public function test_store_with_the_confirmation_saves_the_reading_and_the_confirmed_warnings(): void
    {
        $this->_store(['acknowledged_warnings' => [OrderTierWarnings::BUY_WEAK_TIER]])
            ->assertRedirect(route('myfinance2::orders.index'));

        $snapshot = $this->_order()?->tierSnapshot;
        $this->assertNotNull($snapshot);
        $this->assertSame(TierSnapshot::SOURCE_LIVE, $snapshot->source);
        $this->assertSame(Tiers::RUST, $snapshot->effective_tier);
        $this->assertSame(OrderTierWarnings::VERDICT_AGAINST, $snapshot->verdict);
        $this->assertNotNull($snapshot->acknowledged_at);
        $this->assertSame(OrderTierWarnings::LEVEL_SERIOUS, $this->_codes($snapshot)[OrderTierWarnings::BUY_WEAK_TIER]);
        $this->assertSame(self::SYMBOL, $snapshot->reading['symbol']);
    }

    public function test_a_soft_warning_saves_without_a_confirmation(): void
    {
        $this->_setTier(Tiers::SILVER);
        $this->_store()->assertRedirect(route('myfinance2::orders.index'));

        $snapshot = $this->_order()->tierSnapshot;
        $this->assertSame(OrderTierWarnings::VERDICT_SOFT, $snapshot->verdict);
        $this->assertNull($snapshot->acknowledged_at);
    }

    public function test_the_saved_reading_stays_when_the_tier_changes(): void
    {
        $this->_store(['acknowledged_warnings' => [OrderTierWarnings::BUY_WEAK_TIER]]);
        $order    = $this->_order();
        $original = $order->tierSnapshot;

        $this->_setTier(Tiers::GOLD);
        $this->_update($order, ['description' => 'edited'])->assertRedirect(route('myfinance2::orders.index'));

        $order->refresh();
        $this->assertSame($original->id, $order->tier_snapshot_id);
        $this->assertSame(Tiers::RUST, $order->tierSnapshot->effective_tier);
        $this->assertSame($original->reading, $order->tierSnapshot->reading);

        // The edit form shows the saved reading, and today's tier next to it.
        $response = $this->withoutMiddleware()->getJson(route('myfinance2::orders.tier-reading', [
            'symbol' => self::SYMBOL, 'action' => 'BUY', 'quantity' => 1, 'limit_price' => 50,
            'order_id' => $order->id,
        ]));
        $response->assertOk();
        $this->assertStringContainsString('Locked', $response->json('html'));
        $this->assertStringContainsString('now:', $response->json('html'));
        $this->assertSame([], $response->json('missing'));
    }

    public function test_a_quantity_edit_that_adds_a_position_size_warning_asks_again(): void
    {
        $this->_setTier(Tiers::SILVER);
        $this->_store();
        $order    = $this->_order();
        $original = $order->tierSnapshot;
        $this->assertSame([OrderTierWarnings::BUY_SILVER => OrderTierWarnings::LEVEL_SOFT], $this->_codes($original));

        // 200 x 50 EUR on a 500 EUR position of a 10,000 EUR portfolio: 52.5 % after the order.
        $this->_update($order, ['quantity' => '200'])
            ->assertRedirect(route('myfinance2::orders.edit', $order->id))
            ->assertSessionHas('tier_confirm');
        $this->assertSame($original->id, $order->fresh()->tier_snapshot_id);

        $this->_update($order, ['quantity' => '200',
            'acknowledged_warnings' => [OrderTierWarnings::BUY_OVER_MAX_POSITION]])
            ->assertRedirect(route('myfinance2::orders.index'));
        $order->refresh();
        $this->assertNotSame($original->id, $order->tier_snapshot_id);
        $this->assertSame(OrderTierWarnings::LEVEL_SERIOUS,
            $this->_codes($order->tierSnapshot)[OrderTierWarnings::BUY_OVER_MAX_POSITION]);
        // Same reading, taken at the same time; only the warnings were evaluated again.
        $this->assertSame($original->reading, $order->tierSnapshot->reading);
        $this->assertEquals($original->taken_at, $order->tierSnapshot->taken_at);
        $this->assertSoftDeleted($original);
    }

    public function test_changing_the_action_takes_a_new_reading(): void
    {
        $this->_setTier(Tiers::SILVER);
        $this->_store();
        $order    = $this->_order();
        $original = $order->tierSnapshot;

        $this->_setTier(Tiers::GOLD);
        $this->_update($order, ['action' => 'SELL'])->assertRedirect(route('myfinance2::orders.index'));

        $order->refresh();
        $this->assertNotSame($original->id, $order->tier_snapshot_id);
        $this->assertSame(Tiers::GOLD, $order->tierSnapshot->effective_tier);
        $this->assertSame('SELL', $order->tierSnapshot->action);
    }

    public function test_the_order_list_shows_the_saved_verdict_and_todays_tier(): void
    {
        $this->_store(['acknowledged_warnings' => [OrderTierWarnings::BUY_WEAK_TIER]]);
        $this->_setTier(Tiers::BRONZE);

        $html = $this->withoutMiddleware()->get(route('myfinance2::orders.index', ['view' => 'all']))
            ->assertOk()
            ->assertSee('At creation, locked')
            ->getContent();
        // The red cross of a decision against the tiers, the saved Rust and today's Bronze in the "now" chip.
        $this->assertStringContainsString('fa-times', $html);
        $this->assertStringContainsString('Now: Bronze', html_entity_decode($html));
        $this->assertStringContainsString('now:', $html);
        // The 12-month counts: the order against the tiers, confirmed through the warning (the BUY tooltip).
        $this->assertStringContainsString('12M vs tiers', $html);
        $this->assertStringContainsString('confirmed through the warning', $html);
    }

    public function test_a_saved_snapshot_cannot_be_changed(): void
    {
        $this->_setTier(Tiers::SILVER);
        $this->_store();
        $snapshot = $this->_order()->tierSnapshot;

        $snapshot->effective_tier = Tiers::GOLD;
        $this->assertFalse($snapshot->save());
        $this->assertSame(Tiers::SILVER, $snapshot->fresh()->effective_tier);
    }
}
