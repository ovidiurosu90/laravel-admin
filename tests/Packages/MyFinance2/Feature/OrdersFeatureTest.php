<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use ovidiuro\myfinance2\App\Models\Account;
use ovidiuro\myfinance2\App\Models\Order;
use ovidiuro\myfinance2\App\Models\Trade;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;

/**
 * Feature tests for the Orders module (Phase 2).
 *
 * Covers: status lifecycle transitions, trade linking/unlinking,
 * duplicate warning on create, and soft-delete safety.
 *
 * DatabaseTransactions wraps each test in a rolled-back transaction,
 * so no test data reaches the production database.
 */
class OrdersFeatureTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Wrap both the admin (default) and myfinance2 connections in transactions
     * so all test data — including Order, Trade, Account models — is rolled back
     * after each test and never persists to the production database.
     */
    public function connectionsToTransact(): array
    {
        return [null, config('myfinance2.db_connection', 'myfinance2_mysql')];
    }

    private ?User $user = null;
    private ?Account $account = null;

    protected function setUp(): void
    {
        parent::setUp();

        AssignedToUserScope::enable();

        $this->user = User::first();

        if (!$this->user) {
            $this->markTestSkipped('Requires at least 1 user in database');
        }

        // actingAs so the Model creating() hook can assign user_id
        $this->actingAs($this->user);

        $this->account = Account::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->user->id)
            ->first();
    }

    protected function tearDown(): void
    {
        AssignedToUserScope::enable();
        // forgetGuards resets auth state without triggering a user model save,
        // which avoids QueryException from virtual attributes (e.g. theme) on the User model.
        Auth::forgetGuards();
        parent::tearDown();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function createOrder(array $fields): Order
    {
        return Order::create(array_merge([
            'symbol' => 'TEST.AS',
            'action' => 'BUY',
            'status' => 'DRAFT',
        ], $fields));
    }

    private function createPlacedOrder(array $overrides = []): Order
    {
        if (!$this->account) {
            $this->markTestSkipped('Requires at least 1 account for the test user');
        }

        return $this->createOrder(array_merge([
            'status'      => 'PLACED',
            'account_id'  => $this->account->id,
            'quantity'    => '10.00000000',
            'limit_price' => '100.0000',
            'placed_at'   => now(),
        ], $overrides));
    }

    private function postOrderAction(string $route, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->withoutMiddleware()
            ->actingAs($this->user)
            ->post($route, $data);
    }

    // =========================================================================
    // SMOKE TEST
    // =========================================================================

    /**
     * Basic sanity: the orders list is accessible.
     */
    public function test_index_returns_200(): void
    {
        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->get(route('myfinance2::orders.index'));

        $response->assertStatus(200);
        $response->assertViewIs('myfinance2::orders.crud.dashboard');
    }

    // =========================================================================
    // SOFT DELETE
    // =========================================================================

    /**
     * Deleted orders must be soft-deleted (recoverable), not hard-deleted.
     */
    public function test_destroy_soft_deletes_order(): void
    {
        $order = $this->createOrder(['status' => 'DRAFT']);
        $orderId = $order->id;

        $this->withoutMiddleware()
            ->actingAs($this->user)
            ->delete(route('myfinance2::orders.destroy', $orderId))
            ->assertRedirect(route('myfinance2::orders.index'))
            ->assertSessionHas('success');

        $this->assertNull(Order::find($orderId), 'Soft-deleted order should not appear in normal queries');

        $deleted = Order::withTrashed()->find($orderId);
        $this->assertNotNull($deleted, 'Soft-deleted order must still exist in the database');
        $this->assertNotNull($deleted->deleted_at);
    }

    // =========================================================================
    // DUPLICATE WARNING
    // =========================================================================

    /**
     * Open orders (DRAFT, PLACED) for the same symbol must appear in the
     * duplicate warning query — this is what the create form uses to warn the user.
     *
     * Tested at the model/query layer: the create form uses
     * Order::where('symbol', $symbol)->whereIn('status', ['DRAFT', 'PLACED'])->get()
     * directly (not via HTTP) because the form view requires an active session
     * (csrf_field), which is not available when withoutMiddleware() is used.
     */
    public function test_duplicate_warning_query_includes_open_orders(): void
    {
        $draft  = $this->createOrder(['symbol' => 'TEST.AS', 'status' => 'DRAFT']);
        $placed = $this->createOrder(['symbol' => 'TEST.AS', 'status' => 'PLACED']);

        $duplicates = Order::where('symbol', 'TEST.AS')
            ->whereIn('status', ['DRAFT', 'PLACED'])
            ->get();

        $this->assertTrue($duplicates->contains('id', $draft->id));
        $this->assertTrue($duplicates->contains('id', $placed->id));
    }

    /**
     * Terminal orders must not appear in the duplicate warning query.
     *
     * Uses created_at >= $testStart to ignore any pre-existing production data
     * for the same symbol, making the assertion independent of DB state.
     */
    public function test_duplicate_warning_query_excludes_terminal_orders(): void
    {
        $testStart = now();

        $this->createOrder(['symbol' => 'TEST.AS', 'status' => 'FILLED']);
        $this->createOrder(['symbol' => 'TEST.AS', 'status' => 'EXPIRED']);
        $this->createOrder(['symbol' => 'TEST.AS', 'status' => 'CANCELLED']);

        $duplicates = Order::where('symbol', 'TEST.AS')
            ->whereIn('status', ['DRAFT', 'PLACED'])
            ->where('created_at', '>=', $testStart)
            ->get();

        $this->assertCount(0, $duplicates, 'Terminal orders must not appear in the duplicate warning');
    }

    // =========================================================================
    // STATUS TRANSITION: PLACE (DRAFT → PLACED)
    // =========================================================================

    public function test_place_transitions_draft_to_placed(): void
    {
        if (!$this->account) {
            $this->markTestSkipped('Requires at least 1 account for the test user');
        }

        $order = $this->createOrder([
            'status'      => 'DRAFT',
            'account_id'  => $this->account->id,
            'quantity'    => '10.00000000',
            'limit_price' => '100.0000',
        ]);

        $this->postOrderAction(route('myfinance2::orders.place', $order->id))
            ->assertRedirect(route('myfinance2::orders.index'))
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals('PLACED', $order->status);
        $this->assertNotNull($order->placed_at);
    }

    /**
     * Placing a DRAFT order with missing required fields must redirect back to
     * the edit form, not silently place an incomplete order.
     */
    public function test_place_fails_when_required_fields_are_missing(): void
    {
        $order = $this->createOrder(['status' => 'DRAFT']); // no account, quantity, or limit_price

        $this->postOrderAction(route('myfinance2::orders.place', $order->id))
            ->assertRedirect(route('myfinance2::orders.edit', $order->id))
            ->assertSessionHas('error');

        $order->refresh();
        $this->assertEquals('DRAFT', $order->status); // unchanged
    }

    /**
     * Placing an already-PLACED order must be rejected to prevent duplicate timestamps.
     */
    public function test_place_fails_for_non_draft_order(): void
    {
        $order = $this->createPlacedOrder();

        $this->postOrderAction(route('myfinance2::orders.place', $order->id))
            ->assertRedirect(route('myfinance2::orders.index'))
            ->assertSessionHas('error');

        $order->refresh();
        $this->assertEquals('PLACED', $order->status); // unchanged
    }

    // =========================================================================
    // STATUS TRANSITION: FILL (PLACED → FILLED)
    // =========================================================================

    public function test_fill_transitions_placed_to_filled(): void
    {
        $order = $this->createPlacedOrder();

        $this->postOrderAction(route('myfinance2::orders.fill', $order->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals('FILLED', $order->status);
        $this->assertNotNull($order->filled_at);
    }

    /**
     * Filling a non-PLACED order must be rejected (e.g. double-fill attempt).
     */
    public function test_fill_fails_for_non_placed_order(): void
    {
        $order = $this->createOrder(['status' => 'DRAFT']);

        $this->postOrderAction(route('myfinance2::orders.fill', $order->id))
            ->assertRedirect(route('myfinance2::orders.index'))
            ->assertSessionHas('error');

        $order->refresh();
        $this->assertEquals('DRAFT', $order->status); // unchanged
    }

    /**
     * When fill is submitted with create_trade=1, the user must be redirected
     * to the trades create form pre-filled with the order data.
     */
    public function test_fill_with_create_trade_redirects_to_trades_create_form(): void
    {
        $order = $this->createPlacedOrder();

        $response = $this->postOrderAction(route('myfinance2::orders.fill', $order->id), [
            'create_trade' => '1',
        ]);

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/trades/create', $location);
        $this->assertStringContainsString('symbol=TEST.AS', $location);
        $this->assertStringContainsString('order_id=' . $order->id, $location);
    }

    // =========================================================================
    // STATUS TRANSITION: EXPIRE (PLACED → EXPIRED)
    // =========================================================================

    public function test_expire_transitions_placed_to_expired_at_end_of_day(): void
    {
        $placedAt = now()->subDay();
        $order = $this->createPlacedOrder(['placed_at' => $placedAt]);

        $this->postOrderAction(route('myfinance2::orders.expire', $order->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals('EXPIRED', $order->status);
        $this->assertEquals($placedAt->format('Y-m-d'), $order->expired_at->format('Y-m-d'));
        $this->assertEquals('23', $order->expired_at->format('H'));
        $this->assertEquals('59', $order->expired_at->format('i'));
    }

    public function test_expire_fails_for_non_placed_order(): void
    {
        $order = $this->createOrder(['status' => 'DRAFT']);

        $this->postOrderAction(route('myfinance2::orders.expire', $order->id))
            ->assertRedirect(route('myfinance2::orders.index'))
            ->assertSessionHas('error');

        $order->refresh();
        $this->assertEquals('DRAFT', $order->status); // unchanged
    }

    // =========================================================================
    // STATUS TRANSITION: CANCEL (non-terminal → CANCELLED)
    // =========================================================================

    public function test_cancel_transitions_non_terminal_order_to_cancelled(): void
    {
        $order = $this->createOrder(['status' => 'DRAFT']);

        $this->postOrderAction(route('myfinance2::orders.cancel', $order->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals('CANCELLED', $order->status);
    }

    public function test_cancel_fails_for_terminal_order(): void
    {
        $order = $this->createOrder(['status' => 'FILLED']);

        $this->postOrderAction(route('myfinance2::orders.cancel', $order->id))
            ->assertRedirect(route('myfinance2::orders.index'))
            ->assertSessionHas('error');

        $order->refresh();
        $this->assertEquals('FILLED', $order->status); // unchanged
    }

    // =========================================================================
    // STATUS TRANSITION: REOPEN (terminal → PLACED)
    // =========================================================================

    public function test_reopen_transitions_terminal_order_to_placed(): void
    {
        $order = $this->createOrder(['status' => 'EXPIRED', 'expired_at' => now()]);

        $this->postOrderAction(route('myfinance2::orders.reopen', $order->id))
            ->assertRedirect(route('myfinance2::orders.index'))
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals('PLACED', $order->status);
        $this->assertNull($order->expired_at);
        $this->assertNull($order->filled_at);
    }

    public function test_reopen_fails_for_non_terminal_order(): void
    {
        $order = $this->createOrder(['status' => 'DRAFT']);

        $this->postOrderAction(route('myfinance2::orders.reopen', $order->id))
            ->assertRedirect(route('myfinance2::orders.index'))
            ->assertSessionHas('error');

        $order->refresh();
        $this->assertEquals('DRAFT', $order->status); // unchanged
    }

    // =========================================================================
    // TRADE LINKING
    // =========================================================================

    public function test_link_trade_sets_trade_id(): void
    {
        $trade = Trade::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->user->id)
            ->first();

        if (!$trade) {
            $this->markTestSkipped('Requires at least 1 trade for the test user');
        }

        $order = $this->createOrder(['status' => 'FILLED']);

        $this->postOrderAction(
            route('myfinance2::orders.link-trade', $order->id),
            ['trade_id' => $trade->id]
        )
            ->assertRedirect(route('myfinance2::orders.index'))
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals($trade->id, $order->trade_id);
    }

    public function test_unlink_trade_clears_trade_id(): void
    {
        $trade = Trade::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->user->id)
            ->first();

        if (!$trade) {
            $this->markTestSkipped('Requires at least 1 trade for the test user');
        }

        $order = $this->createOrder(['status' => 'FILLED', 'trade_id' => $trade->id]);

        $this->postOrderAction(route('myfinance2::orders.unlink-trade', $order->id))
            ->assertRedirect(route('myfinance2::orders.index'))
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertNull($order->trade_id);
    }
}

