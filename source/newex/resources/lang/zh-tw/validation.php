<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 驗證語言行
    |--------------------------------------------------------------------------
    |
    | 以下語言行包含 Validator 類別所使用的預設錯誤訊息。
    | 部分規則有多種版本，例如 size 規則。您可以依需求自行修改。
    |
    */

    'accepted' => ':attribute 必須接受。',
    'active_url' => ':attribute 不是有效的 URL。',
    'after' => ':attribute 必須是 :date 之後的日期。',
    'after_or_equal' => ':attribute 必須是 :date 之後或相同的日期。',
    'alpha' => ':attribute 只能包含字母。',
    'alpha_dash' => ':attribute 只能包含字母、數字、破折號與底線。',
    'alpha_num' => ':attribute 只能包含字母與數字。',
    'array' => ':attribute 必須是陣列。',
    'before' => ':attribute 必須是 :date 之前的日期。',
    'before_or_equal' => ':attribute 必須是 :date 之前或相同的日期。',

    'between' => [
        'numeric' => ':attribute 必須介於 :min 與 :max 之間。',
        'file' => ':attribute 必須介於 :min 與 :max KB 之間。',
        'string' => ':attribute 必須介於 :min 與 :max 個字元之間。',
        'array' => ':attribute 必須包含 :min 到 :max 個項目。',
    ],

    'boolean' => ':attribute 欄位必須為 true 或 false。',
    'confirmed' => ':attribute 確認不一致。',
    'date' => ':attribute 不是有效的日期。',
    'date_equals' => ':attribute 必須是等於 :date 的日期。',
    'date_format' => ':attribute 與格式 :format 不符。',
    'different' => ':attribute 與 :other 必須不同。',
    'digits' => ':attribute 必須為 :digits 位數字。',
    'digits_between' => ':attribute 必須介於 :min 到 :max 位數字。',
    'dimensions' => ':attribute 圖片尺寸無效。',
    'distinct' => ':attribute 欄位包含重複值。',
    'email' => ':attribute 必須是有效的電子郵件地址。',
    'ends_with' => ':attribute 必須以下列其中之一結尾: :values。',
    'exists' => '所選的 :attribute 無效。',
    'file' => ':attribute 必須是檔案。',
    'filled' => ':attribute 欄位必須有值。',

    'gt' => [
        'numeric' => ':attribute 必須大於 :value。',
        'file' => ':attribute 必須大於 :value KB。',
        'string' => ':attribute 必須多於 :value 個字元。',
        'array' => ':attribute 必須包含超過 :value 個項目。',
    ],

    'gte' => [
        'numeric' => ':attribute 必須大於或等於 :value。',
        'file' => ':attribute 必須大於或等於 :value KB。',
        'string' => ':attribute 必須大於或等於 :value 個字元。',
        'array' => ':attribute 必須至少包含 :value 個項目。',
    ],

    'image' => ':attribute 必須是圖片。',
    'in' => '所選的 :attribute 無效。',
    'in_array' => ':attribute 欄位不存在於 :other。',
    'integer' => ':attribute 必須是整數。',
    'ip' => ':attribute 必須是有效的 IP 位址。',
    'ipv4' => ':attribute 必須是有效的 IPv4 位址。',
    'ipv6' => ':attribute 必須是有效的 IPv6 位址。',
    'json' => ':attribute 必須是有效的 JSON 字串。',

    'lt' => [
        'numeric' => ':attribute 必須小於 :value。',
        'file' => ':attribute 必須小於 :value KB。',
        'string' => ':attribute 必須少於 :value 個字元。',
        'array' => ':attribute 必須少於 :value 個項目。',
    ],

    'lte' => [
        'numeric' => ':attribute 必須小於或等於 :value。',
        'file' => ':attribute 必須小於或等於 :value KB。',
        'string' => ':attribute 必須小於或等於 :value 個字元。',
        'array' => ':attribute 不能超過 :value 個項目。',
    ],

    'max' => [
        'numeric' => ':attribute 不得大於 :max。',
        'file' => ':attribute 不得大於 :max KB。',
        'string' => ':attribute 不得多於 :max 個字元。',
        'array' => ':attribute 不得多於 :max 個項目。',
    ],

    'mimes' => ':attribute 必須是類型為 :values 的檔案。',
    'mimetypes' => ':attribute 必須是類型為 :values 的檔案。',

    'min' => [
        'numeric' => ':attribute 至少為 :min。',
        'file' => ':attribute 至少為 :min KB。',
        'string' => ':attribute 至少為 :min 個字元。',
        'array' => ':attribute 至少包含 :min 個項目。',
    ],

    'multiple_of' => ':attribute 必須是 :value 的倍數。',
    'not_in' => '所選的 :attribute 無效。',
    'not_regex' => ':attribute 格式無效。',
    'numeric' => ':attribute 必須為數字。',
    'password' => '密碼不正確。',
    'present' => ':attribute 欄位必須存在。',
    'regex' => ':attribute 格式無效。',
    'required' => ':attribute 欄位為必填。',
    'required_if' => '當 :other 為 :value 時，:attribute 為必填。',
    'required_unless' => '除非 :other 在 :values 中，否則 :attribute 為必填。',
    'required_with' => '當 :values 存在時，:attribute 為必填。',
    'required_with_all' => '當 :values 都存在時，:attribute 為必填。',
    'required_without' => '當 :values 不存在時，:attribute 為必填。',
    'required_without_all' => '當 :values 都不存在時，:attribute 為必填。',
    'same' => ':attribute 與 :other 必須相同。',

    'size' => [
        'numeric' => ':attribute 必須為 :size。',
        'file' => ':attribute 必須為 :size KB。',
        'string' => ':attribute 必須為 :size 個字元。',
        'array' => ':attribute 必須包含 :size 個項目。',
    ],

    'starts_with' => ':attribute 必須以下列其中之一開頭: :values。',
    'string' => ':attribute 必須是字串。',
    'timezone' => ':attribute 必須是有效的時區。',
    'unique' => ':attribute 已經被使用。',
    'uploaded' => ':attribute 上傳失敗。',
    'url' => ':attribute 格式無效。',
    'uuid' => ':attribute 必須是有效的 UUID。',

    /*
    |--------------------------------------------------------------------------
    | 自訂驗證語言行
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => '自訂訊息',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 自訂屬性名稱
    |--------------------------------------------------------------------------
    */

    'attributes' => ['email'=>'電子郵件','password'=>'密碼','password_confirmation'=>'確認密碼','email_code'=>'郵箱驗證碼','terms'=>'使用者協議','referral'=>'邀請碼'],

];