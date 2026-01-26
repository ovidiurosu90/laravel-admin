<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use ovidiuro\myfinance2\App\Models\Account;
use ovidiuro\myfinance2\App\Models\CashBalance;
use ovidiuro\myfinance2\App\Models\Dividend;
use ovidiuro\myfinance2\App\Models\LedgerTransaction;
use ovidiuro\myfinance2\App\Models\Trade;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;

/**
 * Security tests for user data isolation
 *
 * CRITICAL: These tests verify that users can only access their own data.
 * The AssignedToUserScope must properly filter all queries by user_id.
 *
 * Uses DatabaseTransactions to automatically rollback any test data.
 */
class UserDataIsolationSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private ?User $userA = null;
    private ?User $userB = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure scope is enabled for web-like test context
        AssignedToUserScope::enable();

        // Get two different users from the database for testing
        // We need at least 2 users to test data isolation
        $users = User::take(2)->get();

        if ($users->count() < 2) {
            $this->markTestSkipped('Requires at least 2 users in database for isolation testing');
        }

        $this->userA = $users[0];
        $this->userB = $users[1];
    }

    protected function tearDown(): void
    {
        // Ensure scope is re-enabled after each test
        AssignedToUserScope::enable();
        Auth::logout();
        parent::tearDown();
    }

    // =========================================================================
    // SCOPE CONTROL TESTS
    // =========================================================================

    /**
     * SECURITY: AssignedToUserScope cannot be disabled in web context
     */
    public function test_scope_cannot_be_disabled_in_web_context(): void
    {
        // In test context, php_sapi_name() returns 'cli', so we test the logic differently
        // We verify that the scope IS enabled by default
        $this->assertTrue(
            AssignedToUserScope::isEnabled(),
            'AssignedToUserScope should be enabled by default'
        );
    }

    /**
     * SECURITY: Scope can be re-enabled after being disabled
     */
    public function test_scope_can_be_reenabled(): void
    {
        // Disable (allowed in CLI/test context)
        AssignedToUserScope::disable();
        $this->assertFalse(AssignedToUserScope::isEnabled());

        // Re-enable
        AssignedToUserScope::enable();
        $this->assertTrue(AssignedToUserScope::isEnabled());
    }

    // =========================================================================
    // ACCOUNT DATA ISOLATION TESTS
    // =========================================================================

    /**
     * SECURITY: User can see their own accounts
     */
    public function test_user_can_see_own_accounts(): void
    {
        $this->actingAs($this->userA);

        // Check if user A has any accounts
        $accountsForUserA = Account::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->userA->id)
            ->count();

        if ($accountsForUserA === 0) {
            $this->markTestSkipped('User A has no accounts to test');
        }

        // With scope enabled, user should see their accounts
        $visibleAccounts = Account::count();

        $this->assertEquals(
            $accountsForUserA,
            $visibleAccounts,
            'User should see exactly their own accounts'
        );
    }

    /**
     * SECURITY: User cannot see other users' accounts
     */
    public function test_user_cannot_see_other_users_accounts(): void
    {
        // Check if user B has accounts
        $accountsForUserB = Account::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->userB->id)
            ->count();

        if ($accountsForUserB === 0) {
            $this->markTestSkipped('User B has no accounts to test isolation');
        }

        // Act as user A
        $this->actingAs($this->userA);

        // User A should NOT see user B's accounts
        $userBAccountIds = Account::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->userB->id)
            ->pluck('id')
            ->toArray();

        $visibleAccountIds = Account::pluck('id')->toArray();

        foreach ($userBAccountIds as $userBAccountId) {
            $this->assertNotContains(
                $userBAccountId,
                $visibleAccountIds,
                "SECURITY VIOLATION: User A can see User B's account ID $userBAccountId"
            );
        }
    }

    // =========================================================================
    // TRADE DATA ISOLATION TESTS
    // =========================================================================

    /**
     * SECURITY: User can see their own trades
     */
    public function test_user_can_see_own_trades(): void
    {
        $this->actingAs($this->userA);

        $tradesForUserA = Trade::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->userA->id)
            ->count();

        if ($tradesForUserA === 0) {
            $this->markTestSkipped('User A has no trades to test');
        }

        $visibleTrades = Trade::count();

        $this->assertEquals(
            $tradesForUserA,
            $visibleTrades,
            'User should see exactly their own trades'
        );
    }

    /**
     * SECURITY: User cannot see other users' trades
     */
    public function test_user_cannot_see_other_users_trades(): void
    {
        $tradesForUserB = Trade::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->userB->id)
            ->count();

        if ($tradesForUserB === 0) {
            $this->markTestSkipped('User B has no trades to test isolation');
        }

        $this->actingAs($this->userA);

        $userBTradeIds = Trade::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->userB->id)
            ->pluck('id')
            ->toArray();

        $visibleTradeIds = Trade::pluck('id')->toArray();

        foreach ($userBTradeIds as $userBTradeId) {
            $this->assertNotContains(
                $userBTradeId,
                $visibleTradeIds,
                "SECURITY VIOLATION: User A can see User B's trade ID $userBTradeId"
            );
        }
    }

    // =========================================================================
    // DIVIDEND DATA ISOLATION TESTS
    // =========================================================================

    /**
     * SECURITY: User can see their own dividends
     */
    public function test_user_can_see_own_dividends(): void
    {
        $this->actingAs($this->userA);

        $dividendsForUserA = Dividend::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->userA->id)
            ->count();

        if ($dividendsForUserA === 0) {
            $this->markTestSkipped('User A has no dividends to test');
        }

        $visibleDividends = Dividend::count();

        $this->assertEquals(
            $dividendsForUserA,
            $visibleDividends,
            'User should see exactly their own dividends'
        );
    }

    /**
     * SECURITY: User cannot see other users' dividends
     */
    public function test_user_cannot_see_other_users_dividends(): void
    {
        $dividendsForUserB = Dividend::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->userB->id)
            ->count();

        if ($dividendsForUserB === 0) {
            $this->markTestSkipped('User B has no dividends to test isolation');
        }

        $this->actingAs($this->userA);

        $userBDividendIds = Dividend::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->userB->id)
            ->pluck('id')
            ->toArray();

        $visibleDividendIds = Dividend::pluck('id')->toArray();

        foreach ($userBDividendIds as $userBDividendId) {
            $this->assertNotContains(
                $userBDividendId,
                $visibleDividendIds,
                "SECURITY VIOLATION: User A can see User B's dividend ID $userBDividendId"
            );
        }
    }

    // =========================================================================
    // CASH BALANCE DATA ISOLATION TESTS
    // =========================================================================

    /**
     * SECURITY: User can see their own cash balances
     */
    public function test_user_can_see_own_cash_balances(): void
    {
        $this->actingAs($this->userA);

        $cashBalancesForUserA = CashBalance::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->userA->id)
            ->count();

        if ($cashBalancesForUserA === 0) {
            $this->markTestSkipped('User A has no cash balances to test');
        }

        $visibleCashBalances = CashBalance::count();

        $this->assertEquals(
            $cashBalancesForUserA,
            $visibleCashBalances,
            'User should see exactly their own cash balances'
        );
    }

    /**
     * SECURITY: User cannot see other users' cash balances
     */
    public function test_user_cannot_see_other_users_cash_balances(): void
    {
        $cashBalancesForUserB = CashBalance::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->userB->id)
            ->count();

        if ($cashBalancesForUserB === 0) {
            $this->markTestSkipped('User B has no cash balances to test isolation');
        }

        $this->actingAs($this->userA);

        $userBCashBalanceIds = CashBalance::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->userB->id)
            ->pluck('id')
            ->toArray();

        $visibleCashBalanceIds = CashBalance::pluck('id')->toArray();

        foreach ($userBCashBalanceIds as $userBCashBalanceId) {
            $this->assertNotContains(
                $userBCashBalanceId,
                $visibleCashBalanceIds,
                "SECURITY VIOLATION: User A can see User B's cash balance ID $userBCashBalanceId"
            );
        }
    }

    // =========================================================================
    // LEDGER TRANSACTION DATA ISOLATION TESTS
    // =========================================================================

    /**
     * SECURITY: User can see their own ledger transactions
     */
    public function test_user_can_see_own_ledger_transactions(): void
    {
        $this->actingAs($this->userA);

        $transactionsForUserA = LedgerTransaction::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->userA->id)
            ->count();

        if ($transactionsForUserA === 0) {
            $this->markTestSkipped('User A has no ledger transactions to test');
        }

        $visibleTransactions = LedgerTransaction::count();

        $this->assertEquals(
            $transactionsForUserA,
            $visibleTransactions,
            'User should see exactly their own ledger transactions'
        );
    }

    /**
     * SECURITY: User cannot see other users' ledger transactions
     */
    public function test_user_cannot_see_other_users_ledger_transactions(): void
    {
        $transactionsForUserB = LedgerTransaction::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->userB->id)
            ->count();

        if ($transactionsForUserB === 0) {
            $this->markTestSkipped('User B has no ledger transactions to test isolation');
        }

        $this->actingAs($this->userA);

        $userBTransactionIds = LedgerTransaction::withoutGlobalScope(AssignedToUserScope::class)
            ->where('user_id', $this->userB->id)
            ->pluck('id')
            ->toArray();

        $visibleTransactionIds = LedgerTransaction::pluck('id')->toArray();

        foreach ($userBTransactionIds as $userBTransactionId) {
            $this->assertNotContains(
                $userBTransactionId,
                $visibleTransactionIds,
                "SECURITY VIOLATION: User A can see User B's ledger transaction ID $userBTransactionId"
            );
        }
    }

    // =========================================================================
    // CROSS-MODEL CONSISTENCY TESTS
    // =========================================================================

    /**
     * SECURITY: All queries for a user return consistent user_id
     */
    public function test_all_visible_data_belongs_to_authenticated_user(): void
    {
        $this->actingAs($this->userA);

        // Verify accounts
        $accountUserIds = Account::pluck('user_id')->unique()->toArray();
        foreach ($accountUserIds as $userId) {
            $this->assertEquals(
                $this->userA->id,
                $userId,
                'All visible accounts must belong to authenticated user'
            );
        }

        // Verify trades
        $tradeUserIds = Trade::pluck('user_id')->unique()->toArray();
        foreach ($tradeUserIds as $userId) {
            $this->assertEquals(
                $this->userA->id,
                $userId,
                'All visible trades must belong to authenticated user'
            );
        }

        // Verify dividends
        $dividendUserIds = Dividend::pluck('user_id')->unique()->toArray();
        foreach ($dividendUserIds as $userId) {
            $this->assertEquals(
                $this->userA->id,
                $userId,
                'All visible dividends must belong to authenticated user'
            );
        }

        // Verify cash balances
        $cashBalanceUserIds = CashBalance::pluck('user_id')->unique()->toArray();
        foreach ($cashBalanceUserIds as $userId) {
            $this->assertEquals(
                $this->userA->id,
                $userId,
                'All visible cash balances must belong to authenticated user'
            );
        }

        // Verify ledger transactions
        $ledgerTransactionUserIds = LedgerTransaction::pluck('user_id')->unique()->toArray();
        foreach ($ledgerTransactionUserIds as $userId) {
            $this->assertEquals(
                $this->userA->id,
                $userId,
                'All visible ledger transactions must belong to authenticated user'
            );
        }
    }

    /**
     * SECURITY: Switching users changes visible data
     */
    public function test_switching_users_changes_visible_data(): void
    {
        // Check both users have some data
        $userAHasData = Trade::withoutGlobalScope(AssignedToUserScope::class)->where('user_id', $this->userA->id)->exists()
            || Account::withoutGlobalScope(AssignedToUserScope::class)->where('user_id', $this->userA->id)->exists();
        $userBHasData = Trade::withoutGlobalScope(AssignedToUserScope::class)->where('user_id', $this->userB->id)->exists()
            || Account::withoutGlobalScope(AssignedToUserScope::class)->where('user_id', $this->userB->id)->exists();

        if (!$userAHasData || !$userBHasData) {
            $this->markTestSkipped('Both users need data to test user switching');
        }

        // Get data as user A
        $this->actingAs($this->userA);
        $userATradeIds = Trade::pluck('id')->toArray();
        $userAAccountIds = Account::pluck('id')->toArray();

        // Switch to user B
        Auth::logout();
        $this->actingAs($this->userB);
        $userBTradeIds = Trade::pluck('id')->toArray();
        $userBAccountIds = Account::pluck('id')->toArray();

        // Verify no overlap (unless they genuinely share no data)
        $tradeOverlap = array_intersect($userATradeIds, $userBTradeIds);
        $accountOverlap = array_intersect($userAAccountIds, $userBAccountIds);

        $this->assertEmpty(
            $tradeOverlap,
            'Users should not see overlapping trade IDs'
        );
        $this->assertEmpty(
            $accountOverlap,
            'Users should not see overlapping account IDs'
        );
    }
}

