<?php
declare(strict_types=1);

/**
 * Template charts of accounts. The core chart is the same for every
 * business; only the equity section depends on how the business is taxed.
 * Packs (legal, and later other verticals) add their own accounts on top
 * through ledger_account_ensure().
 *
 * Each account: [code, name, type, statement_line, cash_flow_class, options]
 * options: key (system key), contra, accrual_only, control, equity_type.
 */

function ledger_template_core(): array
{
    return [
        ['1000', 'Operating cash',                 'asset',     'Cash and cash equivalents',   'cash',      ['key' => 'cash.operating']],
        ['1100', 'Accounts receivable',            'asset',     'Accounts receivable',         'operating', ['key' => 'ar', 'control' => 'receivable', 'accrual_only' => true]],
        ['1200', 'Prepaid expenses',               'asset',     'Prepaid and other current assets', 'operating', ['key' => 'prepaid', 'accrual_only' => true]],
        ['1500', 'Equipment and furniture',        'asset',     'Property and equipment, net', 'investing', ['key' => 'fixed.equipment']],
        ['1590', 'Accumulated depreciation',       'asset',     'Property and equipment, net', 'non_cash',  ['key' => 'fixed.accum_depr', 'contra' => true]],
        ['2000', 'Accounts payable',               'liability', 'Accounts payable',            'operating', ['key' => 'ap', 'control' => 'payable', 'accrual_only' => true]],
        ['2100', 'Credit cards payable',           'liability', 'Other current liabilities',   'operating', ['key' => 'cards']],
        ['2200', 'Accrued liabilities',            'liability', 'Accrued liabilities',         'operating', ['key' => 'accrued', 'accrual_only' => true]],
        ['2300', 'Unapplied receipts',             'liability', 'Other current liabilities',   'operating', ['key' => 'unapplied_receipts']],
        ['2400', 'Customer credits',               'liability', 'Other current liabilities',   'operating', ['key' => 'customer_credits']],
        ['2700', 'Loans payable',                  'liability', 'Long-term debt',              'financing', ['key' => 'loans']],
        ['2710', 'Current portion of long-term debt', 'liability', 'Other current liabilities', 'financing', ['key' => 'loans_current']],
        ['3900', 'Retained earnings',              'equity',    'Retained earnings',           'none',      ['key' => 'equity.retained', 'equity_type' => 'retained_earnings']],
        ['3950', 'Opening balance equity',         'equity',    'Opening balance equity',      'none',      ['key' => 'equity.opening', 'equity_type' => 'opening_balance']],
        ['4000', 'Service revenue',                'revenue',   'Revenue',                     'none',      ['key' => 'revenue.services']],
        ['4900', 'Other income',                   'revenue',   'Other income',                'none',      ['key' => 'revenue.other']],
        ['5000', 'Cost of revenue',                'expense',   'Cost of revenue',             'none',      ['key' => 'cogs']],
        ['6000', 'Salaries and wages',             'expense',   'Operating expenses',          'none',      ['key' => 'exp.salaries']],
        ['6100', 'Rent',                           'expense',   'Operating expenses',          'none',      ['key' => 'exp.rent']],
        ['6200', 'Software and subscriptions',     'expense',   'Operating expenses',          'none',      ['key' => 'exp.software']],
        ['6300', 'Office expenses',                'expense',   'Operating expenses',          'none',      ['key' => 'exp.office']],
        ['6400', 'Professional fees',              'expense',   'Operating expenses',          'none',      ['key' => 'exp.professional']],
        ['6500', 'Insurance',                      'expense',   'Operating expenses',          'none',      ['key' => 'exp.insurance']],
        ['6600', 'Marketing',                      'expense',   'Operating expenses',          'none',      ['key' => 'exp.marketing']],
        ['6700', 'Travel',                         'expense',   'Operating expenses',          'none',      ['key' => 'exp.travel']],
        ['6800', 'Depreciation',                   'expense',   'Operating expenses',          'none',      ['key' => 'exp.depreciation']],
        ['6900', 'Bad debt',                       'expense',   'Operating expenses',          'none',      ['key' => 'exp.bad_debt']],
        ['7000', 'Interest expense',               'expense',   'Other income and expense',    'none',      ['key' => 'exp.interest']],
        ['7100', 'Bank and processing fees',       'expense',   'Operating expenses',          'none',      ['key' => 'exp.bank_fees']],
    ];
}

/** The equity section (and the few accounts that go with it) for a tax treatment. */
function ledger_template_equity(string $treatment): array
{
    return match ($treatment) {
        'sole_proprietor' => [
            ['3000', "Owner's capital",        'equity', "Owner's equity", 'financing', ['key' => 'equity.capital', 'equity_type' => 'capital']],
            ['3050', 'Owner contributions',    'equity', "Owner's equity", 'financing', ['key' => 'equity.contributions', 'equity_type' => 'contributions']],
            ['3100', "Owner's draws",          'equity', "Owner's equity", 'financing', ['key' => 'equity.draws', 'equity_type' => 'draws']],
        ],
        'partnership' => [
            ['3000', "Partners' capital",      'equity', "Partners' capital", 'financing', ['key' => 'equity.capital', 'equity_type' => 'capital']],
            ['3050', 'Partner contributions',  'equity', "Partners' capital", 'financing', ['key' => 'equity.contributions', 'equity_type' => 'contributions']],
            ['3100', "Partners' draws",        'equity', "Partners' capital", 'financing', ['key' => 'equity.draws', 'equity_type' => 'draws']],
            ['6050', 'Guaranteed payments to partners', 'expense', 'Operating expenses', 'none', ['key' => 'exp.guaranteed_payments']],
        ],
        's_corp' => [
            ['3000', 'Common stock',               'equity', "Shareholders' equity", 'financing', ['key' => 'equity.stock', 'equity_type' => 'stock']],
            ['3010', 'Additional paid-in capital', 'equity', "Shareholders' equity", 'financing', ['key' => 'equity.apic', 'equity_type' => 'paid_in_capital']],
            ['3100', 'Shareholder distributions',  'equity', "Shareholders' equity", 'financing', ['key' => 'equity.distributions', 'equity_type' => 'distributions']],
        ],
        'c_corp' => [
            ['3000', 'Common stock',               'equity', "Shareholders' equity", 'financing', ['key' => 'equity.stock', 'equity_type' => 'stock']],
            ['3010', 'Additional paid-in capital', 'equity', "Shareholders' equity", 'financing', ['key' => 'equity.apic', 'equity_type' => 'paid_in_capital']],
            ['3100', 'Dividends paid',             'equity', "Shareholders' equity", 'financing', ['key' => 'equity.distributions', 'equity_type' => 'distributions']],
            ['7900', 'Income tax expense',         'expense', 'Income tax expense',  'none',      ['key' => 'exp.income_tax']],
        ],
        default => [
            ['3000', "Owner's equity",             'equity', "Owner's equity", 'financing', ['key' => 'equity.capital', 'equity_type' => 'capital']],
            ['3100', "Owner's draws",              'equity', "Owner's equity", 'financing', ['key' => 'equity.draws', 'equity_type' => 'draws']],
        ],
    };
}

/** Which tax treatments a legal form can elect. */
const LEDGER_FORM_TREATMENTS = [
    'unspecified'              => ['unspecified'],
    'sole_proprietor'          => ['sole_proprietor'],
    'single_member_llc'        => ['sole_proprietor', 's_corp', 'c_corp'],
    'multi_member_llc'         => ['partnership', 's_corp', 'c_corp'],
    'partnership'              => ['partnership'],
    'pllc'                     => ['sole_proprietor', 'partnership', 's_corp', 'c_corp'],
    'professional_corporation' => ['s_corp', 'c_corp'],
    'corporation'              => ['s_corp', 'c_corp'],
];
