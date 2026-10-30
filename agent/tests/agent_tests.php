<?php
declare(strict_types=1);

/**
 * Tests for the STRATICUS agent core. No database and no model: actions
 * are plain closures and the model is a scripted transport. Hosts run these
 * with their own check() / check_same().
 */

require_once dirname(__DIR__) . '/agent.php';

/** A transport that replays scripted replies and records what it was sent. */
function at_script(array $replies, array &$sent): callable
{
    return function (array $body) use (&$replies, &$sent) {
        $sent[] = $body;
        $r = array_shift($replies);
        if ($r === null) {
            throw new RuntimeException('the model was asked more often than scripted');
        }
        return ['content' => $r, 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]];
    };
}

function at_text(string $t): array
{
    return [['type' => 'text', 'text' => $t]];
}

function at_use(string $id, string $name, array $input): array
{
    return ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input];
}

function at_register(?array &$ran): void
{
    $reg = &agent_registry();
    $reg = [];
    $ran = [];
    agent_action(['name' => 'profit_and_loss', 'description' => 'The P&L for a period.', 'mode' => 'read',
        'input_schema' => ['type' => 'object', 'required' => ['from', 'to'], 'properties' => [
            'from' => ['type' => 'string', 'format' => 'date'], 'to' => ['type' => 'string', 'format' => 'date'],
            'basis' => ['type' => 'string', 'enum' => ['cash', 'accrual']]]],
        'allowed' => fn($c) => in_array($c['role'], ['owner', 'accountant'], true),
        'run' => function ($in) use (&$ran) { $ran[] = ['profit_and_loss', $in]; return ['revenue' => 3000000, 'expenses' => 900000, 'net_income' => 2100000, 'net_margin_pct' => 70.0]; }]);
    agent_action(['name' => 'record_expense', 'description' => 'Record an expense.', 'mode' => 'change', 'label' => 'Record an expense',
        'input_schema' => ['type' => 'object', 'required' => ['amount'], 'properties' => ['amount' => ['type' => 'integer', 'minimum' => 1]]],
        'allowed' => fn($c) => $c['role'] === 'owner',
        'summarize' => fn($in) => 'Record an expense of ' . $in['amount'] . ' cents',
        'run' => function ($in) use (&$ran) { $ran[] = ['record_expense', $in]; return ['recorded' => true]; }]);
}

function test_agent_offers_only_what_the_person_may_use(): void
{
    at_register($ran);
    check_same(['profit_and_loss', 'record_expense'], array_column(agent_tools(['role' => 'owner']), 'name'), 'an owner');
    check_same(['profit_and_loss'], array_column(agent_tools(['role' => 'accountant']), 'name'), 'an accountant cannot record');
    check_same([], agent_tools(['role' => 'attorney']), 'an attorney has no book actions');
    check(str_contains(agent_tools(['role' => 'owner'])[1]['description'], 'confirms'), 'the model is told a change waits for confirmation');
    // Even if the model names an action it was not offered, it does not run.
    $sent = [];
    $r = agent_run('record a $50 expense', ['role' => 'accountant'], ['transport' => at_script([
        [at_use('t1', 'record_expense', ['amount' => 5000])], at_text('I cannot record expenses.')], $sent)]);
    check_same([], $ran, 'nothing ran');
    check(!$r['calls'][0]['ok'] && str_contains($r['calls'][0]['error'], 'no action named'), 'refused: ' . json_encode($r['calls']));
    try {
        agent_invoke('record_expense', ['amount' => 5000], ['role' => 'accountant'], true);
        check(false, 'an accountant confirmed a change');
    } catch (AgentError) {
    }
}

function test_agent_reads_then_answers_with_grounded_figures(): void
{
    at_register($ran);
    $events = [];
    $ctx = ['role' => 'owner', 'today' => '2026-07-15', 'audit' => function ($e, $d) use (&$events) { $events[] = $e; }];
    $sent = [];
    $r = agent_run('print the profit and loss for April 1 to June 30 2026', $ctx, ['transport' => at_script([
        [at_text('Fetching.'), at_use('t1', 'profit_and_loss', ['from' => '2026-04-01', 'to' => '2026-06-30', 'extra' => 'dropped'])],
        at_text('From April 1 to June 30, 2026 revenue was $30,000.00 against expenses of $9,000 (about $9k), so net income was $21,000.00, a 70% margin.'),
    ], $sent)]);
    check_same([['profit_and_loss', ['from' => '2026-04-01', 'to' => '2026-06-30']]], $ran, 'the action ran with clean input');
    check($r['grounded'], 'every figure came from the action: ' . json_encode($r['ungrounded']));
    check_same(2, count($sent), 'two model turns');
    check_same('tool_result', $sent[1]['messages'][2]['content'][0]['type'], 'the result went back to the model');
    check(str_contains($sent[1]['messages'][2]['content'][0]['content'], '2100000'), 'as JSON');
    check_same(['agent.asked', 'agent.action', 'agent.answered'], $events, 'audited: ' . json_encode($events));
    check_same(['input_tokens' => 20, 'output_tokens' => 10], $r['usage'], 'usage summed');
}

function test_agent_corrects_or_flags_figures_it_made_up(): void
{
    at_register($ran);
    $sent = [];
    $pl = at_use('t1', 'profit_and_loss', ['from' => '2026-04-01', 'to' => '2026-06-30']);
    // Made up a figure; corrected after being told.
    $r = agent_run('how did we do last quarter?', ['role' => 'owner'], ['transport' => at_script([
        [$pl], at_text('Net income was $21,000.00, so next year should be about $84,000.'), at_text('Net income was $21,000.00 for the quarter.'),
    ], $sent)]);
    check($r['grounded'], 'corrected');
    check_same('Net income was $21,000.00 for the quarter.', $r['answer'], 'the corrected answer is the one returned');
    check(str_contains($sent[2]['messages'][4]['content'], '$84,000'), 'the model was told which figure: ' . json_encode($sent[2]['messages'][4]));
    // Still made up after correction: flagged, not hidden.
    $r = agent_run('how did we do last quarter?', ['role' => 'owner'], ['transport' => at_script([
        [$pl], at_text('Margin 71.5%.'), at_text('Margin 71.5%, I am sure.'),
    ], $sent)]);
    check(!$r['grounded'] && $r['ungrounded'] === ['71.5%'], 'flagged: ' . json_encode($r['ungrounded']));
}

function test_agent_changes_wait_for_the_person(): void
{
    at_register($ran);
    $sent = [];
    $events = [];
    $ctx = ['role' => 'owner', 'audit' => function ($e, $d) use (&$events) { $events[] = $e; }];
    $r = agent_run('record a $50 expense', $ctx, ['transport' => at_script([
        [at_use('t1', 'record_expense', ['amount' => 5000])], at_text('A $50.00 expense is waiting for your confirmation.'),
    ], $sent)]);
    check_same([], $ran, 'nothing changed yet');
    check_same([['action' => 'record_expense', 'label' => 'Record an expense', 'input' => ['amount' => 5000], 'summary' => 'Record an expense of 5000 cents']], $r['pending'], 'pending change');
    check(str_contains($sent[1]['messages'][2]['content'][0]['content'], 'awaiting_confirmation'), 'the model was told it has not happened');
    check(in_array('agent.change_offered', $events, true), 'the offer is audited');
    agent_confirm($r['pending'][0], $ctx);
    check_same([['record_expense', ['amount' => 5000]]], $ran, 'confirmed, it ran');
    check_same('agent.action', end($events), 'the confirmation is audited');
}

function test_agent_bad_input_goes_back_to_the_model(): void
{
    at_register($ran);
    $sent = [];
    $r = agent_run('P&L', ['role' => 'owner'], ['transport' => at_script([
        [at_use('t1', 'profit_and_loss', ['from' => '2026-02-30', 'to' => '2026-06-30'])],
        [at_use('t2', 'profit_and_loss', ['from' => '2026-04-01', 'to' => '2026-06-30', 'basis' => 'barter'])],
        at_text('I need valid dates.'),
    ], $sent)]);
    check_same([], $ran, 'nothing ran on bad input');
    check_same('input.from must be a date (YYYY-MM-DD).', $r['calls'][0]['error'], 'date');
    check(str_contains($r['calls'][1]['error'], 'must be one of'), 'enum');
    check($sent[1]['messages'][2]['content'][0]['is_error'] ?? false, 'marked as an error for the model');
    // A failure inside an action is not leaked.
    agent_action(['name' => 'boom', 'description' => 'x', 'mode' => 'read', 'input_schema' => ['type' => 'object'], 'allowed' => fn() => true,
        'run' => fn() => throw new RuntimeException('SQLSTATE secret detail')]);
    $r = agent_run('boom', ['role' => 'owner'], ['transport' => at_script([[at_use('t1', 'boom', [])], at_text('Failed.')], $sent)]);
    check_same('The action failed.', $r['calls'][0]['error'], 'internals hidden');
    // A model that never stops is cut off.
    $loop = array_fill(0, 3, [at_use('t', 'profit_and_loss', ['from' => '2026-04-01', 'to' => '2026-06-30'])]);
    $r = agent_run('loop', ['role' => 'owner'], ['transport' => at_script($loop, $sent), 'max_steps' => 3]);
    check_same(3, $r['steps'], 'stopped after max steps');
    check(str_contains($r['answer'], 'could not finish'), 'says so');
}

function test_agent_number_grounding_rules(): void
{
    $calls = [['ok' => true, 'result' => ['cash' => 5600000, 'runway_months' => 18.7, 'margin' => 70.0, 'label' => '61–90 days', 'lowest' => -400000]]];
    $ok = fn(string $t) => agent_verify_numbers($t, $calls, 'from 2026-04-01 to 2026-06-30, next 45 days');
    foreach (['Cash is $56,000.00.', 'Cash is $56,000.', 'about $56k', 'about $0.06 million', 'runway 18.7 months, about 19 months', 'a 70% margin',
              'on July 15, 2026 and 2026-07-15 and 7/15/2026', 'the 3rd of 4 items', 'in the 61–90 days window', 'in the next 45 days',
              'cash falls to -$4,000.00', 'short by $4,000'] as $t) {
        check($ok($t)['grounded'], "grounded: $t " . json_encode($ok($t)['ungrounded']));
    }
    foreach (['Cash is $56,001.00.' => '$56,001.00', 'runway 18.8 months' => '18.8', 'a 71% margin' => '71%', 'about $57k' => '$57k', 'we have 13 clients' => '13'] as $t => $bad) {
        check_same(['grounded' => false, 'ungrounded' => [$bad]], $ok($t), "ungrounded: $t");
    }
    check(!agent_verify_numbers('Cash is $56,000.', [['ok' => false, 'error' => 'x', 'result' => ['cash' => 5600000]]])['grounded'], 'a failed call grounds nothing');
}
