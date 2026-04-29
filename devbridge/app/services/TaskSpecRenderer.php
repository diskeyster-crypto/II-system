<?php
declare(strict_types=1);

namespace DevBridge\Services;

/**
 * Converts an AI-generated task spec (structured JSON or free-text markdown)
 * into a formatted GitHub Issue body.
 */
class TaskSpecRenderer
{
    /**
     * Render a GitHub Issue body from a task record + optional structured JSON.
     *
     * @param  array       $task       Row from dev_tasks (joined with projects/repositories)
     * @param  string      $branchName Proposed branch name
     * @param  array|null  $specJson   Parsed structured spec from the AI planner (optional)
     * @return string      Markdown string suitable for a GitHub Issue body
     */
    public function render(array $task, string $branchName, ?array $specJson = null): string
    {
        if ($specJson !== null) {
            return $this->renderFromJson($task, $branchName, $specJson);
        }
        return $this->renderFromText($task, $branchName);
    }

    // -----------------------------------------------------------------------
    // Rendering from structured JSON
    // -----------------------------------------------------------------------

    private function renderFromJson(array $task, string $branchName, array $s): string
    {
        $lines = [];

        $lines[] = '> **This issue was created by DevBridge and assigned to a coding agent.**';
        $lines[] = '> **Branch:** `' . $branchName . '`';
        $lines[] = '> **Do NOT modify unrelated files.**';
        $lines[] = '';

        if ($s['goal'] ?? '') {
            $lines[] = '## Goal';
            $lines[] = '';
            $lines[] = $s['goal'];
            $lines[] = '';
        }

        if ($s['final_expected_result'] ?? '') {
            $lines[] = '## Final Expected Result';
            $lines[] = '';
            $lines[] = $s['final_expected_result'];
            $lines[] = '';
        }

        foreach ([
            'ui_requirements'  => 'UI Requirements',
            'api_requirements' => 'API Requirements',
            'database_changes' => 'Database Changes',
        ] as $key => $heading) {
            $items = $s[$key] ?? [];
            if (!empty($items)) {
                $lines[] = '## ' . $heading;
                $lines[] = '';
                foreach ((array)$items as $item) {
                    $lines[] = '- ' . $item;
                }
                $lines[] = '';
            }
        }

        $criteria = $s['acceptance_criteria'] ?? json_decode($task['acceptance_criteria_json'] ?? '[]', true) ?: [];
        if (!empty($criteria)) {
            $lines[] = '## Acceptance Criteria';
            $lines[] = '';
            foreach ((array)$criteria as $c) {
                $lines[] = '- [ ] ' . $c;
            }
            $lines[] = '';
        }

        $testPlan = $s['test_plan'] ?? [];
        if (!empty($testPlan)) {
            $lines[] = '## Test Plan';
            $lines[] = '';
            foreach ((array)$testPlan as $item) {
                $lines[] = '- ' . $item;
            }
            $lines[] = '';
        }

        $rollback = $s['rollback_notes'] ?? [];
        if (!empty($rollback)) {
            $lines[] = '## Rollback Notes';
            $lines[] = '';
            foreach ((array)$rollback as $item) {
                $lines[] = '- ' . $item;
            }
            $lines[] = '';
        }

        $allowed   = $s['allowed_files'] ?? json_decode($task['allowed_files_json'] ?? '[]', true) ?: [];
        $forbidden = $s['forbidden_files'] ?? json_decode($task['forbidden_files_json'] ?? '[]', true) ?: [];
        $expected  = json_decode($task['expected_files_json'] ?? '[]', true) ?: [];

        $lines[] = '## Allowed Files';
        $lines[] = '';
        $lines[] = $allowed ? implode("\n", array_map(fn($f) => '- `' . $f . '`', (array)$allowed)) : '_any_';
        $lines[] = '';

        $lines[] = '## Forbidden Files (DO NOT MODIFY)';
        $lines[] = '';
        $lines[] = $forbidden ? implode("\n", array_map(fn($f) => '- `' . $f . '`', (array)$forbidden)) : '_none_';
        $lines[] = '';

        if (!empty($expected)) {
            $lines[] = '## Expected Files';
            $lines[] = '';
            foreach ($expected as $f) {
                $lines[] = '- `' . $f . '`';
            }
            $lines[] = '';
        }

        $rules = trim($task['global_rules'] ?? '');
        if ($rules) {
            $lines[] = '## Project Rules';
            $lines[] = '';
            $lines[] = $rules;
            $lines[] = '';
        }

        $techStack = trim($task['tech_stack'] ?? '');
        if ($techStack) {
            $lines[] = '## Tech Stack';
            $lines[] = '';
            $lines[] = $techStack;
            $lines[] = '';
        }

        if ($s['coding_agent_prompt'] ?? '') {
            $lines[] = '## Instructions for Coding Agent';
            $lines[] = '';
            $lines[] = $s['coding_agent_prompt'];
            $lines[] = '';
        }

        $lines[] = '---';
        $lines[] = '_Task ID: ' . $task['id']
            . ' | Project: ' . ($task['project_name'] ?? '')
            . ' | Risk: ' . ($s['risk_level'] ?? $task['risk_level'] ?? 'unknown')
            . '_';

        return implode("\n", $lines);
    }

    // -----------------------------------------------------------------------
    // Rendering from free-text (legacy / markdown spec)
    // -----------------------------------------------------------------------

    private function renderFromText(array $task, string $branchName): string
    {
        $criteria  = json_decode($task['acceptance_criteria_json'] ?? '[]', true) ?: [];
        $forbidden = json_decode($task['forbidden_files_json'] ?? '[]', true) ?: [];
        $allowed   = json_decode($task['allowed_files_json'] ?? '[]', true) ?: [];
        $expected  = json_decode($task['expected_files_json'] ?? '[]', true) ?: [];

        $criteriaList  = implode("\n", array_map(fn($c) => '- [ ] ' . $c, $criteria));
        $forbiddenList = $forbidden ? implode("\n", array_map(fn($f) => '- `' . $f . '`', $forbidden)) : '_none_';
        $allowedList   = $allowed  ? implode("\n", array_map(fn($f) => '- `' . $f . '`', $allowed))  : '_any_';
        $expectedList  = $expected ? implode("\n", array_map(fn($f) => '- `' . $f . '`', $expected)) : '_see spec_';

        $rules        = trim($task['global_rules'] ?? '');
        $rulesSection = $rules ? "## Project Rules\n\n$rules\n\n" : '';

        return <<<BODY
> **This issue was created by DevBridge and assigned to a coding agent.**
> **Branch:** `$branchName`
> **Do NOT modify unrelated files.**

## Task Specification

{$task['final_task_spec']}

## Acceptance Criteria

$criteriaList

## Allowed Files

$allowedList

## Forbidden Files (DO NOT MODIFY)

$forbiddenList

## Expected Files

$expectedList

{$rulesSection}## Tech Stack

{$task['tech_stack']}

---
_Task ID: {$task['id']} | Project: {$task['project_name']} | Risk: {$task['risk_level']}_
BODY;
    }
}
