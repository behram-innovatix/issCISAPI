<?php

use App\Sync\MartSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Mart tables for these streams; columns come from config/cis_streams.php.
     *
     * @var list<string>
     */
    private array $streams = [
        'fin.fee_accounts',
        'fin.invoices',
        'fin.payments',
        'fin.payroll_employees',
        'fin.payroll_monthly',
        'fin.budgets',
        'fin.budget_actuals',
        'fin.income',
        'fin.cash_balances',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->streams as $stream) {
            MartSchema::create($stream);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->streams) as $stream) {
            MartSchema::drop($stream);
        }
    }
};
