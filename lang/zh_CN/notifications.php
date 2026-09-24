<?php

/* Machine-translated (first pass) — flagged for native speaker review before this ships as a claimed-accurate translation. Do not remove this notice until reviewed. */

// Simplified Chinese (zh_CN) translation of lang/en/notifications.php —
// formal, professional transactional-email register. Structurally mirrored
// from lang/fr/notifications.php.
//
// Locale: notifications sent to a user currently render in
// config('app.locale') because there is no users.locale column yet.
// TODO: per-recipient locale once users.locale is available.

return [

    'rfq_verification' => [
        'subject' => '请确认您的询价请求 —— :reference',
        'heading' => '确认您的请求',
        'intro' => '感谢您提交请求 :reference。请确认您的电子邮箱地址，以便我们将其转发给经验证的喀麦隆木材出口商。',
        'action' => '确认请求',
        'disclaimer' => '提交请求并不构成合同。买家在进行任何交易前应自行开展尽职调查。',
        'salutation' => '谢谢，',
    ],

    'inquiry_verification' => [
        'subject' => '请确认您的消息 —— Cameroon Timber Hub',
        'heading' => '确认您的消息',
        'intro' => '请确认您的电子邮箱地址，以便您的消息能够转发给该出口商。',
        'action' => '确认消息',
        'disclaimer' => '买家在进行任何交易前应自行开展尽职调查。',
        'salutation' => '谢谢，',
    ],

    'quote_submitted' => [
        'subject' => '针对 :reference 的新报价 —— :company',
        'heading' => '您收到了一份新报价',
        'intro' => ':company 已回复您的请求 :reference。',
        'quote_reference' => '报价编号：',
        'total' => '总额：',
        'lead_time' => '交货周期：',
        'lead_time_value' => ':days 天',
        'valid_until' => '有效期至：',
        'action' => '查看您的报价',
        'personal_link' => '此链接为您的专属请求链接——请勿转发。',
        'disclaimer' => '收到报价并不构成合同。买家在进行任何交易前应自行开展尽职调查。',
        'salutation' => '谢谢，',
    ],

    'contact_message' => [
        'subject' => '[CTH联系表单] :subject',
        'heading' => '来自联系表单的新消息',
        'name' => '姓名：',
        'company' => '企业：',
        'email' => '电子邮箱：',
        'phone' => '电话：',
        'subject_label' => '主题：',
        'reply_hint' => '直接回复此邮件即可联系发件人。',
    ],

    'error_digest' => [
        'subject' => '[:app] 错误摘要 —— 上一周期共 :count 个错误',
    ],

    'company_verified' => [
        'subject' => '您的企业已通过验证 —— :company',
        'line_1' => '恭喜！:company 已通过 Cameroon Timber Hub 团队的验证。',
        'line_2' => '您的“已验证”徽章现已在公开名录中显示。',
        'action' => '前往您的控制台',
        'line_3' => '相关文件由 Cameroon Timber Hub 根据企业提供的信息进行审核。买家在进行任何交易前应自行开展尽职调查。',
    ],

    'document_expiring' => [
        'subject' => '合规文件即将到期 —— :company',
        'fallback_type' => '合规文件',
        'expired_line_1' => '您的:type已过期。',
        'expired_line_2' => '请上传最新文件，以保持您的认证资料处于有效状态。',
        'expiring_line_1' => '您的:type将在 :days 天后（即 :date）到期。',
        'expiring_line_2' => '请在到期前完成续期，以保持您的认证资料有效。',
        'action' => '管理文件',
    ],

    'rfq_routed_to_exporter' => [
        'subject' => '新买家询价 —— :reference',
        'line_1' => '一位经验证买家的请求已转发给贵企业。',
        'action' => '在控制台中查看',
        'line_2' => '编号：:reference',
    ],

    'subscription_renewal' => [
        'subject' => '您的 :plan 套餐即将续订',
        'line_1' => '您的 :plan 订阅将于 :date 续订，金额为 :amount。',
        'line_2' => '移动支付（Mobile Money）无法自动扣款：请在本计费周期结束前通过下方链接完成续订。',
        'action' => '立即续订',
        'line_3' => '若未采取任何操作，您的套餐将在续订日期后进入7天宽限期，随后自动转为免费套餐。',
    ],

    'subscription_past_due' => [
        'subject' => '付款逾期 —— :plan 套餐',
        'line_1' => '我们尚未收到您 :plan 订阅的 :amount 付款。',
        'line_2' => '您的访问权限将保留至 :date。请在此日期前完成续订，以避免服务中断。',
        'action' => '立即续订',
        'line_3' => '此日期之后，贵企业将转为免费套餐，付费功能将被停用。',
    ],

    'subscription_lapsed' => [
        'subject' => '您的订阅已过期 —— 现已转为免费套餐',
        'line_1' => '您的 :plan 订阅未能续订；贵企业现已转为 :free 套餐。',
        'line_2' => '您的数据已被保留。您可随时重新订阅以恢复付费功能。',
        'action' => '查看套餐',
    ],

    'push' => [
        'quote_received' => [
            'title' => '收到新报价',
            'body' => ':supplier 已针对请求 :rfq 提交了一份报价。',
        ],
        'order_status_changed' => [
            'title' => '订单状态已更新',
            'body' => '订单 :order 现在的状态为 :status。',
        ],
        'message_received' => [
            'title' => '来自 :sender 的新消息',
        ],
        'dispute_reply' => [
            'title' => '您的纠纷收到了新回复',
        ],
        'quote_accepted' => [
            'title' => '您的报价已被接受',
            'body' => '买家已接受您针对请求 :rfq 提交的报价。',
        ],
        'quote_declined' => [
            'title' => '您的报价已被拒绝',
            'body' => '买家已拒绝您的报价 :quote。',
        ],
        'counter_offer' => [
            'title' => '新的还价',
            'body' => ':party 已针对报价 :quote 发送了一份还价。',
        ],
        'payment_requested' => [
            'title' => '已请求付款',
            'body' => '供应商已请求订单 :order 的付款。',
        ],
        'payment_confirmed' => [
            'title' => '付款已记录',
            'body' => '订单 :order 的一笔付款已被记录。',
        ],
        'shipment_update' => [
            'title' => '发货信息已更新',
            'body' => '订单 :order 的发货详情已更新。',
        ],
        'document_uploaded' => [
            'title' => '新的订单文件',
            'body' => '订单 :order 已添加一份新文件。',
        ],
        'dispute_opened' => [
            'title' => '已提出一项纠纷',
            'body' => '订单 :order 已被提出纠纷。',
        ],
        'rfq_routed' => [
            'title' => '新买家请求',
            'body' => '一位经验证买家的请求已转发给贵企业。',
        ],
    ],

    'trial_ended' => [
        'subject' => '您的 :plan 免费试用已结束',
        'line_1' => '您的 :plan 免费试用已结束，且未产生任何付款。',
        'line_2' => '贵企业现已转为免费套餐。请在下方订阅以保留付费功能。',
        'action' => '立即订阅',
    ],

];
