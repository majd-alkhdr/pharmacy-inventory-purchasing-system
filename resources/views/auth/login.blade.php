<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول · نظام ترميم الصيدليات</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body {
            font-family: 'Cairo', 'Segoe UI', Tahoma, sans-serif;
            background-color: #08090A;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .login-card {
            width: 100%;
            max-width: 400px;
            background-color: #1B1C22;
            border: 1px solid #2A2B33;
            border-radius: 18px;
            box-shadow: 0 30px 70px -24px rgba(0, 0, 0, .75);
            padding: 2.25rem 2rem;
        }

        .login-icon {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            background: #FF6B00;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.3rem;
            margin: 0 auto 1rem;
            box-shadow: 0 4px 14px rgba(255, 107, 0, .35);
        }

        .login-title {
            color: #EDEDEF;
            font-weight: 700;
            text-align: center;
            font-size: 1.1rem;
            margin-bottom: .25rem;
        }

        .login-subtitle {
            color: #82859A;
            text-align: center;
            font-size: .8rem;
            margin-bottom: 1.75rem;
        }

        .form-label {
            color: #C7C9D4;
            font-size: .82rem;
            font-weight: 600;
        }

        .form-control {
            background-color: #121316 !important;
            border-color: #2A2B33 !important;
            color: #EDEDEF !important;
        }

        .form-control:focus {
            border-color: #FF6B00 !important;
            box-shadow: 0 0 0 .15rem rgba(255, 107, 0, .18) !important;
        }

        .btn-login {
            background-color: #FF6B00;
            border-color: #FF6B00;
            color: #fff;
            font-weight: 600;
        }

        .btn-login:hover {
            background-color: #E05F00;
            border-color: #E05F00;
            color: #fff;
        }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="login-icon"><i class="fa-solid fa-capsules"></i></div>
        <div class="login-title">نظام ترميم الصيدليات</div>
        <div class="login-subtitle">تسجيل الدخول للوحة التحكم</div>

        @if ($errors->any())
            <div class="alert alert-danger small py-2 mb-3">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">البريد الإلكتروني</label>
                <input type="email" name="email" class="form-control" value="{{ old('email') }}" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label">كلمة المرور</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="form-check mb-3">
                <input type="checkbox" name="remember" class="form-check-input" id="remember">
                <label class="form-check-label small" for="remember" style="color:#82859A">تذكرني</label>
            </div>
            <button type="submit" class="btn btn-login w-100">
                <i class="fa-solid fa-right-to-bracket me-1"></i> تسجيل الدخول
            </button>
        </form>
    </div>

</body>
</html>
