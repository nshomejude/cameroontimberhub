<?php

/* Machine-translated (first pass) — flagged for native speaker review before this ships as a claimed-accurate translation. Do not remove this notice until reviewed. */

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => ':attribute 必须被接受。',
    'accepted_if' => '当 :other 为 :value 时，:attribute 必须被接受。',
    'active_url' => ':attribute 必须是有效的网址。',
    'after' => ':attribute 必须是晚于 :date 的日期。',
    'after_or_equal' => ':attribute 必须是不早于 :date 的日期。',
    'alpha' => ':attribute 只能包含字母。',
    'alpha_dash' => ':attribute 只能包含字母、数字、连字符和下划线。',
    'alpha_num' => ':attribute 只能包含字母和数字。',
    'any_of' => ':attribute 无效。',
    'array' => ':attribute 必须是一个数组。',
    'ascii' => ':attribute 只能包含单字节的字母数字字符和符号。',
    'before' => ':attribute 必须是早于 :date 的日期。',
    'before_or_equal' => ':attribute 必须是不晚于 :date 的日期。',
    'between' => [
        'array' => ':attribute 必须包含 :min 到 :max 个元素。',
        'file' => ':attribute 必须介于 :min 到 :max KB 之间。',
        'numeric' => ':attribute 必须介于 :min 到 :max 之间。',
        'string' => ':attribute 必须介于 :min 到 :max 个字符之间。',
    ],
    'boolean' => ':attribute 必须为真或假。',
    'can' => ':attribute 包含未被授权的值。',
    'confirmed' => ':attribute 的确认不匹配。',
    'contains' => ':attribute 必须包含所需的值。',
    'current_password' => '密码不正确。',
    'date' => ':attribute 必须是有效的日期。',
    'date_equals' => ':attribute 必须是等于 :date 的日期。',
    'date_format' => ':attribute 与格式 :format 不匹配。',
    'decimal' => ':attribute 必须包含 :decimal 位小数。',
    'declined' => ':attribute 必须被拒绝。',
    'declined_if' => '当 :other 为 :value 时，:attribute 必须被拒绝。',
    'different' => ':attribute 和 :other 必须不同。',
    'digits' => ':attribute 必须是 :digits 位数字。',
    'digits_between' => ':attribute 必须介于 :min 到 :max 位数字之间。',
    'dimensions' => ':attribute 图片尺寸无效。',
    'distinct' => ':attribute 存在重复的值。',
    'doesnt_contain' => ':attribute 不能包含以下任何值：:values。',
    'doesnt_end_with' => ':attribute 不能以以下任何值结尾：:values。',
    'doesnt_start_with' => ':attribute 不能以以下任何值开头：:values。',
    'email' => ':attribute 必须是一个有效的电子邮件地址。',
    'encoding' => ':attribute 必须采用 :encoding 编码。',
    'ends_with' => ':attribute 必须以以下值之一结尾：:values。',
    'enum' => '所选的 :attribute 无效。',
    'exists' => '所选的 :attribute 无效。',
    'extensions' => ':attribute 必须具有以下扩展名之一：:values。',
    'file' => ':attribute 必须是一个文件。',
    'filled' => ':attribute 字段必须有值。',
    'gt' => [
        'array' => ':attribute 必须包含多于 :value 个元素。',
        'file' => ':attribute 必须大于 :value KB。',
        'numeric' => ':attribute 必须大于 :value。',
        'string' => ':attribute 必须多于 :value 个字符。',
    ],
    'gte' => [
        'array' => ':attribute 必须包含 :value 个或更多元素。',
        'file' => ':attribute 必须大于或等于 :value KB。',
        'numeric' => ':attribute 必须大于或等于 :value。',
        'string' => ':attribute 必须至少包含 :value 个字符。',
    ],
    'hex_color' => ':attribute 必须是有效的十六进制颜色。',
    'image' => ':attribute 必须是一张图片。',
    'in' => '所选的 :attribute 无效。',
    'in_array' => ':attribute 字段必须存在于 :other 中。',
    'in_array_keys' => ':attribute 必须至少包含以下键之一：:values。',
    'integer' => ':attribute 必须是整数。',
    'ip' => ':attribute 必须是有效的 IP 地址。',
    'ipv4' => ':attribute 必须是有效的 IPv4 地址。',
    'ipv6' => ':attribute 必须是有效的 IPv6 地址。',
    'json' => ':attribute 必须是有效的 JSON 字符串。',
    'list' => ':attribute 必须是一个列表。',
    'lowercase' => ':attribute 必须为小写。',
    'lt' => [
        'array' => ':attribute 必须包含少于 :value 个元素。',
        'file' => ':attribute 必须小于 :value KB。',
        'numeric' => ':attribute 必须小于 :value。',
        'string' => ':attribute 必须少于 :value 个字符。',
    ],
    'lte' => [
        'array' => ':attribute 不能包含多于 :value 个元素。',
        'file' => ':attribute 必须小于或等于 :value KB。',
        'numeric' => ':attribute 必须小于或等于 :value。',
        'string' => ':attribute 最多包含 :value 个字符。',
    ],
    'mac_address' => ':attribute 必须是有效的 MAC 地址。',
    'max' => [
        'array' => ':attribute 不能包含多于 :max 个元素。',
        'file' => ':attribute 不能大于 :max KB。',
        'numeric' => ':attribute 不能大于 :max。',
        'string' => ':attribute 不能超过 :max 个字符。',
    ],
    'max_digits' => ':attribute 不能超过 :max 位数字。',
    'mimes' => ':attribute 必须是以下类型的文件：:values。',
    'mimetypes' => ':attribute 必须是以下类型的文件：:values。',
    'min' => [
        'array' => ':attribute 必须至少包含 :min 个元素。',
        'file' => ':attribute 至少必须为 :min KB。',
        'numeric' => ':attribute 至少必须为 :min。',
        'string' => ':attribute 必须至少包含 :min 个字符。',
    ],
    'min_digits' => ':attribute 必须至少包含 :min 位数字。',
    'missing' => ':attribute 字段必须缺失。',
    'missing_if' => '当 :other 为 :value 时，:attribute 字段必须缺失。',
    'missing_unless' => '除非 :other 为 :value，否则 :attribute 字段必须缺失。',
    'missing_with' => '当 :values 存在时，:attribute 字段必须缺失。',
    'missing_with_all' => '当 :values 都存在时，:attribute 字段必须缺失。',
    'multiple_of' => ':attribute 必须是 :value 的倍数。',
    'not_in' => '所选的 :attribute 无效。',
    'not_regex' => ':attribute 格式无效。',
    'numeric' => ':attribute 必须是数字。',
    'password' => [
        'letters' => ':attribute 必须至少包含一个字母。',
        'mixed' => ':attribute 必须至少包含一个大写字母和一个小写字母。',
        'numbers' => ':attribute 必须至少包含一个数字。',
        'symbols' => ':attribute 必须至少包含一个符号。',
        'uncompromised' => '给定的 :attribute 已出现在数据泄露事件中，请选择其他 :attribute。',
    ],
    'present' => ':attribute 字段必须存在。',
    'present_if' => '当 :other 为 :value 时，:attribute 字段必须存在。',
    'present_unless' => '除非 :other 为 :value，否则 :attribute 字段必须存在。',
    'present_with' => '当 :values 存在时，:attribute 字段必须存在。',
    'present_with_all' => '当 :values 都存在时，:attribute 字段必须存在。',
    'prohibited' => ':attribute 字段是被禁止的。',
    'prohibited_if' => '当 :other 为 :value 时，:attribute 字段是被禁止的。',
    'prohibited_if_accepted' => '当 :other 被接受时，:attribute 字段是被禁止的。',
    'prohibited_if_declined' => '当 :other 被拒绝时，:attribute 字段是被禁止的。',
    'prohibited_unless' => '除非 :other 在 :values 中，否则 :attribute 字段是被禁止的。',
    'prohibits' => ':attribute 字段禁止 :other 存在。',
    'regex' => ':attribute 格式无效。',
    'required' => ':attribute 字段为必填项。',
    'required_array_keys' => ':attribute 字段必须包含以下条目：:values。',
    'required_if' => '当 :other 为 :value 时，:attribute 字段为必填项。',
    'required_if_accepted' => '当 :other 被接受时，:attribute 字段为必填项。',
    'required_if_declined' => '当 :other 被拒绝时，:attribute 字段为必填项。',
    'required_unless' => '除非 :other 在 :values 中，否则 :attribute 字段为必填项。',
    'required_with' => '当 :values 存在时，:attribute 字段为必填项。',
    'required_with_all' => '当 :values 都存在时，:attribute 字段为必填项。',
    'required_without' => '当 :values 不存在时，:attribute 字段为必填项。',
    'required_without_all' => '当以上 :values 均不存在时，:attribute 字段为必填项。',
    'same' => ':attribute 和 :other 必须匹配。',
    'size' => [
        'array' => ':attribute 必须包含 :size 个元素。',
        'file' => ':attribute 大小必须为 :size KB。',
        'numeric' => ':attribute 必须为 :size。',
        'string' => ':attribute 必须包含 :size 个字符。',
    ],
    'starts_with' => ':attribute 必须以以下值之一开头：:values。',
    'string' => ':attribute 必须是一个字符串。',
    'timezone' => ':attribute 必须是有效的时区。',
    'unique' => ':attribute 的值已被使用。',
    'uploaded' => ':attribute 上传失败。',
    'uppercase' => ':attribute 必须为大写。',
    'url' => ':attribute 必须是一个有效的网址。',
    'ulid' => ':attribute 必须是有效的 ULID。',
    'uuid' => ':attribute 必须是有效的 UUID。',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [
        'name' => '姓名',
        'email' => '电子邮箱地址',
        'password' => '密码',
        'password_confirmation' => '确认密码',
        'phone' => '电话号码',
        'company' => '公司',
    ],

];
