<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

use ovidiuro\myfinance2\App\Models\Account;
use ovidiuro\myfinance2\App\Models\Currency;
use ovidiuro\myfinance2\App\Models\Order;
use ovidiuro\myfinance2\App\Models\TierSnapshot;
use ovidiuro\myfinance2\App\Models\Trade;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;
use ovidiuro\myfinance2\App\Services\LiveTierReadings;
use ovidiuro\myfinance2\App\Services\OrderTierWarnings;
use ovidiuro\myfinance2\App\Services\RebuiltTierReadings;
use ovidiuro\myfinance2\App\Services\TierCalculationService as Tiers;
use ovidiuro\myfinance2\App\Services\TierReadingAsOf;
use ovidiuro\myfinance2\App\Services\TierReadingBuilder;
use ovidiuro\myfinance2\App\Services\TierSnapshotBackfill;

/**
 * The tier reading saved with a trade (TierSnapshot): the order's when the trade fills an order, live for a manual
 * trade dated today, rebuilt for a past date; kept on an edit unless the symbol, action or date changes; the order's
 * taken over when the trade is linked to an order later, unless the trade's own is live. The live pipeline and the
 * stored history are replaced by fakes (no quotes, no network); only the synthetic TST.TRD symbol is written, and
 * everything is rolled back.
 */
class TradeTierSnapshotsFeatureTest extends TestCase
{
    use DatabaseTransactions;

    private const SYMBOL = 'TST.TRD';

    private ?User $_user = null;

    private ?Account $_account = null;

    private ?Currency $_currency = null;

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
        $this->_account  = Account::where('is_trade_account', 1)->first();
        $this->_currency = Currency::where('is_trade_currency', 1)->first();
        if (!$this->_account || !$this->_currency) {
            $this->markTestSkipped('Requires a trade account and a trade currency');
        }
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
        $this->_setLiveTier(Tiers::SILVER);

        // A past date reads Rust from the "stored history".
        app()->instance(RebuiltTierReadings::class, new class extends RebuiltTierReadings
        {
            public function read(int $userId, string $symbol, string $day): array
            {
                $reading = TierReadingBuilder::fromCategorization($symbol, [
                    'effective_tier' => Tiers::RUST, 'tier_tooltip' => 'Rebuilt Rust.', 'explanation' => '',
                ]);

                return ['source' => TierReadingAsOf::SOURCE_REBUILT, 'as_of' => $day, 'reading' => $reading + [
                    'facts'    => ['has_reading' => true, 'symbol' => $symbol, 'effective_tier' => Tiers::RUST,
                        'index_gap_settled_tier' => Tiers::RUST],
                    'position' => null,
                ]];
            }
        });
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        parent::tearDown();
    }

    private function _setLiveTier(string $tier): void
    {
        $this->_live->data = [
            'taken_at' => now()->format('Y-m-d H:i:s'),
            'as_of'    => now()->format('Y-m-d'),
            'stale'    => [],
            'symbols'  => [self::SYMBOL => [
                'reading'  => TierReadingBuilder::fromCategorization(self::SYMBOL, [
                    'effective_tier' => $tier, 'tier_tooltip' => 'Live ' . $tier . '.', 'explanation' => '',
                ]),
                'facts'    => ['has_reading' => true, 'symbol' => self::SYMBOL, 'effective_tier' => $tier,
                    'index_gap_settled_tier' => $tier],
                'position' => null,
            ]],
        ];
    }

    private function _payload(array $fields = []): array
    {
        return array_merge([
            'timestamp'         => now()->format('Y-m-d H:i:s'),
            'account_id'        => $this->_account->id,
            'trade_currency_id' => $this->_currency->id,
            'action'            => 'BUY',
            'exchange_rate'     => '1',
            'symbol'            => self::SYMBOL,
            'quantity'          => '2',
            'unit_price'        => '50',
            'fee'               => '1',
            'description'       => 'tier snapshot test',
        ], $fields);
    }

    private function _store(array $fields = []): Trade
    {
        $this->withoutMiddleware()->post(route('myfinance2::trades.store'), $this->_payload($fields))
            ->assertRedirect(route('myfinance2::trades.index'));

        return Trade::with('tierSnapshot')->where('symbol', self::SYMBOL)->latest('id')->firstOrFail();
    }

    private function _update(Trade $trade, array $fields = []): Trade
    {
        $this->withoutMiddleware()->put(route('myfinance2::trades.update', $trade->id), $this->_payload($fields))
            ->assertRedirect(route('myfinance2::trades.index'));

        return Trade::with('tierSnapshot')->findOrFail($trade->id);
    }

    private function _order(): Order
    {
        $order = Order::create([
            'symbol' => self::SYMBOL, 'action' => 'BUY', 'status' => 'FILLED', 'quantity' => 2, 'limit_price' => 50,
        ]);
        $snapshot = TierSnapshot::create([
            'symbol' => self::SYMBOL, 'action' => 'BUY', 'as_of_date' => now()->format('Y-m-d'),
            'taken_at' => now(), 'evaluated_at' => now(), 'source' => TierSnapshot::SOURCE_LIVE,
            'schema_version' => TierReadingBuilder::SCHEMA_VERSION, 'effective_tier' => Tiers::BRONZE,
            'verdict' => OrderTierWarnings::VERDICT_AGAINST, 'acknowledged_at' => now(),
            'reading' => TierReadingBuilder::unrated(self::SYMBOL, 'Order reading.'),
            'warnings' => [['code' => OrderTierWarnings::BUY_WEAK_TIER, 'level' => 'serious', 'label' => 'Weak tier',
                'text' => 'Bronze.']],
        ]);
        $order->tier_snapshot_id = $snapshot->id;
        $order->save();

        return $order->load('tierSnapshot');
    }

    public function test_a_trade_filling_an_order_shares_the_orders_reading(): void
    {
        $order = $this->_order();
        $trade = $this->_store(['order_id' => $order->id]);

        $this->assertSame($order->tier_snapshot_id, $trade->tier_snapshot_id);
        $this->assertSame($trade->id, $order->fresh()->trade_id);
    }

    public function test_a_manual_trade_dated_today_takes_a_live_reading_without_a_confirmation(): void
    {
        $trade = $this->_store();

        $this->assertSame(TierSnapshot::SOURCE_LIVE, $trade->tierSnapshot->source);
        $this->assertSame(Tiers::SILVER, $trade->tierSnapshot->effective_tier);
        $this->assertSame(OrderTierWarnings::VERDICT_SOFT, $trade->tierSnapshot->verdict);
        $this->assertNull($trade->tierSnapshot->acknowledged_at);
    }

    public function test_a_manual_trade_with_a_past_timestamp_gets_a_rebuilt_reading(): void
    {
        $trade = $this->_store(['timestamp' => '2025-09-12 10:00:00']);

        $this->assertSame(TierSnapshot::SOURCE_REBUILT, $trade->tierSnapshot->source);
        $this->assertSame('2025-09-12', $trade->tierSnapshot->as_of_date->format('Y-m-d'));
        $this->assertSame(OrderTierWarnings::VERDICT_AGAINST, $trade->tierSnapshot->verdict);
    }

    public function test_an_edit_keeps_the_reading_unless_the_decision_changes(): void
    {
        $trade    = $this->_store();
        $original = $trade->tier_snapshot_id;

        $this->_setLiveTier(Tiers::GOLD);
        $this->assertSame($original, $this->_update($trade, ['fee' => '2'])->tier_snapshot_id);

        // A new date is a new decision: a reading rebuilt for that date, the old one retired.
        $moved = $this->_update($trade, ['timestamp' => '2025-09-12 10:00:00']);
        $this->assertNotSame($original, $moved->tier_snapshot_id);
        $this->assertSame(TierSnapshot::SOURCE_REBUILT, $moved->tierSnapshot->source);
        $this->assertSoftDeleted(TierSnapshot::withTrashed()->find($original));
    }

    public function test_linking_takes_the_orders_reading_over_a_rebuilt_one_but_not_a_live_one(): void
    {
        $rebuilt = $this->_store(['timestamp' => '2025-09-12 10:00:00']);
        $order   = $this->_order();
        $this->withoutMiddleware()->post(route('myfinance2::orders.link-trade', $order->id), [
            'trade_id' => $rebuilt->id,
        ]);
        $this->assertSame($order->tier_snapshot_id, $rebuilt->fresh()->tier_snapshot_id);

        $live  = $this->_store();
        $other = $this->_order();
        $this->withoutMiddleware()->post(route('myfinance2::orders.link-trade', $other->id), [
            'trade_id' => $live->id,
        ]);
        $this->assertSame($live->tier_snapshot_id, $live->fresh()->tier_snapshot_id);
    }

    /**
     * An order and the trade that filled it, both from before readings were saved (no snapshot).
     *
     * @return array{0: Order, 1: Trade}
     */
    private function _legacyPair(): array
    {
        $trade = Trade::create([
            'timestamp' => '2025-09-12 10:00:00', 'account_id' => $this->_account->id,
            'trade_currency_id' => $this->_currency->id, 'action' => 'BUY', 'exchange_rate' => 1,
            'symbol' => self::SYMBOL, 'quantity' => 2, 'unit_price' => 50, 'fee' => 1,
        ]);
        $order = Order::create([
            'symbol' => self::SYMBOL, 'action' => 'BUY', 'status' => 'FILLED', 'quantity' => 2, 'limit_price' => 50,
            'placed_at' => '2025-09-11 15:00:00', 'trade_id' => $trade->id,
        ]);

        return [$order, $trade];
    }

    public function test_the_backfill_rebuilds_an_order_and_its_trade_shares_it(): void
    {
        [$order, $trade] = $this->_legacyPair();
        $backfill = TierSnapshotBackfill::make();
        $userId   = (int) $this->_user->id;

        $dry = $backfill->run($userId, TierSnapshotBackfill::ONLY_ORDERS, $order->id, false, true);
        $this->assertSame(TierSnapshotBackfill::RESULT_CREATED, $dry[0]['result']);
        $this->assertNull($order->fresh()->tier_snapshot_id);

        $backfill->run($userId, TierSnapshotBackfill::ONLY_ORDERS, $order->id, false, false);
        $rows = TierSnapshotBackfill::make()->run($userId, TierSnapshotBackfill::ONLY_TRADES, $trade->id, false, false);

        $order->refresh();
        $this->assertSame(TierSnapshot::SOURCE_REBUILT, $order->tierSnapshot->source);
        $this->assertSame('2025-09-11', $order->tierSnapshot->as_of_date->format('Y-m-d'));
        $this->assertSame($order->tier_snapshot_id, $trade->fresh()->tier_snapshot_id);
        $this->assertStringStartsWith(TierSnapshotBackfill::RESULT_SHARED, $rows[0]['result']);
        // The backfill does not change when the rows were last updated.
        $this->assertEquals($order->created_at, $order->updated_at);
    }

    public function test_the_backfill_leaves_a_saved_reading_alone_unless_forced(): void
    {
        [$order] = $this->_legacyPair();
        $userId  = (int) $this->_user->id;
        TierSnapshotBackfill::make()->run($userId, TierSnapshotBackfill::ONLY_ORDERS, $order->id, false, false);
        $first = $order->fresh()->tier_snapshot_id;

        $again = TierSnapshotBackfill::make()->run($userId, TierSnapshotBackfill::ONLY_ORDERS, $order->id, false,
            false);
        $this->assertSame([], $again);
        $this->assertSame($first, $order->fresh()->tier_snapshot_id);

        TierSnapshotBackfill::make()->run($userId, TierSnapshotBackfill::ONLY_ORDERS, $order->id, true, false);
        $this->assertNotSame($first, $order->fresh()->tier_snapshot_id);
        $this->assertSoftDeleted(TierSnapshot::withTrashed()->find($first));
    }

    public function test_the_trade_form_shows_the_orders_reading(): void
    {
        $order    = $this->_order();
        $response = $this->withoutMiddleware()->getJson(route('myfinance2::trades.tier-reading', [
            'symbol' => self::SYMBOL, 'action' => 'BUY', 'timestamp' => now()->format('Y-m-d H:i:s'),
            'order_id' => $order->id,
        ]))->assertOk();

        $this->assertStringContainsString('Tier reading copied from', $response->json('html'));
        $this->assertStringContainsString('order', $response->json('html'));
        $this->assertStringContainsString('#' . $order->id, $response->json('html'));
        $this->assertStringContainsString('Confirmed when order #' . $order->id, $response->json('html'));
    }
}
