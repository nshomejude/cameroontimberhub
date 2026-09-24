<?php

/* Machine-translated (first pass) — flagged for native speaker review before this ships as a claimed-accurate translation. Do not remove this notice until reviewed. */

// Locale: notifications sent to a user currently render in config('app.locale')
// because there is no users.locale column yet.
// TODO: per-recipient locale once users.locale is available.

return [

    'rfq_verification' => [
        'subject' => 'Xác nhận yêu cầu báo giá của bạn — :reference',
        'heading' => 'Xác nhận yêu cầu của bạn',
        'intro' => 'Cảm ơn bạn đã gửi yêu cầu :reference. Vui lòng xác nhận địa chỉ email của bạn để chúng tôi có thể chuyển yêu cầu này đến các nhà xuất khẩu gỗ Cameroon đã xác minh.',
        'action' => 'Xác nhận yêu cầu',
        'disclaimer' => 'Việc gửi một yêu cầu không phải là một hợp đồng. Người mua nên tự thực hiện xác minh trước khi giao dịch.',
        'salutation' => 'Cảm ơn,',
    ],

    'inquiry_verification' => [
        'subject' => 'Xác nhận tin nhắn của bạn — Cameroon Timber Hub',
        'heading' => 'Xác nhận tin nhắn của bạn',
        'intro' => 'Vui lòng xác nhận địa chỉ email của bạn để tin nhắn của bạn có thể được chuyển đến nhà xuất khẩu.',
        'action' => 'Xác nhận tin nhắn',
        'disclaimer' => 'Người mua nên tự thực hiện xác minh trước khi giao dịch.',
        'salutation' => 'Cảm ơn,',
    ],

    'quote_submitted' => [
        'subject' => 'Báo giá mới cho :reference — :company',
        'heading' => 'Bạn đã nhận được báo giá mới',
        'intro' => ':company đã phản hồi yêu cầu :reference của bạn.',
        'quote_reference' => 'Mã tham chiếu báo giá:',
        'total' => 'Tổng cộng:',
        'lead_time' => 'Thời gian giao hàng:',
        'lead_time_value' => ':days ngày',
        'valid_until' => 'Có hiệu lực đến:',
        'action' => 'Xem báo giá của bạn',
        'personal_link' => 'Liên kết này là riêng cho yêu cầu của bạn — vui lòng không chuyển tiếp.',
        'disclaimer' => 'Việc nhận một báo giá không phải là một hợp đồng. Người mua nên tự thực hiện xác minh trước khi giao dịch.',
        'salutation' => 'Cảm ơn,',
    ],

    'contact_message' => [
        'subject' => '[CTH Liên hệ] :subject',
        'heading' => 'Tin nhắn mới từ biểu mẫu liên hệ',
        'name' => 'Tên:',
        'company' => 'Doanh nghiệp:',
        'email' => 'Email:',
        'phone' => 'Điện thoại:',
        'subject_label' => 'Chủ đề:',
        'reply_hint' => 'Trả lời trực tiếp email này để liên hệ với người gửi.',
    ],

    'error_digest' => [
        'subject' => '[:app] Tổng hợp lỗi — :count lỗi trong khoảng thời gian gần đây',
    ],

    'company_verified' => [
        'subject' => 'Doanh nghiệp của bạn đã được xác minh — :company',
        'line_1' => 'Chúc mừng! :company đã được đội ngũ Cameroon Timber Hub xác minh.',
        'line_2' => 'Huy hiệu "đã xác minh" của bạn hiện đã hiển thị trong danh bạ công khai.',
        'action' => 'Đi đến bảng điều khiển của bạn',
        'line_3' => 'Tài liệu được Cameroon Timber Hub xem xét dựa trên thông tin do doanh nghiệp cung cấp. Người mua nên tự thực hiện xác minh trước khi giao dịch.',
    ],

    'document_expiring' => [
        'subject' => 'Tài liệu tuân thủ sắp hết hạn — :company',
        'fallback_type' => 'tài liệu tuân thủ',
        'expired_line_1' => ':type của bạn đã hết hạn.',
        'expired_line_2' => 'Vui lòng tải lên tài liệu cập nhật để duy trì hồ sơ đã xác minh của bạn ở trạng thái hoạt động.',
        'expiring_line_1' => ':type của bạn sẽ hết hạn trong :days ngày (vào ngày :date).',
        'expiring_line_2' => 'Vui lòng gia hạn trước khi hết hạn để duy trì hồ sơ đã xác minh của bạn.',
        'action' => 'Quản lý tài liệu',
    ],

    'rfq_routed_to_exporter' => [
        'subject' => 'Khách hàng tiềm năng mới — :reference',
        'line_1' => 'Một yêu cầu từ người mua đã xác minh đã được chuyển đến doanh nghiệp của bạn.',
        'action' => 'Xem trong bảng điều khiển của bạn',
        'line_2' => 'Mã tham chiếu: :reference',
    ],

    'subscription_renewal' => [
        'subject' => 'Gói :plan của bạn sắp gia hạn',
        'line_1' => 'Gói đăng ký :plan của bạn cần được gia hạn vào :date với số tiền :amount.',
        'line_2' => 'Thanh toán qua mobile money không thể được trừ tự động: vui lòng gia hạn qua liên kết bên dưới trước khi kết thúc kỳ hạn của bạn.',
        'action' => 'Gia hạn ngay',
        'line_3' => 'Nếu không có hành động từ bạn, gói của bạn sẽ chuyển sang thời gian ân hạn 7 ngày sau ngày gia hạn, sau đó chuyển về gói miễn phí.',
    ],

    'subscription_past_due' => [
        'subject' => 'Thanh toán quá hạn — gói :plan',
        'line_1' => 'Chúng tôi chưa nhận được khoản thanh toán :amount cho gói đăng ký :plan của bạn.',
        'line_2' => 'Quyền truy cập của bạn được duy trì đến ngày :date. Hãy gia hạn trước ngày này để tránh bị gián đoạn.',
        'action' => 'Gia hạn ngay',
        'line_3' => 'Sau ngày này, doanh nghiệp của bạn sẽ chuyển về gói miễn phí và các tính năng trả phí sẽ bị vô hiệu hóa.',
    ],

    'subscription_lapsed' => [
        'subject' => 'Gói đăng ký của bạn đã hết hạn — bạn hiện đang dùng gói miễn phí',
        'line_1' => 'Gói đăng ký :plan của bạn chưa được gia hạn; doanh nghiệp của bạn hiện đang dùng gói :free.',
        'line_2' => 'Dữ liệu của bạn được giữ nguyên. Bạn có thể đăng ký lại bất kỳ lúc nào để khôi phục các tính năng trả phí.',
        'action' => 'Xem các gói',
    ],

    'push' => [
        'quote_received' => [
            'title' => 'Đã nhận báo giá mới',
            'body' => ':supplier đã gửi báo giá cho yêu cầu :rfq.',
        ],
        'order_status_changed' => [
            'title' => 'Trạng thái đơn hàng đã cập nhật',
            'body' => 'Đơn hàng :order hiện đang :status.',
        ],
        'message_received' => [
            'title' => 'Tin nhắn mới từ :sender',
        ],
        'dispute_reply' => [
            'title' => 'Phản hồi mới cho tranh chấp của bạn',
        ],
        'quote_accepted' => [
            'title' => 'Báo giá của bạn đã được chấp nhận',
            'body' => 'Người mua đã chấp nhận báo giá của bạn cho yêu cầu :rfq.',
        ],
        'quote_declined' => [
            'title' => 'Báo giá của bạn đã bị từ chối',
            'body' => 'Người mua đã từ chối báo giá :quote của bạn.',
        ],
        'counter_offer' => [
            'title' => 'Đề nghị ngược mới',
            'body' => ':party đã gửi một đề nghị ngược cho báo giá :quote.',
        ],
        'payment_requested' => [
            'title' => 'Yêu cầu thanh toán',
            'body' => 'Nhà cung cấp đã yêu cầu thanh toán cho đơn hàng :order.',
        ],
        'payment_confirmed' => [
            'title' => 'Đã ghi nhận thanh toán',
            'body' => 'Một khoản thanh toán đã được ghi nhận cho đơn hàng :order.',
        ],
        'shipment_update' => [
            'title' => 'Cập nhật vận chuyển',
            'body' => 'Chi tiết vận chuyển đã được cập nhật cho đơn hàng :order.',
        ],
        'document_uploaded' => [
            'title' => 'Tài liệu đơn hàng mới',
            'body' => 'Một tài liệu mới đã được thêm vào đơn hàng :order.',
        ],
        'dispute_opened' => [
            'title' => 'Một tranh chấp đã được mở',
            'body' => 'Một tranh chấp đã được mở cho đơn hàng :order.',
        ],
        'rfq_routed' => [
            'title' => 'Yêu cầu người mua mới',
            'body' => 'Một yêu cầu từ người mua đã xác minh đã được chuyển đến doanh nghiệp của bạn.',
        ],
    ],

    'trial_ended' => [
        'subject' => 'Bản dùng thử miễn phí :plan của bạn đã kết thúc',
        'line_1' => 'Bản dùng thử miễn phí :plan của bạn đã kết thúc và chưa có khoản thanh toán nào được thực hiện.',
        'line_2' => 'Doanh nghiệp của bạn hiện đang dùng gói miễn phí. Đăng ký bên dưới để duy trì các tính năng trả phí.',
        'action' => 'Đăng ký',
    ],

];
