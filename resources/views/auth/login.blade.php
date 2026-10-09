<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelector('meta[name="viewport"]')?.setAttribute(
                'content',
                'width=device-width, initial-scale=1.0',
            );
        }, { once: true });
    </script>
    <title>Đăng nhập - PCGD-XMC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    @include('partials.password-toggle')
    <style>
        html,
        body {
            min-width: 320px;
            min-height: 100%;
        }

        .login-main {
            display: grid;
            min-height: 100vh;
            min-height: 100dvh;
            align-items: center;
            padding-top: clamp(1.25rem, 6vh, 4rem);
            padding-bottom: clamp(1.25rem, 6vh, 4rem);
        }

        .login-panel {
            padding: clamp(1.25rem, 5vw, 3rem) !important;
        }

        .login-notice {
            padding: .8rem .9rem;
            border: 1px solid #fecaca;
            border-left: 4px solid #b91c1c;
            border-radius: .65rem;
            background: #fef2f2;
            color: #7f1d1d;
        }

        .login-notice-title {
            margin: 0 0 .35rem;
            color: #991b1b;
            font-weight: 700;
        }

        @media (max-width: 575.98px) {
            .login-main {
                padding-right: .75rem;
                padding-left: .75rem;
            }

            .login-panel .form-control,
            .login-panel .btn {
                min-height: 2.75rem;
                font-size: 1rem;
            }
        }
    </style>
</head>

<body class="bg-light">
    <main class="container login-main">
        <div class="row justify-content-center">
            <div class="col-md-7 col-lg-5">
                <section class="login-panel bg-white border rounded shadow-sm p-4 p-md-5">
                    <h1 class="h4 text-primary mb-1">Đăng nhập hệ thống</h1>
                    <p class="text-muted mb-4">Phổ cập giáo dục - Xóa mù chữ</p>

                    @if ($errors->any())
                        <div class="login-notice mb-3" role="alert" aria-live="assertive">
                            <p class="login-notice-title">Không thể đăng nhập</p>
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('login.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="username" class="form-label">Tên đăng nhập</label>
                            <input id="username" class="form-control" type="text" name="username" value="{{ old('username') }}" required autocomplete="username">
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Mật khẩu</label>
                            <input id="password" class="form-control" type="password" name="password" required autocomplete="current-password">
                        </div>
                        <div class="form-check mb-3">
                            <input id="remember" class="form-check-input" type="checkbox" name="remember" value="1">
                            <label class="form-check-label" for="remember">Ghi nhớ đăng nhập</label>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Đăng nhập</button>
                    </form>
                </section>
            </div>
        </div>
    </main>
</body>

</html>
