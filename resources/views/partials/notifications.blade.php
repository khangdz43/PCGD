@php
    $notificationTypes = [
        'success' => ['title' => 'Thao tác thành công', 'icon' => 'fa-circle-check', 'class' => 'success'],
        'status' => ['title' => 'Đã hoàn tất', 'icon' => 'fa-circle-info', 'class' => 'info'],
        'info' => ['title' => 'Thông tin', 'icon' => 'fa-circle-info', 'class' => 'info'],
        'warning' => ['title' => 'Cần lưu ý', 'icon' => 'fa-triangle-exclamation', 'class' => 'warning'],
        'error' => ['title' => 'Thao tác chưa thành công', 'icon' => 'fa-circle-exclamation', 'class' => 'danger'],
    ];
    $notifications = [];

    foreach ($notificationTypes as $key => $type) {
        $message = session($key);
        if (filled($message)) {
            $notifications[] = [
                ...$type,
                'message' => is_string($message) ? $message : '',
                'details' => [],
            ];
        }
    }

    if ($errors->any()) {
        $notifications[] = [
            ...$notificationTypes['error'],
            'title' => 'Vui lòng kiểm tra lại thông tin',
            'message' => 'Lỗi: ',
            'details' => $errors->all(),
        ];
    }
@endphp

@if (count($notifications))
    <section class="app-notifications @if (collect($notifications)->every(fn ($notification) => in_array($notification['class'], ['success', 'info'], true))) app-notifications-toast @endif" aria-label="Thông báo">
        @foreach ($notifications as $notification)
            <div class="app-notification app-notification-{{ $notification['class'] }}" role="{{ $notification['class'] === 'danger' || $notification['class'] === 'warning' ? 'alert' : 'status' }}" aria-live="{{ $notification['class'] === 'danger' ? 'assertive' : 'polite' }}" @if (in_array($notification['class'], ['success', 'info'], true)) data-notification-autohide @endif>
                <span class="app-notification-icon" aria-hidden="true">
                    <i class="fa-solid {{ $notification['icon'] }}"></i>
                </span>
                <div class="app-notification-content">
                    <h2 class="app-notification-title">{{ $notification['title'] }}</h2>
                    @if ($notification['message'] !== '')
                        <p class="app-notification-message">{{ $notification['message'] }}</p>
                    @endif
                    @if (count($notification['details']))
                        <ul class="app-notification-details">
                            @foreach (array_slice($notification['details'], 0, 5) as $detail)
                                <li>{{ $detail }}</li>
                            @endforeach
                            @if (count($notification['details']) > 5)
                                <li>Còn {{ count($notification['details']) - 5 }} nội dung cần kiểm tra.</li>
                            @endif
                        </ul>
                    @endif
                </div>
                <button class="app-notification-close" type="button" aria-label="Đóng thông báo">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
        @endforeach
    </section>
@endif
