<?php

declare(strict_types=1);

namespace Tests\Packages\MyFinance2;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use ovidiuro\myfinance2\App\Models\Account;
use ovidiuro\myfinance2\App\Models\Scopes\AssignedToUserScope;
use ovidiuro\myfinance2\App\Services\Positions;
use ovidiuro\myfinance2\App\Services\Returns\Returns;
use Tests\TestCase;

/**
 * On-demand consistency check between /returns (current year) and /positions, on the live data.
 *
 * For the current year, /returns values each account as of today (its Dec 31 side is today) by
 * netting ALL trades up to today, OPEN and CLOSED alike. /positions only sums the trades still
 * marked OPEN. Both must therefore agree on what each account holds today; when they do not, a
 * trade's OPEN/CLOSED status is out of sync with the real quantity (e.g. a sell marked CLOSED
 * while its buys stay OPEN), or /returns silently dropped a holding from its valuation. Cash comes
 * from the same cash balance history on both sides and must match too.
 *
 * Prices are deliberately not compared: /returns uses the regular market price (cached up to an
 * hour, with price overrides) while /positions uses the live quote including pre/post-market.
 *
 * Run it before closing a year, or after editing trade statuses or position date overrides:
 *   php artisan test tests/Packages/MyFinance2/ReturnsPositionsHoldingsTest.php
 *
 * Read-only: both connections are rolled back, no User is saved, and no stats are persisted
 * (Positions with persistStats off; Returns never persists). It fetches today's quotes from
 * Yahoo Finance (the test cache starts empty), so expect it to take a while.
 */
class ReturnsPositionsHoldingsTest extends TestCase
{
    use DatabaseTransactions;

    private const _QTY_EPSILON = 0.000001;
    private const _CASH_TOLERANCE = 0.01;

    /**
     * Both sides per user and account, collected once for all test methods:
     * [userId => [accountId => ['name', 'positions' => [symbol => qty], 'returns' => [symbol => qty],
     *  'skipped' => [symbol, ...], 'positions_cash' => ?float, 'returns_cash' => ?float]]]
     */
    private static ?array $_sides = null;

    public function connectionsToTransact(): array
    {
        return [null, config('myfinance2.db_connection', 'myfinance2_mysql')];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$_sides === null) {
            self::$_sides = $this->_collectSides();
        }
        if (empty(self::$_sides)) {
            $this->markTestSkipped('No user with trade accounts and resolvable positions found.');
        }
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        parent::tearDown();
    }

    public function test_returns_and_positions_hold_the_same_quantities_today(): void
    {
        $mismatches = [];

        foreach (self::$_sides as $userId => $accounts) {
            foreach ($accounts as $side) {
                $symbols = array_unique(array_merge(array_keys($side['positions']), array_keys($side['returns'])));
                sort($symbols);
                foreach ($symbols as $symbol) {
                    if (in_array($symbol, $side['skipped'], true)) {
                        continue;
                    }
                    $positionsQty = $side['positions'][$symbol] ?? 0.0;
                    $returnsQty = $side['returns'][$symbol] ?? 0.0;
                    if (abs($positionsQty - $returnsQty) > self::_QTY_EPSILON) {
                        $mismatches[] = sprintf('user %d, %s, %s: /positions (OPEN trades) %s vs /returns '
                            . '(all trades up to today) %s', $userId, $side['name'], $symbol,
                            $positionsQty, $returnsQty);
                    }
                }
            }
        }

        $this->assertSame([], $mismatches, "Holdings differ between /positions and /returns today.\n"
            . "Usual causes: a trade's OPEN/CLOSED status out of sync with its quantity, a trade "
            . "timestamped later today, or a position /returns could not value (missing exchange "
            . "rate).\n" . implode("\n", $mismatches));
    }

    public function test_returns_and_positions_report_the_same_cash_today(): void
    {
        $mismatches = [];

        foreach (self::$_sides as $userId => $accounts) {
            foreach ($accounts as $side) {
                if ($side['positions_cash'] === null || $side['returns_cash'] === null) {
                    continue;
                }
                if (abs($side['positions_cash'] - $side['returns_cash']) > self::_CASH_TOLERANCE) {
                    $mismatches[] = sprintf('user %d, %s: /positions cash %.2f vs /returns cash today %.2f',
                        $userId, $side['name'], $side['positions_cash'], $side['returns_cash']);
                }
            }
        }

        $this->assertSame([], $mismatches, "Cash differs between /positions and /returns today "
            . "(a cash balance entry timestamped later today shows up only in /returns).\n"
            . implode("\n", $mismatches));
    }

    /**
     * Run both pipelines as each user owning trade accounts (read-only actingAs: the user scope
     * of both pipelines follows the authenticated user).
     */
    private function _collectSides(): array
    {
        $userIds = Account::withoutGlobalScope(AssignedToUserScope::class)
            ->where('is_trade_account', '1')
            ->distinct()
            ->pluck('user_id');
        $year = (int) date('Y');
        $sides = [];

        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if ($user === null) {
                continue;
            }
            $this->actingAs($user);

            $positionsService = new Positions();
            $positionsService->setPersistStats(false);
            $positions = $positionsService->handle();
            if ($positions === null) {
                continue;
            }
            $returns = (new Returns())->handle($year, (int) $userId);

            $sides[(int) $userId] = $this->_compareAccounts($positions, $returns);
            Auth::forgetGuards();
        }

        return $sides;
    }

    private function _compareAccounts(array $positions, array $returns): array
    {
        $accountIds = array_unique(array_merge(
            array_keys($positions['groupedItems'] ?? []),
            array_filter(array_keys($returns), 'is_int')
        ));
        $accounts = [];

        foreach ($accountIds as $accountId) {
            $positionsAccount = $positions['accountData'][$accountId] ?? null;
            $returnsAccount = $returns[$accountId] ?? null;
            $model = $positionsAccount['accountModel'] ?? $returnsAccount['account'] ?? null;

            $accounts[$accountId] = [
                'name' => $model->name ?? ('account ' . $accountId),
                'positions' => $this->_positionsHoldings($positions['groupedItems'][$accountId] ?? []),
                'returns' => $this->_returnsHoldings($returnsAccount ?? []),
                'skipped' => $this->_symbolsOverriddenToday((int) $accountId),
                'positions_cash' => $positionsAccount !== null && !empty($positionsAccount['cashBalanceUtils'])
                    ? (float) ($positionsAccount['cashBalanceUtils']->getAmount() ?? 0) : null,
                'returns_cash' => isset($returnsAccount['dec31CashValue'])
                    ? (float) $returnsAccount['dec31CashValue'] : null,
            ];
        }

        return $accounts;
    }

    private function _positionsHoldings(array $items): array
    {
        $holdings = [];
        foreach ($items as $symbol => $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            if (abs($quantity) > self::_QTY_EPSILON) {
                $holdings[$symbol] = $quantity;
            }
        }

        return $holdings;
    }

    /**
     * /returns holdings today: the valued positions plus the ones it could not price (those are
     * still held, only left out of the value and reported by the missing-quote alert).
     */
    private function _returnsHoldings(array $returnsAccount): array
    {
        $holdings = [];
        $rows = array_merge($returnsAccount['dec31PositionDetails'] ?? [], $returnsAccount['dec31MissingQuotes'] ?? []);
        foreach ($rows as $row) {
            $holdings[$row['symbol']] = ($holdings[$row['symbol']] ?? 0.0) + (float) $row['quantity'];
        }

        return array_filter($holdings, fn(float $quantity) => abs($quantity) > self::_QTY_EPSILON);
    }

    /**
     * Symbols a position date override for today replaces or removes in /returns
     * (ReturnsValuation::applyPositionDateOverrides); they differ from /positions by design.
     */
    private function _symbolsOverriddenToday(int $accountId): array
    {
        $override = config('trades.position_date_overrides', [])[date('Y-m-d')][$accountId] ?? [];

        return array_merge($override['exclude_symbols'] ?? [], array_keys($override['manual_positions'] ?? []));
    }
}
