<?php
declare(strict_types=1);

/**
 * STRATICUS agent core: a prompt becomes actions inside the product.
 *
 * Product-agnostic. A host registers actions (what the product can do), and
 * agent_run() lets a model choose among them for one person's request:
 *
 * - Actions are the only way the model touches anything. Each declares an
 *   input schema, a mode and a permission check, and the runner only offers
 *   the model the actions the asking person may use. The model acts as that
 *   person and never with more.
 * - Modes: 'read' runs at once; 'propose' runs at once and creates
 *   something that waits for a person to approve; 'change' never runs from
 *   the model. It comes back as a pending action the person confirms in the
 *   product, which then calls agent_confirm().
 * - Numbers come from actions, never from the model. agent_verify_numbers()
 *   checks every figure in the answer against what the actions returned (and
 *   the person's own words); the runner asks the model once to correct an
 *   answer that states anything else, and flags it if it still does.
 * - Every request, every action call and the outcome go to the host's audit
 *   hook, which in Liticus is the hash-chained Proof of Work log.
 *
 * The model is reached through a transport: callable(array $body): array
 * taking and returning the Anthropic Messages API shape. Tests pass a fake.
 */

final class AgentError extends RuntimeException
{
}

/** The registered actions, by name. */
function &agent_registry(): array
{
    static $actions = [];
    return $actions;
}

/**
 * Register an action.
 *
 * $def: name (a-z0-9_), description (for the model: what it does and when
 * to use it), input_schema (JSON schema, an object), mode ('read' |
 * 'propose' | 'change'), allowed (callable(array $ctx): bool), run
 * (callable(array $input, array $ctx): array), and optionally label (for
 * people) and summarize (callable(array $input): string, for a pending
 * change's confirmation).
 */
function agent_action(array $def): void
{
    foreach (['name', 'description', 'input_schema', 'mode', 'allowed', 'run'] as $k) {
        if (!isset($def[$k])) {
            throw new AgentError("An action needs $k.");
        }
    }
    if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $def['name']) || !in_array($def['mode'], ['read', 'propose', 'change'], true)
        || ($def['input_schema']['type'] ?? '') !== 'object' || !is_callable($def['allowed']) || !is_callable($def['run'])) {
        throw new AgentError('Action ' . $def['name'] . ' is not well formed.');
    }
    agent_registry()[$def['name']] = $def + ['label' => $def['name']];
}

/** The actions this person may use. */
function agent_actions_for(array $ctx): array
{
    return array_filter(agent_registry(), fn($a) => (bool) ($a['allowed'])($ctx));
}

/** Tool definitions for the model, for this person. */
function agent_tools(array $ctx): array
{
    $out = [];
    foreach (agent_actions_for($ctx) as $a) {
        $desc = $a['description'];
        if ($a['mode'] === 'change') {
            $desc .= ' This does not happen until the person confirms it; calling it asks them.';
        } elseif ($a['mode'] === 'propose') {
            $desc .= ' This creates a proposal that someone with authority must approve.';
        }
        $out[] = ['name' => $a['name'], 'description' => $desc, 'input_schema' => $a['input_schema']];
    }
    return $out;
}

/**
 * Check input against an action's schema: required fields, types, enums,
 * date and number bounds. Unknown fields are dropped. Returns the clean
 * input or throws AgentError with what is wrong (shown to the model, which
 * may correct itself).
 */
function agent_validate(array $schema, mixed $input, string $at = 'input'): mixed
{
    $type = $schema['type'] ?? null;
    if ($type === 'object') {
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            throw new AgentError("$at must be an object.");
        }
        $out = [];
        foreach ($schema['required'] ?? [] as $r) {
            if (!array_key_exists($r, $input)) {
                throw new AgentError("$at.$r is required.");
            }
        }
        foreach ($schema['properties'] ?? [] as $k => $sub) {
            if (array_key_exists($k, $input) && $input[$k] !== null) {
                $out[$k] = agent_validate($sub, $input[$k], "$at.$k");
            }
        }
        return $out;
    }
    if ($type === 'array') {
        if (!is_array($input) || !array_is_list($input)) {
            throw new AgentError("$at must be a list.");
        }
        if (isset($schema['maxItems']) && count($input) > $schema['maxItems']) {
            throw new AgentError("$at has too many items.");
        }
        return array_map(fn($v) => agent_validate($schema['items'] ?? [], $v, $at . '[]'), $input);
    }
    $ok = match ($type) {
        'string' => is_string($input),
        'integer' => is_int($input) || (is_float($input) && floor($input) === $input),
        'number' => is_int($input) || is_float($input),
        'boolean' => is_bool($input),
        null => true,
        default => false,
    };
    if (!$ok) {
        throw new AgentError("$at must be a $type.");
    }
    if ($type === 'integer') {
        $input = (int) $input;
    }
    if (isset($schema['enum']) && !in_array($input, $schema['enum'], true)) {
        throw new AgentError("$at must be one of: " . implode(', ', $schema['enum']) . '.');
    }
    if (is_string($input)) {
        if (($schema['format'] ?? '') === 'date' && !(preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) && checkdate((int) substr($input, 5, 2), (int) substr($input, 8, 2), (int) substr($input, 0, 4)))) {
            throw new AgentError("$at must be a date (YYYY-MM-DD).");
        }
        if (strlen($input) > ($schema['maxLength'] ?? 2000)) {
            throw new AgentError("$at is too long.");
        }
    }
    if (is_int($input) || is_float($input)) {
        if ((isset($schema['minimum']) && $input < $schema['minimum']) || (isset($schema['maximum']) && $input > $schema['maximum'])) {
            throw new AgentError("$at is out of range.");
        }
    }
    return $input;
}

/**
 * Run one action for a person, outside the model too (a button, the
 * confirmation of a pending change). Checks permission and input, runs it,
 * and audits it.
 */
function agent_invoke(string $name, array $input, array $ctx, bool $confirmed = false): array
{
    $a = agent_registry()[$name] ?? null;
    if ($a === null || !($a['allowed'])($ctx)) {
        throw new AgentError("Not an action you can use here: $name.");
    }
    if ($a['mode'] === 'change' && !$confirmed) {
        throw new AgentError("$name changes things and needs the person's confirmation.");
    }
    $clean = agent_validate($a['input_schema'], $input);
    try {
        $result = ($a['run'])($clean, $ctx);
    } catch (Throwable $e) {
        agent_audit($ctx, 'agent.action_failed', ['action' => $name, 'input' => $clean, 'error' => agent_error_text($e)]);
        throw $e;
    }
    agent_audit($ctx, 'agent.action', ['action' => $name, 'mode' => $a['mode'], 'input' => $clean, 'confirmed' => $confirmed]);
    return $result;
}

/** A pending change the person has confirmed. */
function agent_confirm(array $pending, array $ctx): array
{
    return agent_invoke((string) $pending['action'], (array) $pending['input'], $ctx, true);
}

function agent_audit(array $ctx, string $event, array $data): void
{
    if (isset($ctx['audit']) && is_callable($ctx['audit'])) {
        ($ctx['audit'])($event, $data);
    }
}

/** What to tell the model (and the log) about a failure, without internals. */
function agent_error_text(Throwable $e): string
{
    if ($e instanceof AgentError || (isset($GLOBALS['agent_safe_errors']) && array_filter($GLOBALS['agent_safe_errors'], fn($c) => $e instanceof $c))) {
        return $e->getMessage();
    }
    return 'The action failed.';
}

/**
 * Answer one request.
 *
 * $ctx: the person (host data the actions need, e.g. user and tenant ids),
 * plus audit (callable), today (Y-m-d), and system (extra instructions for
 * this product). $opts: transport (required), model, max_tokens,
 * max_steps (default 8), history (earlier turns, Messages API shape).
 *
 * @return array{answer:string, grounded:bool, ungrounded:array, calls:array, pending:array, steps:int, usage:array}
 */
function agent_run(string $prompt, array $ctx, array $opts): array
{
    $prompt = trim($prompt);
    if ($prompt === '' || mb_strlen($prompt) > 4000) {
        throw new AgentError('Ask in 1 to 4,000 characters.');
    }
    $transport = $opts['transport'] ?? null;
    if (!is_callable($transport)) {
        throw new AgentError('No model is configured.');
    }
    $tools = agent_tools($ctx);
    $system = agent_system_prompt($ctx);
    $messages = array_merge($opts['history'] ?? [], [['role' => 'user', 'content' => $prompt]]);
    $calls = [];
    $pending = [];
    $usage = ['input_tokens' => 0, 'output_tokens' => 0];
    $answer = '';
    $repaired = false;
    $maxSteps = (int) ($opts['max_steps'] ?? 8);
    agent_audit($ctx, 'agent.asked', ['prompt' => $prompt, 'tools' => array_column($tools, 'name')]);
    for ($step = 1; $step <= $maxSteps; $step++) {
        $body = ['model' => (string) ($opts['model'] ?? ''), 'max_tokens' => (int) ($opts['max_tokens'] ?? 4000), 'system' => $system, 'messages' => $messages];
        if ($tools) {
            $body['tools'] = $tools;
        }
        $res = $transport($body);
        foreach (['input_tokens', 'output_tokens'] as $k) {
            $usage[$k] += (int) ($res['usage'][$k] ?? 0);
        }
        $content = (array) ($res['content'] ?? []);
        $messages[] = ['role' => 'assistant', 'content' => $content];
        $uses = array_values(array_filter($content, fn($b) => ($b['type'] ?? '') === 'tool_use'));
        $text = trim(implode("\n", array_map(fn($b) => (string) $b['text'], array_filter($content, fn($b) => ($b['type'] ?? '') === 'text'))));
        if (!$uses) {
            $answer = $text;
            $check = agent_verify_numbers($answer, $calls, $prompt);
            if (!$check['grounded'] && !$repaired && $step < $maxSteps) {
                // One chance to restate using only the figures the actions returned.
                $repaired = true;
                $messages[] = ['role' => 'user', 'content' => 'These figures in your answer did not come from any action result: ' . implode(', ', $check['ungrounded'])
                    . '. Restate the answer using only figures exactly as the actions returned them (call another action if you need a figure), and do not compute new ones.'];
                continue;
            }
            break;
        }
        $results = [];
        foreach ($uses as $u) {
            $name = (string) ($u['name'] ?? '');
            $input = is_array($u['input'] ?? null) ? $u['input'] : [];
            $a = agent_actions_for($ctx)[$name] ?? null;
            $rec = ['action' => $name, 'input' => $input, 'ok' => false];
            try {
                if ($a === null) {
                    throw new AgentError("There is no action named $name that you can use.");
                }
                $clean = agent_validate($a['input_schema'], $input);
                $rec['input'] = $clean;
                if ($a['mode'] === 'change') {
                    $p = ['action' => $name, 'label' => $a['label'], 'input' => $clean,
                          'summary' => isset($a['summarize']) ? (string) ($a['summarize'])($clean) : $a['label']];
                    $pending[] = $p;
                    $out = ['status' => 'awaiting_confirmation', 'note' => 'Shown to the person to confirm; it has not happened.'];
                    agent_audit($ctx, 'agent.change_offered', ['action' => $name, 'input' => $clean]);
                } else {
                    $out = ($a['run'])($clean, $ctx);
                    agent_audit($ctx, 'agent.action', ['action' => $name, 'mode' => $a['mode'], 'input' => $clean, 'confirmed' => false]);
                }
                $rec['ok'] = true;
                $rec['result'] = $out;
                $results[] = ['type' => 'tool_result', 'tool_use_id' => (string) $u['id'], 'content' => json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
            } catch (Throwable $e) {
                $rec['error'] = agent_error_text($e);
                agent_audit($ctx, 'agent.action_failed', ['action' => $name, 'input' => $rec['input'], 'error' => $rec['error']]);
                $results[] = ['type' => 'tool_result', 'tool_use_id' => (string) $u['id'], 'is_error' => true, 'content' => $rec['error']];
            }
            $calls[] = $rec;
        }
        $messages[] = ['role' => 'user', 'content' => $results];
    }
    $check = agent_verify_numbers($answer, $calls, $prompt);
    if ($answer === '') {
        $answer = 'I could not finish that request. Try asking more narrowly.';
    }
    agent_audit($ctx, 'agent.answered', ['grounded' => $check['grounded'], 'ungrounded' => $check['ungrounded'], 'actions' => array_column($calls, 'action'),
                                         'pending' => array_column($pending, 'action'), 'steps' => min($step, $maxSteps), 'usage' => $usage]);
    return ['answer' => $answer, 'grounded' => $check['grounded'], 'ungrounded' => $check['ungrounded'], 'calls' => $calls,
            'pending' => $pending, 'steps' => min($step, $maxSteps), 'usage' => $usage];
}

function agent_system_prompt(array $ctx): string
{
    $today = (string) ($ctx['today'] ?? gmdate('Y-m-d'));
    return trim(<<<TXT
You are STRATICUS, the assistant inside a business's own software. Today is $today.
You act for the person asking, through the actions offered to you, and you can do only what those actions do.

Figures:
- Every number you state must come from an action's result, exactly as returned or rounded. Never estimate, compute, add up or extrapolate a figure yourself; if a figure you need is not in a result, call an action that returns it, or say you do not have it.
- Money in action results is in cents unless a field says otherwise: 123456 means \$1,234.56.
- Recommendations come from the analysis actions' findings and verdicts. Explain them plainly, and keep each one's verdict as given.

Conduct:
- Read actions are safe to use freely. A change is only offered to the person to confirm; say it is waiting for them, never that it is done.
- If the request is ambiguous about dates, use a sensible reading (for example "last month" is the previous calendar month) and say which you used.
- Be brief and plain. Lead with the answer.
TXT . (isset($ctx['system']) ? "\n\n" . $ctx['system'] : ''));
}

/* ── Number grounding ─────────────────────────────────────────────────── */

/** Result fields whose text is rules or boilerplate, never a measured figure. */
const AGENT_UNGROUNDING_KEYS = ['rule', 'advice', 'note', 'description'];

/**
 * Every figure in $text must appear in an action result or in the person's
 * own request. A figure matches when a source value, rounded to the
 * precision written (and to thousands or millions for "12k" / "1.2m"),
 * equals it. Integer results count both as written and as cents in dollars.
 * Figures inside dates and the small numbers of ordinary prose (counts up
 * to 12, ordinals) are not checked.
 *
 * @return array{grounded:bool, ungrounded:string[]}
 */
function agent_verify_numbers(string $text, array $calls, string $prompt = ''): array
{
    $sources = [];
    $walk = function (mixed $v) use (&$walk, &$sources) {
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                // Fixed thresholds and boilerplate are not measurements: "under 80%" in a
                // rule must not ground "collections are 80%".
                if (is_string($k) && in_array($k, AGENT_UNGROUNDING_KEYS, true)) {
                    continue;
                }
                $walk($x);
            }
        } elseif (is_int($v)) {
            $sources[] = (float) $v;
            $sources[] = $v / 100;
        } elseif (is_float($v)) {
            $sources[] = $v;
        } elseif (is_string($v)) {
            foreach (agent_numbers_in($v) as $n) {
                $sources[] = $n['value'];
            }
        }
    };
    $any = false;
    foreach ($calls as $c) {
        if ($c['ok'] ?? false) {
            $walk($c['result'] ?? null);   // not the input: the model chose that
            $any = true;
        }
    }
    if ($any) {
        $walk($prompt);   // the person's own figures (a salary to test), but only alongside the books: never on their own
    }
    $sources = array_values(array_unique($sources, SORT_REGULAR));
    $bad = [];
    foreach (agent_numbers_in($text) as $n) {
        if ($n['trivial']) {
            continue;
        }
        $hit = false;
        foreach ($sources as $s) {
            foreach ([$s, -$s, abs($s)] as $cand) {
                if (round($cand, $n['places']) == $n['value'] || ($n['scale'] > 1 && round($cand / $n['scale'], $n['places']) == $n['value'] / $n['scale'])) {
                    $hit = true;
                    break 2;
                }
            }
        }
        if (!$hit) {
            $bad[] = $n['raw'];
        }
    }
    return ['grounded' => !$bad, 'ungrounded' => array_values(array_unique($bad))];
}

/**
 * The figures in a piece of text: [raw, value, places, scale, trivial].
 * Dates (2026-07-15, 7/15/2026, July 15, 2026) are read as dates and
 * marked trivial, as are bare whole numbers up to 12 and ordinals.
 */
function agent_numbers_in(string $text): array
{
    $out = [];
    $months = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';
    // Take dates out first.
    $text = preg_replace_callback('/\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}\/\d{1,2}(?:\/\d{2,4})?\b|\b(?:' . $months . ')\.?\s+\d{1,2}(?:st|nd|rd|th)?(?:,?\s+\d{4})?\b|\b\d{1,2}(?:st|nd|rd|th)?\s+(?:' . $months . ')\b(?:,?\s+\d{4})?|\b(?:' . $months . ')\s+\d{4}\b|\bQ[1-4]\b/i',
        fn() => ' ', $text);
    preg_match_all('/(-|−)?(\$)?\s?(\d{1,3}(?:,\d{3})+|\d+)(\.\d+)?\s?(k|m|million|thousand)?\b(%|(?:st|nd|rd|th)\b)?/i', $text, $m, PREG_SET_ORDER);
    foreach ($m as $g) {
        $whole = str_replace(',', '', $g[3]);
        $frac = $g[4] ?? '';
        $value = (float) ($whole . $frac);
        $places = $frac === '' ? 0 : strlen($frac) - 1;
        $suffix = strtolower($g[5] ?? '');
        $scale = match ($suffix) { 'k', 'thousand' => 1000, 'm', 'million' => 1000000, default => 1 };
        $value *= $scale;
        if (($g[1] ?? '') !== '') {
            $value = -$value;
        }
        $tail = strtolower($g[6] ?? '');
        $ordinal = in_array($tail, ['st', 'nd', 'rd', 'th'], true);
        $plain = $g[2] === '' && $tail !== '%' && $frac === '' && $scale === 1 && !str_contains($g[3], ',');
        // A bare year ("in 2026", "FY2025") is prose; "$2050" or "2,050" is money and is checked.
        $trivial = $ordinal || ($plain && abs($value) <= 12) || ($plain && $value >= 1900 && $value <= 2099 && strlen($whole) === 4);
        $out[] = ['raw' => trim($g[0]), 'value' => $value, 'places' => $places, 'scale' => $scale, 'trivial' => $trivial];
    }
    return $out;
}
