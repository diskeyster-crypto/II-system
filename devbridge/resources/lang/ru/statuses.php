<?php
return [
    'draft'                  => 'Черновик',
    'clarifying'             => 'Уточнение',
    'ready_to_run'           => 'Готова к запуску',
    'blocked_by_dependency'  => 'Заблокирована зависимостью',
    'issue_created'          => 'Issue создан',
    'assigned_to_agent'      => 'Назначена агенту',
    'pr_created'             => 'PR создан',
    'reviewing'              => 'Проверяется',
    'changes_requested'      => 'Запрошены правки',
    'waiting_for_agent'      => 'Ждёт агента',
    'waiting_for_operator'   => 'Ждёт оператора',
    'approved_by_gpt'        => 'Одобрена GPT',
    'ready_for_manual_merge' => 'Готова к ручному merge',
    'merged'                 => 'Слита (merged)',
    'failed'                 => 'Ошибка',
    'cancelled'              => 'Отменена',
    'active'                 => 'Активен',
    'archived'               => 'В архиве',
    'pending'                => 'Ожидает',
    'approved'               => 'Одобрено',
    'rejected'               => 'Отклонено',
    // Flat priority keys
    'priority_low'    => 'Низкий',
    'priority_normal' => 'Обычный',
    'priority_high'   => 'Высокий',
    'priority_urgent' => 'Срочный',
    // Flat risk keys
    'risk_low'      => 'Низкий',
    'risk_medium'   => 'Средний',
    'risk_high'     => 'Высокий',
    'risk_critical' => 'Критический',
    // Flat run_mode keys
    'run_mode_manual'             => 'Вручную',
    'run_mode_run_now'            => 'Запустить сейчас',
    'run_mode_queue'              => 'В очередь',
    'run_mode_after_dependencies' => 'После зависимостей',
    // Flat task_mode keys
    'task_mode_ai_chat'           => '🤖 AI Чат',
    'task_mode_manual_spec'       => '📋 Ручное ТЗ',
    // Nested (kept for backward compat)
    'priorities' => [
        'low'    => 'Низкий',
        'normal' => 'Обычный',
        'high'   => 'Высокий',
        'urgent' => 'Срочный',
    ],
    'run_modes' => [
        'manual'               => 'Вручную',
        'run_now'              => 'Запустить сейчас',
        'queue'                => 'В очередь',
        'after_dependencies'   => 'После зависимостей',
    ],
    'conflict_levels' => [
        'none'     => 'Нет',
        'low'      => 'Низкий',
        'medium'   => 'Средний',
        'high'     => 'Высокий',
        'blocking' => 'Блокирующий',
    ],
    'risk_levels' => [
        'low'      => 'Низкий',
        'medium'   => 'Средний',
        'high'     => 'Высокий',
        'critical' => 'Критический',
    ],
];

