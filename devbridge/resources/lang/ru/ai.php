<?php
return [
    'request_failed'        => 'AI-запрос не выполнен.',
    'invalid_json'          => 'AI вернул некорректный JSON.',
    'repairing_json'        => 'Пытаемся исправить JSON.',
    'review_done'           => 'AI-проверка завершена.',
    'clarify_req'           => 'Запрошено уточнение у GPT.',
    'spec_generated'        => 'Финальное ТЗ сформировано.',
    // Gemini-specific
    'gemini_key_missing'    => 'API-ключ Gemini не настроен. Добавьте ключ в Настройки → AI.',
    'gemini_no_candidates'  => 'Gemini не вернул результат. Ответ пустой или заблокирован.',
    'gemini_blocked'        => 'Ответ Gemini заблокирован фильтрами безопасности.',
    'gemini_empty_response' => 'Gemini вернул пустой ответ.',
    'gemini_err_400'        => 'Gemini: неверный запрос или имя модели.',
    'gemini_err_401'        => 'Gemini: неверный API-ключ или доступ запрещён.',
    'gemini_err_429'        => 'Gemini: превышен лимит запросов. Повторите позже.',
    'gemini_err_500'        => 'Gemini: внутренняя ошибка сервиса. Повторите позже.',
];
