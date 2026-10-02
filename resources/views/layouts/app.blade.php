<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'PCGD-XMC - Phổ cập giáo dục')</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome (Icons) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

    @stack('styles')
</head>

<body class="bg-light">

    {{-- Header --}}
    @include('partials.header')

    {{-- Navbar --}}
    @include('partials.navbar')

    {{-- Content --}}
    <main class="py-4">
        @yield('content')
    </main>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    @stack('scripts')
    <script>
        document.addEventListener('invalid', (event) => {
            const field = event.target;
            if (!(field instanceof HTMLElement) || !field.matches('input[required], select[required], textarea[required]')) return;

            field.classList.add('is-invalid');
            let feedback = field.nextElementSibling;
            if (!feedback?.matches('.invalid-feedback')) {
                feedback = document.createElement('div');
                feedback.className = 'invalid-feedback d-block';
                feedback.dataset.requiredFeedback = 'true';
                field.insertAdjacentElement('afterend', feedback);
            }

            if (!feedback.textContent.trim()) {
                const label = field.id ? document.querySelector(`label[for="${CSS.escape(field.id)}"]`) : null;
                const labelText = label?.textContent.replace('*', '').trim().toLowerCase();
                feedback.textContent = field.validity.valueMissing ?
                    `${field.tagName === 'SELECT' ? 'Vui lòng chọn' : 'Vui lòng nhập'} ${labelText || 'thông tin này'}.` :
                    field.validationMessage;
            }
        }, true);

        function clearRequiredFieldError(event) {
            const field = event.target;
            if (!(field instanceof HTMLElement) || !field.matches('input[required], select[required], textarea[required]') || !field.checkValidity()) return;

            field.classList.remove('is-invalid');
            if (field.nextElementSibling?.dataset.requiredFeedback) {
                field.nextElementSibling.remove();
            }
        }

        document.addEventListener('input', clearRequiredFieldError, true);
        document.addEventListener('change', clearRequiredFieldError, true);
    </script>
</body>

</html>