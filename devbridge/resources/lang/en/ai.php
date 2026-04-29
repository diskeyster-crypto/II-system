<?php
return [
    'request_failed'        => 'AI request failed.',
    'invalid_json'          => 'AI returned invalid JSON.',
    'repairing_json'        => 'Trying to repair JSON.',
    'review_done'           => 'AI review completed.',
    'clarify_req'           => 'GPT clarification requested.',
    'spec_generated'        => 'Final task spec generated.',
    // Gemini-specific
    'gemini_key_missing'    => 'Gemini API key is not configured. Add it in Settings → AI.',
    'gemini_no_candidates'  => 'Gemini did not return a usable candidate. The response may have been blocked or empty.',
    'gemini_blocked'        => 'Gemini response was blocked by safety filters.',
    'gemini_empty_response' => 'Gemini returned an empty response.',
    'gemini_err_400'        => 'Gemini: invalid request or model name.',
    'gemini_err_401'        => 'Gemini: invalid API key or access denied.',
    'gemini_err_429'        => 'Gemini: quota or rate limit exceeded. Try again later.',
    'gemini_err_500'        => 'Gemini: service error. Try again later.',
];
