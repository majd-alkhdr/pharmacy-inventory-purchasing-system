<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('page_title', 'لوحة التحكم') · نظام ترميم الصيدليات</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    @stack('styles')

    <style>
        /* ============================================================
           🎨 نظام الألوان والتنسيق الموحّد لكل واجهات لوحة التحكم
           أي تعديل هنا ينعكس تلقائياً على كل الشاشات دون الحاجة لتكرار
           الأنماط بكل ملف Blade على حدة.
           ============================================================ */
        :root {
            --c-primary: #0284c7;
            --c-primary-dark: #0369a1;
            --c-primary-soft: #e0f2fe;
            --c-dark: #0f172a;
            --c-dark-soft: #1e293b;
            --c-secondary: #475569;
            --c-bg: #f8fafc;
            --c-border: #e2e8f0;
            --c-success: #16a34a;
            --c-danger: #ef4444;
            --c-warning: #f59e0b;
            --sidebar-w: 268px;
            --topbar-h: 60px;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            height: 100%;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--c-bg) !important;
            color: #1e293b;
        }

        a {
            text-decoration: none;
        }

        /* ---------- الهيكل العام ---------- */
        .app-shell {
            display: flex;
            min-height: 100vh;
        }

        /* ---------- الشريط الجانبي ---------- */
        .sidebar {
            width: var(--sidebar-w);
            flex-shrink: 0;
            background-color: var(--c-dark);
            color: #cbd5e1;
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            transition: transform .25s ease;
            z-index: 1040;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: 1.1rem 1.25rem;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
        }

        .sidebar-brand .icon-badge {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            background: var(--c-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: .95rem;
            flex-shrink: 0;
        }

        .sidebar-brand .title {
            color: #fff;
            font-weight: 700;
            font-size: .95rem;
            line-height: 1.2;
        }

        .sidebar-brand .subtitle {
            color: #64748b;
            font-size: .7rem;
        }

        .sidebar-section-label {
            font-size: .7rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 1rem 1.25rem .35rem;
        }

        .sidebar-group-header {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .55rem 1.25rem;
            font-size: .82rem;
            font-weight: 600;
            color: #94a3b8;
        }

        .sidebar-badge-soon {
            font-size: .62rem;
            font-weight: 700;
            background: rgba(255, 255, 255, .08);
            color: #94a3b8;
            padding: .1rem .45rem;
            border-radius: 999px;
            margin-inline-start: auto;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: .65rem;
            padding: .55rem 1.25rem .55rem 1rem;
            margin: .05rem .6rem;
            border-radius: 8px;
            color: #cbd5e1;
            font-size: .84rem;
            font-weight: 500;
            transition: background-color .15s ease, color .15s ease;
        }

        .sidebar-link i {
            width: 18px;
            text-align: center;
            font-size: .8rem;
            color: #64748b;
            flex-shrink: 0;
        }

        .sidebar-link:hover {
            background-color: rgba(255, 255, 255, .06);
            color: #fff;
        }

        .sidebar-link.active {
            background-color: var(--c-primary);
            color: #fff;
        }

        .sidebar-link.active i {
            color: #fff;
        }

        .sidebar-link.disabled {
            opacity: .45;
            cursor: not-allowed;
            pointer-events: none;
        }

        .sidebar-footer {
            margin-top: auto;
            padding: .9rem 1.25rem;
            border-top: 1px solid rgba(255, 255, 255, .08);
            display: flex;
            align-items: center;
            gap: .6rem;
        }

        .sidebar-footer .avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--c-dark-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-size: .85rem;
        }

        .sidebar-footer .name {
            color: #e2e8f0;
            font-size: .78rem;
            font-weight: 600;
        }

        .sidebar-footer .role {
            color: #64748b;
            font-size: .68rem;
        }

        /* ---------- المنطقة الرئيسية ---------- */
        .main-wrap {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            height: var(--topbar-h);
            background: #fff;
            border-bottom: 1px solid var(--c-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.25rem;
            position: sticky;
            top: 0;
            z-index: 1030;
        }

        .topbar .page-title {
            font-weight: 700;
            font-size: 1rem;
            color: #1e293b;
        }

        .topbar .page-title small {
            display: block;
            font-weight: 400;
            font-size: .72rem;
            color: #94a3b8;
        }

        .sidebar-toggle-btn {
            display: none;
            border: 1px solid var(--c-border);
            background: #fff;
            border-radius: 8px;
            width: 36px;
            height: 36px;
            align-items: center;
            justify-content: center;
            color: var(--c-secondary);
        }

        .content-area {
            padding: 1.25rem 1.5rem 2.5rem;
        }

        .sidebar-backdrop {
            display: none;
        }

        /* ---------- عناصر مشتركة يُعاد استخدامها بكل الصفحات ---------- */
        .surface-card {
            background: #fff;
            border: 1px solid var(--c-border);
            border-radius: 12px;
        }

        .table th {
            background-color: var(--c-dark-soft) !important;
            color: #fff;
            font-weight: 600;
        }

        .btn-brand {
            background-color: var(--c-primary);
            border-color: var(--c-primary);
            color: #fff;
        }

        .btn-brand:hover {
            background-color: var(--c-primary-dark);
            border-color: var(--c-primary-dark);
            color: #fff;
        }

        .badge-soft-primary {
            background-color: var(--c-primary-soft);
            color: #0369a1;
            border: 1px solid #bae6fd;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--c-primary);
            box-shadow: 0 0 0 .15rem rgba(2, 132, 199, .15);
        }

        /* ---------- الجوّال ---------- */
        @media (max-width: 991.98px) {
            .sidebar {
                position: fixed;
                top: 0;
                right: 0;
                left: auto;
                transform: translateX(100%);
                box-shadow: -8px 0 24px rgba(0, 0, 0, .15);
            }

            .sidebar.is-open {
                transform: translateX(0);
            }

            .sidebar-toggle-btn {
                display: inline-flex;
            }

            .sidebar-backdrop.is-open {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, .45);
                z-index: 1035;
            }
        }

        @media print {

            .sidebar,
            .topbar,
            .no-print {
                display: none !important;
            }

            .content-area {
                padding: 0 !important;
            }
        }
    </style>
</head>

<body>
    <div class="app-shell">

        {{-- ============ الشريط الجانبي ============ --}}
        <aside class="sidebar" id="appSidebar">
            <div class="sidebar-brand">
                <div class="icon-badge"><i class="fa-solid fa-capsules"></i></div>
                <div>
                    <div class="title">نظام ترميم الصيدليات</div>
                    <div class="subtitle">لوحة التحكم الإدارية</div>
                </div>
            </div>

            <div class="sidebar-section-label">القوائم الرئيسية</div>

            {{-- قسم العمليات (المناقلات) --}}
            <div class="sidebar-group-header">
                <i class="fa-solid fa-arrow-right-arrow-left"></i>
                <span>العمليات (المناقلات)</span>
            </div>
            <a href="{{ route('inventory.upload.form') }}"
                class="sidebar-link {{ request()->routeIs('inventory.upload.form') || request()->routeIs('inventory.upload') ? 'active' : '' }}">
                <i class="fa-solid fa-cloud-arrow-up"></i> رفع المبيعات والجرد اليومي
            </a>
            {{--             <a href="{{ route('inventory.stock.upload.form') }}"
                class="sidebar-link {{ request()->routeIs('inventory.stock.upload*') ? 'active' : '' }}">
                <i class="fa-solid fa-warehouse"></i> جرد المستودع المركزي
            </a> --}}

            <a href="{{ route('inventory.invoices.index') }}"
                class="sidebar-link {{ request()->routeIs('inventory.invoice*') ? 'active' : '' }}">
                <i class="fa-solid fa-file-invoice-dollar"></i> الفواتير الصادرة
            </a>
            <a href="{{ route('inventory.shortages.dashboard') }}"
                class="sidebar-link {{ request()->routeIs('inventory.shortages.dashboard') ? 'active' : '' }}">
                <i class="fa-solid fa-boxes-stacked"></i> النواقص والعجز
            </a>
            <a href="{{ route('inventory.shortages.upload.form') }}"
                class="sidebar-link {{ request()->routeIs('inventory.shortages.upload*') || request()->routeIs('inventory.shortages.import') ? 'active' : '' }}">
                <i class="fa-solid fa-file-import"></i> استيراد نواقص تاريخية
            </a>

{{-- قسم المشتريات --}}
<div class="sidebar-group-header mt-2">
    <i class="fa-solid fa-cart-shopping"></i>
    <span>المشتريات</span>
</div>
<a href="{{ route('purchasing.orders.create') }}"
    class="sidebar-link {{ request()->routeIs('purchasing.orders.create') ? 'active' : '' }}">
    <i class="fa-solid fa-wand-magic-sparkles"></i> توليد فواتير مقترحة
</a>
<a href="{{ route('purchasing.orders.index') }}"
    class="sidebar-link {{ request()->routeIs('purchasing.orders.index') || request()->routeIs('purchasing.orders.show') ? 'active' : '' }}">
    <i class="fa-solid fa-file-invoice"></i> فواتير الشراء المقترحة
</a>
<a href="{{ route('purchasing.suppliers.index') }}"
    class="sidebar-link {{ request()->routeIs('purchasing.suppliers.*') ? 'active' : '' }}">
    <i class="fa-solid fa-truck-field"></i> الموردون والأسعار
</a>
<a href="{{ route('purchasing.receiving.form') }}"
    class="sidebar-link {{ request()->routeIs('purchasing.receiving.*') ? 'active' : '' }}">
    <i class="fa-solid fa-truck-ramp-box"></i> تسجيل استلام فعلي
</a>
<a href="{{ route('purchasing.cost-variance.index') }}"
    class="sidebar-link {{ request()->routeIs('purchasing.cost-variance.*') ? 'active' : '' }}">
    <i class="fa-solid fa-chart-line"></i> مراقب تذبذب التكلفة
</a>
<a href="{{ route('purchasing.historical-import.form') }}"
    class="sidebar-link {{ request()->routeIs('purchasing.historical-import.*') ? 'active' : '' }}">
    <i class="fa-solid fa-clock-rotate-left"></i> استيراد تاريخي
</a>
<a href="{{ route('purchasing.budget-settings.index') }}"
    class="sidebar-link {{ request()->routeIs('purchasing.budget-settings.*') ? 'active' : '' }}">
    <i class="fa-solid fa-sliders"></i> إعدادات الميزانية
</a>


            {{-- قسم التقارير — قيد التطوير --}}
            <div class="sidebar-group-header mt-2">
                <i class="fa-solid fa-chart-simple"></i>
                <span>التقارير</span>
                <span class="sidebar-badge-soon">قريباً</span>
            </div>
            <span class="sidebar-link disabled">
                <i class="fa-solid fa-chart-line"></i> تقارير الأداء
            </span>

            <div class="sidebar-footer">
                <div class="avatar"><i class="fa-solid fa-user"></i></div>
                <div>
                    <div class="name">أدمن النظام</div>
                    <div class="role">تسجيل الدخول والصلاحيات قيد التطوير</div>
                </div>
            </div>
        </aside>

        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        {{-- ============ المنطقة الرئيسية ============ --}}
        <div class="main-wrap">
            <header class="topbar">
                <div class="d-flex align-items-center gap-2.5">
                    <button type="button" class="sidebar-toggle-btn" id="sidebarToggleBtn">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <div class="page-title">
                        @yield('page_title', 'لوحة التحكم')
                        <small>@yield('page_subtitle', '')</small>
                    </div>
                </div>
                @hasSection('topbar_actions')
                    <div class="d-flex gap-2">@yield('topbar_actions')</div>
                @endif
            </header>

            <main class="content-area">
                {{-- ✅ تنبيهات موحّدة لكل الصفحات بدل تكرارها بكل ملف --}}
                <x-alert-messages />
                <script>
                    const flashMsg = sessionStorage.getItem('import_flash_message');
                    if (flashMsg) {
                        // بإمكانك حقنها بنفس تصميم alert الموجود بدل alert() الافتراضي
                        alert(flashMsg);
                        sessionStorage.removeItem('import_flash_message');
                        sessionStorage.removeItem('import_flash_type');
                    }
                </script>

                @yield('content')
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // فتح/إغلاق الشريط الجانبي بوضع الجوّال
        const sidebarEl = document.getElementById('appSidebar');
        const backdropEl = document.getElementById('sidebarBackdrop');
        document.getElementById('sidebarToggleBtn')?.addEventListener('click', () => {
            sidebarEl.classList.toggle('is-open');
            backdropEl.classList.toggle('is-open');
        });
        backdropEl?.addEventListener('click', () => {
            sidebarEl.classList.remove('is-open');
            backdropEl.classList.remove('is-open');
        });
    </script>
    @stack('scripts')
    {{-- شاشة الانتظار الموحدة --}}
    <div id="globalLoadingOverlay" class="d-none">
        <div class="loading-box">
            <div class="spinner-border text-light mb-3" role="status"></div>
            <h6 class="text-white fw-bold mb-1">جاري المعالجة، الرجاء الانتظار...</h6>
            <p class="text-white-50 small mb-0" id="loadingMessage">
                قد تستغرق العملية حتى دقيقتين حسب حجم الملف، لا تُغلق الصفحة أو تُحدّثها.
            </p>
        </div>
    </div>

    <style>
        #globalLoadingOverlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .85);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #globalLoadingOverlay .loading-box {
            text-align: center;
            max-width: 380px;
            padding: 24px;
        }
    </style>

    <script>
        function showGlobalLoading(message) {
            const overlay = document.getElementById('globalLoadingOverlay');
            if (message) {
                document.getElementById('loadingMessage').textContent = message;
            }
            overlay.classList.remove('d-none');
        }

        // أي فورم عليه data-loading-message بيفعّل الشاشة تلقائياً عند الإرسال
        document.addEventListener('submit', function(e) {
            const form = e.target;
            if (form.dataset.loadingMessage !== undefined) {
                // نأخر خطوة عشان نضمن إن الفورم submit فعلياً قبل ما نمنع أي تفاعل
                setTimeout(() => showGlobalLoading(form.dataset.loadingMessage), 10);
            }
        });
    </script>
</body>

</html>
