<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 验证语言行
    |--------------------------------------------------------------------------
    |
    | 以下语言行包含 Validator 类别所使用的预设错误消息。
    | 部分规则有多种版本，例如 size 规则。您可以依需求自行修改。
    |
    */

    'accepted' => ':attribute 必须接受。',
    'active_url' => ':attribute 不是有效的 URL。',
    'after' => ':attribute 必须是 :date 之后的日期。',
    'after_or_equal' => ':attribute 必须是 :date 之后或相同的日期。',
    'alpha' => ':attribute 只能包含字母。',
    'alpha_dash' => ':attribute 只能包含字母、数字、破折号与底线。',
    'alpha_num' => ':attribute 只能包含字母与数字。',
    'array' => ':attribute 必须是阵列。',
    'before' => ':attribute 必须是 :date 之前的日期。',
    'before_or_equal' => ':attribute 必须是 :date 之前或相同的日期。',

    'between' => [
        'numeric' => ':attribute 必须介于 :min 与 :max 之间。',
        'file' => ':attribute 必须介于 :min 与 :max KB 之间。',
        'string' => ':attribute 必须介于 :min 与 :max 个字元之间。',
        'array' => ':attribute 必须包含 :min 到 :max 个项目。',
    ],

    'boolean' => ':attribute 栏位必须为 true 或 false。',
    'confirmed' => ':attribute 确认不一致。',
    'date' => ':attribute 不是有效的日期。',
    'date_equals' => ':attribute 必须是等于 :date 的日期。',
    'date_format' => ':attribute 与格式 :format 不符。',
    'different' => ':attribute 与 :other 必须不同。',
    'digits' => ':attribute 必须为 :digits 位数字。',
    'digits_between' => ':attribute 必须介于 :min 到 :max 位数字。',
    'dimensions' => ':attribute 图片尺寸无效。',
    'distinct' => ':attribute 栏位包含重复值。',
    'email' => ':attribute 必须是有效的电子邮件地址。',
    'ends_with' => ':attribute 必须以下列其中之一结尾: :values。',
    'exists' => '所选的 :attribute 无效。',
    'file' => ':attribute 必须是文件。',
    'filled' => ':attribute 栏位必须有值。',

    'gt' => [
        'numeric' => ':attribute 必须大于 :value。',
        'file' => ':attribute 必须大于 :value KB。',
        'string' => ':attribute 必须多于 :value 个字元。',
        'array' => ':attribute 必须包含超过 :value 个项目。',
    ],

    'gte' => [
        'numeric' => ':attribute 必须大于或等于 :value。',
        'file' => ':attribute 必须大于或等于 :value KB。',
        'string' => ':attribute 必须大于或等于 :value 个字元。',
        'array' => ':attribute 必须至少包含 :value 个项目。',
    ],

    'image' => ':attribute 必须是图片。',
    'in' => '所选的 :attribute 无效。',
    'in_array' => ':attribute 栏位不存在于 :other。',
    'integer' => ':attribute 必须是整数。',
    'ip' => ':attribute 必须是有效的 IP 位址。',
    'ipv4' => ':attribute 必须是有效的 IPv4 位址。',
    'ipv6' => ':attribute 必须是有效的 IPv6 位址。',
    'json' => ':attribute 必须是有效的 JSON 字串。',

    'lt' => [
        'numeric' => ':attribute 必须小于 :value。',
        'file' => ':attribute 必须小于 :value KB。',
        'string' => ':attribute 必须少于 :value 个字元。',
        'array' => ':attribute 必须少于 :value 个项目。',
    ],

    'lte' => [
        'numeric' => ':attribute 必须小于或等于 :value。',
        'file' => ':attribute 必须小于或等于 :value KB。',
        'string' => ':attribute 必须小于或等于 :value 个字元。',
        'array' => ':attribute 不能超过 :value 个项目。',
    ],

    'max' => [
        'numeric' => ':attribute 不得大于 :max。',
        'file' => ':attribute 不得大于 :max KB。',
        'string' => ':attribute 不得多于 :max 个字元。',
        'array' => ':attribute 不得多于 :max 个项目。',
    ],

    'mimes' => ':attribute 必须是类型为 :values 的文件。',
    'mimetypes' => ':attribute 必须是类型为 :values 的文件。',

    'min' => [
        'numeric' => ':attribute 至少为 :min。',
        'file' => ':attribute 至少为 :min KB。',
        'string' => ':attribute 至少为 :min 个字元。',
        'array' => ':attribute 至少包含 :min 个项目。',
    ],

    'multiple_of' => ':attribute 必须是 :value 的倍数。',
    'not_in' => '所选的 :attribute 无效。',
    'not_regex' => ':attribute 格式无效。',
    'numeric' => ':attribute 必须为数字。',
    'password' => '密码不正确。',
    'present' => ':attribute 栏位必须存在。',
    'regex' => ':attribute 格式无效。',
    'required' => ':attribute 栏位为必填。',
    'required_if' => '当 :other 为 :value 时，:attribute 为必填。',
    'required_unless' => '除非 :other 在 :values 中，否则 :attribute 为必填。',
    'required_with' => '当 :values 存在时，:attribute 为必填。',
    'required_with_all' => '当 :values 都存在时，:attribute 为必填。',
    'required_without' => '当 :values 不存在时，:attribute 为必填。',
    'required_without_all' => '当 :values 都不存在时，:attribute 为必填。',
    'same' => ':attribute 与 :other 必须相同。',

    'size' => [
        'numeric' => ':attribute 必须为 :size。',
        'file' => ':attribute 必须为 :size KB。',
        'string' => ':attribute 必须为 :size 个字元。',
        'array' => ':attribute 必须包含 :size 个项目。',
    ],

    'starts_with' => ':attribute 必须以下列其中之一开头: :values。',
    'string' => ':attribute 必须是字串。',
    'timezone' => ':attribute 必须是有效的时区。',
    'unique' => ':attribute 已经被使用。',
    'uploaded' => ':attribute 上传失败。',
    'url' => ':attribute 格式无效。',
    'uuid' => ':attribute 必须是有效的 UUID。',

    /*
    |--------------------------------------------------------------------------
    | 自订验证语言行
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => '自订消息',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 自订属性名称
    |--------------------------------------------------------------------------
    */

    'attributes' => ['email'=>'邮箱','password'=>'密码','password_confirmation'=>'确认密码','email_code'=>'邮箱验证码','terms'=>'用户协议','referral'=>'邀请码'],

];