{{-- resources/views/errors/403.blade.php --}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لا تملك صلاحية · نظام ترميم الصيدليات</title>

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

        .block-card {
            width: 100%;
            max-width: 420px;
            background-color: #1B1C22;
            border: 1px solid #2A2B33;
            border-radius: 18px;
            box-shadow: 0 30px 70px -24px rgba(0, 0, 0, .75);
            padding: 2.25rem 2rem;
            text-align: center;
        }

        .block-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            background: rgba(240, 68, 56, .12);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #f04438;
            font-size: 1.4rem;
            margin: 0 auto 1.1rem;
        }

        .block-title {
            color: #EDEDEF;
            font-weight: 700;
            font-size: 1.15rem;
            margin-bottom: .5rem;
        }

        .block-message {
            color: #A9ACBC;
            font-size: .88rem;
            margin-bottom: 1.75rem;
            line-height: 1.7;
        }

        .btn-primary-brand {
            background-color: #FF6B00;
            border-color: #FF6B00;
            color: #fff;
            font-weight: 600;
        }

        .btn-primary-brand:hover {
            background-color: #E05F00;
            border-color: #E05F00;
            color: #fff;
        }

        .btn-outline-light-custom {
            background: transparent;
            border: 1px solid #2A2B33;
            color: #82859A;
            font-weight: 500;
        }

        .btn-outline-light-custom:hover {
            background: #232430;
            border-color: #2A2B33;
            color: #EDEDEF;
        }
    </style>
</head>

<body>

    <div class="block-card">
        <div class="block-icon"><i class="fa-solid fa-lock"></i></div>
        <div class="block-title">ليست لديك صلاحية الوصول لهذا القسم</div>
        <div class="block-message">
            {{ $exception->getMessage() ?: 'الصفحة التي حاولت فتحها تخص قسماً آخر لا تملك صلاحية الوصول إليه بحسابك الحالي.' }}
        </div>

        @auth
            <a href="{{ \App\Http\Controllers\AuthController::defaultRedirectFor(auth()->user()) }}"
                class="btn btn-primary-brand w-100 py-2 mb-2">
                <i class="fa-solid fa-house me-1"></i> العودة إلى القسم الخاص بي
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-outline-light-custom w-100 py-2">
                    <i class="fa-solid fa-right-from-bracket me-1"></i> تسجيل الخروج
                </button>
            </form>
        @else
            <a href="{{ route('login') }}" class="btn btn-primary-brand w-100 py-2">
                <i class="fa-solid fa-right-to-bracket me-1"></i> تسجيل الدخول
            </a>
        @endauth
    </div>

</body>

</html>
