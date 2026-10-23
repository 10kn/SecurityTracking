<?php

/*
 * Only the rules the admin forms use. Missing keys fall back to English (APP_FALLBACK_LOCALE).
 */
return [
    'array' => ':attribute phải là một danh sách.',
    'email' => ':attribute phải là email hợp lệ.',
    'exists' => ':attribute đã chọn không tồn tại.',
    'file' => ':attribute phải là một file.',
    'in' => ':attribute đã chọn không hợp lệ.',
    'integer' => ':attribute phải là số nguyên.',
    'max' => [
        'array' => ':attribute không được quá :max mục.',
        'file' => ':attribute không được lớn hơn :max KB.',
        'numeric' => ':attribute không được lớn hơn :max.',
        'string' => ':attribute không được dài quá :max ký tự.',
    ],
    'min' => [
        'array' => ':attribute phải có ít nhất :min mục.',
        'file' => ':attribute phải lớn hơn :min KB.',
        'numeric' => ':attribute phải lớn hơn hoặc bằng :min.',
        'string' => ':attribute phải có ít nhất :min ký tự.',
    ],
    'regex' => ':attribute không đúng định dạng.',
    'required' => 'Nhập :attribute.',
    'starts_with' => ':attribute phải bắt đầu bằng: :values.',
    'string' => ':attribute phải là chuỗi ký tự.',
    'unique' => ':attribute đã được dùng.',
    'url' => ':attribute phải là URL hợp lệ.',
];
