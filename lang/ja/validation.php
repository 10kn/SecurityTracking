<?php

/*
 * Only the rules the admin forms use. Missing keys fall back to English (APP_FALLBACK_LOCALE).
 */
return [
    'array' => ':attribute はリストで指定してください。',
    'email' => ':attribute には正しいメールアドレスを入力してください。',
    'exists' => '選択した :attribute は存在しません。',
    'file' => ':attribute にはファイルを指定してください。',
    'in' => '選択した :attribute は正しくありません。',
    'integer' => ':attribute には整数を入力してください。',
    'max' => [
        'array' => ':attribute は :max 件以下にしてください。',
        'file' => ':attribute は :max KB 以下にしてください。',
        'numeric' => ':attribute は :max 以下にしてください。',
        'string' => ':attribute は :max 文字以内で入力してください。',
    ],
    'min' => [
        'array' => ':attribute は :min 件以上にしてください。',
        'file' => ':attribute は :min KB 以上にしてください。',
        'numeric' => ':attribute は :min 以上にしてください。',
        'string' => ':attribute は :min 文字以上で入力してください。',
    ],
    'regex' => ':attribute の形式が正しくありません。',
    'required' => ':attribute を入力してください。',
    'starts_with' => ':attribute は次で始まる必要があります: :values',
    'string' => ':attribute には文字列を入力してください。',
    'unique' => ':attribute はすでに使われています。',
    'url' => ':attribute には正しい URL を入力してください。',
];
