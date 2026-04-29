Create a separate PHP 8.1+ application called DevBridge.

DevBridge is an admin-only AI Development Management System.

Main goal:
DevBridge helps an operator manage AI-assisted software development through GPT, GitHub and coding agents such as GitHub Copilot.

This is not a simple chat.
This is not just a GitHub issue creator.
This is a controlled AI development workflow:

Operator discusses a task with GPT.
GPT clarifies requirements.
GPT creates a final technical specification.
Operator approves the task.
DevBridge creates a GitHub Issue.
A coding agent writes code and opens a Pull Request.
DevBridge watches the Pull Request.
GPT reviews the code, diff, files and test status.
If the code is wrong, DevBridge comments with required fixes.
If GPT cannot safely decide, DevBridge stops and waits for operator decision.
If the code is good, DevBridge marks the task as ready for manual merge.

Important:
- No auto-merge.
- No auto-deploy.
- Human operator always makes the final merge decision.

Tech stack:
- PHP 8.1+
- MySQL or MariaDB
- PDO
- No framework if possible
- Admin-only web panel
- GitHub REST API integration
- GitHub webhooks
- OpenAI-compatible AI API integration
- OpenRouter as default AI provider
- UTF-8 / utf8mb4
- password_hash/password_verify
- PHP sessions
- CSRF protection for all POST forms

Core sections:

1. Installer

Create install.php.

Installer must:
- check PHP version
- check extensions: pdo, pdo_mysql, curl, json, mbstring, openssl
- connect to MySQL
- create database tables
- create first admin user
- write config file
- create storage folders
- lock installer after installation

2. Admin Auth

Create:
- login.php
- logout.php
- admin dashboard

Requirements:
- password_hash/password_verify
- PHP sessions
- CSRF for forms
- admin-only access

3. Dashboard

Show:
- projects count
- repositories count
- active dev tasks
- tasks waiting for operator
- PRs waiting for review
- recently created tasks
- recent logs

4. Settings

Admin can configure:
- OpenRouter API key
- OpenRouter model
- AI temperature
- max prompt chars
- GitHub token
- default GitHub owner
- webhook base URL
- global no-auto-merge setting

Secrets must not be displayed fully in UI.

5. Projects

A project is a software product or system.
A project can have multiple repositories.

Project fields:
- id
- name
- code
- description
- business_goal
- tech_stack
- global_rules
- default_ai_model
- max_parallel_tasks
- status
- created_at
- updated_at

Admin can:
- create project
- edit project
- archive project
- view project dashboard

Project rules must be included in:
- GPT task planning prompts
- GitHub issue body
- GPT code review prompts

6. Repositories

A repository belongs to a project.

Repository fields:
- id
- project_id
- github_owner
- github_repo
- default_branch
- protected_branch
- repository_type
- status
- max_parallel_tasks
- github_webhook_secret
- created_at
- updated_at

Admin can:
- connect existing GitHub repository
- create new GitHub repository
- create repository from template later
- test GitHub connection
- install webhook
- sync repository roadmap

7. Repository Bootstrapper

Create service:
app/Services/RepositoryBootstrapper.php

It must support:
- creating a new GitHub repository
- creating initial README.md
- creating ROADMAP.md
- creating ARCHITECTURE.md
- creating CONTRIBUTING.md
- creating .gitignore
- creating .devbridge/project.json
- creating .devbridge/rules.md
- creating .devbridge/roadmap.json
- creating .devbridge/roadmaps/core.json
- creating .devbridge/roadmaps/security.json
- creating .devbridge/roadmaps/github.json
- creating default GitHub labels
- installing GitHub webhook
- verifying repository setup

Do not overwrite existing files without operator confirmation.

8. Living Roadmap

Roadmap must be living, versioned and operator-controlled.

Every managed repository should have:
- ROADMAP.md
- .devbridge/roadmap.json
- .devbridge/rules.md
- .devbridge/project.json

For large projects, support roadmap branches:
- core
- github
- ai_planning
- ai_review
- operator_decisions
- parallel_tasks
- ui
- security
- billing_later

Create database tables:
- roadmap_branches
- roadmap_versions
- roadmap_change_proposals

GPT can propose roadmap changes.
GPT must not silently rewrite roadmap.
Operator must approve roadmap changes.

Roadmap change proposal fields:
- id
- project_id
- repository_id
- branch_code
- related_task_id
- related_pr_number
- reason
- proposed_md_diff
- proposed_json_diff
- status: pending, approved, rejected, applied
- operator_decision
- created_at
- decided_at

9. Dev Tasks

A dev task is created through an operator chat.

Fields:
- id
- project_id
- repository_id
- parent_task_id
- title
- original_operator_request
- final_task_spec
- roadmap_branch
- roadmap_item_code
- acceptance_criteria_json
- allowed_files_json
- forbidden_files_json
- expected_files_json
- depends_on_json
- risk_level
- run_mode
- priority
- branch_name
- conflict_status
- conflict_details_json
- github_issue_number
- github_issue_url
- github_pr_number
- github_pr_url
- status
- created_at
- updated_at

Statuses:
- draft
- clarifying
- ready_to_run
- blocked_by_dependency
- issue_created
- assigned_to_agent
- pr_created
- reviewing
- changes_requested
- waiting_for_agent
- waiting_for_operator
- approved_by_gpt
- ready_for_manual_merge
- merged
- failed
- cancelled

Run modes:
- manual
- run_now
- queue
- after_dependencies

Priorities:
- low
- normal
- high
- urgent

10. Task Chat With GPT

Create page:
admin/tasks/chat.php?id={task_id}

The operator can discuss the task with GPT.

GPT must:
- ask clarifying questions if needed
- use project rules
- use repository rules
- use roadmap context
- use active tasks context
- decide if the task should be split
- decide if task can run in parallel
- propose dependencies
- propose allowed files
- propose forbidden files
- propose acceptance criteria

When task is clear, GPT must generate Final Task Spec.

Final Task Spec must include:
- task title
- goal
- final expected behavior
- UI requirements
- API requirements if needed
- database changes if needed
- files likely affected
- allowed files
- forbidden files
- expected files
- acceptance criteria
- test plan
- rollback notes
- roadmap branch
- roadmap item code
- dependencies
- parallel execution recommendation
- conflict risk
- risk level
- prompt for coding agent

Do not create a GitHub Issue until operator clicks:
"Approve and Create Issue".

11. GitHub Issue Creation

After operator approval:
- check dependencies
- check max_parallel_tasks
- run conflict detector
- generate branch name:
  devbridge/task-{task_id}-{slug}
- create GitHub Issue using GitHub REST API
- issue body must include final_task_spec
- include acceptance criteria
- include project rules
- include repository rules
- include roadmap context
- include forbidden files
- include expected branch name
- add labels: devbridge, ai-task
- store issue number and issue URL
- update task status to issue_created

Do not auto-merge.
Do not auto-deploy.

12. Parallel Tasks

DevBridge must allow running multiple tasks in parallel.

Before starting a task:
- check active tasks for the same project
- check active tasks for the same repository
- check project max_parallel_tasks
- check repository max_parallel_tasks
- check dependencies
- check file conflicts
- check sensitive paths

Create service:
app/Services/ConflictDetector.php

Conflict levels:
- none
- low
- medium
- high
- blocking

If conflict level is blocking, task cannot be started without operator override.

13. Task Board

Create admin page:
admin/tasks/board.php

Columns:
- Draft
- Ready
- Running
- Waiting for Agent
- Waiting for Operator
- Ready for Merge
- Done
- Failed

Show each task:
- project
- repository
- branch
- priority
- conflict level
- dependencies
- issue link
- PR link
- review status

14. GitHub Service

Create:
app/Services/GitHubService.php

Methods:
- testConnection()
- createRepository()
- createOrUpdateFile()
- createIssue()
- getIssue()
- getPullRequest()
- getPullRequestFiles()
- getPullRequestDiff()
- createPullRequestComment()
- createWebhook()
- getCheckRuns()
- createLabels()

Use GitHub REST API.
Handle errors clearly.
Log all GitHub API errors.

15. GitHub Webhooks

Create:
webhook/github.php

It must:
- validate GitHub webhook signature
- handle pull_request events
- handle issue_comment events if needed
- handle check_suite/check_run/workflow_run if useful
- store PR number and PR URL in dev_tasks
- update task status
- create log entry
- optionally trigger GPT review

16. PR Diff Fetcher

When PR is created or updated, DevBridge must fetch:
- PR metadata
- changed files
- diff
- check status if available

Store review input snapshot so reviews are reproducible.

17. GPT Code Review

Create:
app/Services/GPTCodeReviewer.php

GPT review input:
- original operator request
- final task spec
- acceptance criteria
- project rules
- repository rules
- roadmap context
- active tasks
- forbidden files
- allowed files
- changed files
- pull request diff
- CI/check status if available

GPT must return strict JSON:

{
  "status": "approved/request_changes/rejected/needs_operator_decision",
  "score": 0,
  "summary": "string",
  "acceptance_criteria": {
    "criterion name": true
  },
  "blocking_issues": [
    {
      "severity": "critical/high/medium",
      "type": "security/architecture/billing/bug/compatibility",
      "file": "string",
      "line": "string or null",
      "message": "string",
      "suggested_fix": "string"
    }
  ],
  "non_blocking_issues": [
    {
      "severity": "low",
      "type": "style/refactor/docs",
      "file": "string",
      "message": "string",
      "suggested_fix": "string"
    }
  ],
  "files_that_should_not_be_changed": [],
  "missing_requirements": [],
  "roadmap_update_needed": false,
  "roadmap_update_reason": "string or null",
  "operator_question": "string or null",
  "merge_decision": "do_not_merge/merge_after_fixes/safe_to_merge"
}

Rules:
- If forbidden files were modified, status must be rejected.
- If critical/high security issue exists, status must be request_changes or rejected.
- If acceptance criteria are not satisfied, status must be request_changes.
- If GPT cannot safely decide, status must be needs_operator_decision.
- Never return safe_to_merge if tests are failing or unknown for critical code.

18. PR Commenting

If GPT status is request_changes and issues are clear:
- create PR comment with required fixes
- format it as a message to coding agent
- include exact fixes
- tell the agent not to modify unrelated files
- update task status to changes_requested

Example comment:

@copilot please fix the following issues:

1. Add CSRF validation to the new admin form.
2. Do not modify app/Core/AuthService.php because it is forbidden for this task.
3. Use PDO prepared statements in ModuleInstaller.
4. Add rollback logic if migration fails.

Do not modify unrelated files.
Keep compatibility with PHP 8.1.

If GPT status is needs_operator_decision:
- do not comment automatically
- set task status to waiting_for_operator
- show operator question in admin panel

Limit automatic fix cycles to 3.
After 3 cycles, set waiting_for_operator.

19. Operator Decision Center

Create page:
admin/operator-decisions.php

Show tasks waiting for operator.

Operator can:
- answer GPT question
- approve roadmap change
- reject roadmap change
- edit roadmap proposal
- send custom comment to PR
- request another GPT review
- cancel task
- mark task as failed
- mark task as ready for manual merge
- mark task as merged manually

20. AI Provider

Create:
app/AI/AiClientInterface.php
app/AI/OpenRouterClient.php

Settings:
- ai_provider
- openrouter_api_key
- openrouter_model
- temperature
- max_prompt_chars

All AI responses expected as JSON must be validated with json_decode.
If JSON is invalid, try one repair request.
If still invalid, store error and show it to operator.

21. Database tables

Create install SQL for:
- admins
- settings
- projects
- repositories
- project_rules
- roadmap_branches
- roadmap_versions
- roadmap_change_proposals
- dev_tasks
- dev_task_messages
- dev_task_reviews
- operator_decisions
- github_events
- logs

22. Security

Requirements:
- Admin-only system
- CSRF for all POST forms
- GitHub webhook signature verification
- GitHub token stored encrypted if possible
- OpenRouter key stored encrypted if possible
- never expose secrets in UI
- no auto-merge
- no auto-deploy
- no shell_exec
- no exec
- no eval
- all SQL through PDO prepared statements
- all output escaped with htmlspecialchars
- storage not directly accessible from browser

23. Logs

Log:
- AI requests
- GitHub requests
- webhook events
- review decisions
- operator decisions
- roadmap changes
- errors

24. UI

Keep UI simple and clean.

Admin menu:
- Dashboard
- Projects
- Repositories
- Roadmaps
- Task Board
- Dev Tasks
- Pull Requests
- Reviews
- Operator Decisions
- Settings
- Logs

25. MVP limitations

Do not implement:
- billing
- public users
- teams
- auto-merge
- auto-deploy
- GitLab
- Bitbucket
- marketplace
- complex CI runner

Focus only on:
- admin auth
- projects
- repositories
- repository bootstrap
- living roadmap
- task chat with GPT
- final task spec
- parallel task basics
- GitHub issue creation
- GitHub webhook
- PR diff fetching
- GPT code review
- operator decision flow
