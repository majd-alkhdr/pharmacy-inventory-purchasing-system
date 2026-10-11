<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('page_title', 'لوحة التحكم') · نظام ترميم الصيدليات</title>

    {{-- 🎯 يجب أن يُنفَّذ هذا قبل أي CSS — يضبط data-theme على <html>
         فوراً من التخزين المحلي، قبل أول رسم للصفحة (first paint).
         لو انتظرنا حتى @stack('scripts') بآخر <body> كما بقية الجافاسكربت،
         كانت الصفحة سترسم بالثيم الافتراضي (الداكن) للحظة ثم "تقفز"
         للفاتح إن كان ذلك تفضيل المستخدم المحفوظ — وميض مزعج (FOUC)
         يظهر بوضوح خاصة على الاتصالات البطيئة. --}}
    <script>
        (function() {
            var saved = localStorage.getItem('app_theme');
            document.documentElement.setAttribute('data-theme', saved === 'light' ? 'light' : 'dark');
        })();
    </script>

    {{-- خط Cairo — يدعم العربية بوزن حديث يناسب الواجهة الداكنة --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    {{-- 🔒 نُبقي Bootstrap + FontAwesome كما هي — عشرات الشاشات خارج نطاق
         هذا التعديل (المشتريات، التقارير، إلخ) لا تزال تعتمد على أصناف
         Bootstrap (d-flex, card, alert-success, mb-3...). حذفها كان
         سيكسر تلك الشاشات بصمت. --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    @stack('styles')

    {{-- 🆕 Tailwind عبر CDN، بادئة tw- تمنع أي تصادم اسمي مع أصناف
         Bootstrap الموجودة (d-flex ضد tw-flex، لا تشابك إطلاقاً)،
         وPreflight معطّل عمداً حتى لا يصفّر التنسيقات الافتراضية
         (جداول، أزرار، قوائم) التي تعتمد عليها كل الشاشات غير المُعاد
         تصميمها بعد.

         🩹 ألوان dt-* هنا تُشير لنفس متغيرات CSS (--c-*) المعرَّفة أدناه،
         وليست قيَماً حرفية ثابتة — لو استُخدمت أي صنف Tailwind مستقبلاً
         (مثل tw-bg-dt-surface) فستتبدَّل تلقائياً مع تبديل [data-theme]
         بلا أي كود إضافي، بنفس منطق باقي الملف بالضبط. --}}
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            prefix: 'tw-',
            important: true,
            corePlugins: {
                preflight: false
            },
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Cairo', 'Tahoma', 'Segoe UI', 'sans-serif']
                    },
                    colors: {
                        'dt-body': 'var(--c-page-bg)',
                        'dt-surface': 'var(--c-surface)',
                        'dt-surface-hover': 'var(--c-surface-hover)',
                        'dt-surface-soft': 'var(--c-dark)',
                        'dt-border': 'var(--c-border)',
                        'dt-accent': 'var(--c-primary)',
                        'dt-accent-dark': 'var(--c-primary-dark)',
                        'dt-accent-soft': 'var(--c-primary-soft)',
                        'dt-text': 'var(--c-text)',
                        'dt-text-dim': 'var(--c-text-muted)',
                        'dt-text-faint': 'var(--c-text-faint)',
                    },
                },
            },
        };
    </script>

    <style>
        /* ============================================================
           🎨 نظام الألوان الموحّد — نفس أسماء المتغيرات السابقة، قيَم
           جديدة داكنة/نحاسية. أي مكوّن قديم (مثل .upload-zone سابقاً)
           يستهلك var(--c-*) يُعاد تلوينه تلقائياً بلا لمس ملفّه.
           ============================================================ */
        :root {
            --c-primary: #FF6B00;
            --c-primary-dark: #E05F00;
            --c-primary-soft: rgba(255, 107, 0, .12);
            --c-dark: #121316;
            --c-dark-soft: #1B1C22;
            --c-secondary: #9497A6;
            --c-bg: #1B1C22;
            --c-surface: #1B1C22;
            --c-surface-hover: #232430;
            --c-elevated: rgba(255, 255, 255, .06);
            --c-border: #2A2B33;
            --c-success: #22c55e;
            --c-danger: #f04438;
            --c-danger-soft: rgba(240, 68, 56, .12);
            --c-warning: #f5a623;
            --c-text: #EDEDEF;
            --c-text-secondary: #C7C9D4;
            --c-text-muted: #A9ACBC;
            --c-text-faint: #82859A;
            --c-page-bg: #08090A;
            --shadow-shell: 0 30px 70px -24px rgba(0, 0, 0, .75);
            --shadow-card: 0 1px 2px rgba(0, 0, 0, .4), 0 8px 24px -10px rgba(0, 0, 0, .55);
            --sidebar-w: 272px;
            --topbar-h: 68px;
            --shell-gap: 18px;
        }

        /* ============================================================
           ☀️ الوضع الفاتح — نفس أسماء المتغيرات بالضبط، قيَم مختلفة فقط.
           بما أن كل قاعدة CSS بالملف تقرأ اللون عبر var(--c-*) وليس قيمة
           حرفية، فتفعيل [data-theme="light"] على <html> يكفي وحده لقلب
           كل الواجهة — بلا الحاجة لكتابة أي قاعدة CSS إضافية مكرَّرة لكل
           عنصر. هذا بالضبط سبب استخدام Design Tokens من البداية بدل ألوان
           متناثرة داخل كل قاعدة.

           🎯 اللون النحاسي (--c-primary) يبقى **كما هو** بلا تغيير بين
           الوضعين عمداً — هوية العلامة يجب أن تبقى مُتعرَّف عليها بصرف
           النظر عن الوضع، تماماً كما شعار أي تطبيق لا "يُلوَّن" بتبديل
           الثيم. المتغيّر الوحيد المرتبط بالنحاسي الذي يتغيّر هو الشفافية
           (--c-primary-soft) فقط، لضبط تباينه فوق خلفية بيضاء بدل داكنة. */
        [data-theme="light"] {
            --c-primary: #FF6B00;
            --c-primary-dark: #E05F00;
            --c-primary-soft: rgba(255, 107, 0, .10);
            --c-dark: #F3F4F7;
            --c-dark-soft: #FFFFFF;
            --c-secondary: #6B6E7B;
            --c-bg: #FFFFFF;
            --c-surface: #FFFFFF;
            --c-surface-hover: #F1F2F5;
            /* 🩹 الأسطح صارت بيضاء، فتراكب أبيض شفاف (كما بالوضع الداكن)
               يصبح غير مرئي إطلاقاً — نحتاج تراكباً داكناً خفيفاً بدلاً. */
            --c-elevated: rgba(18, 19, 22, .05);
            --c-border: #E4E5EA;
            --c-success: #16A34A;
            --c-danger: #DC2626;
            --c-danger-soft: rgba(220, 38, 38, .10);
            --c-warning: #B45309;
            --c-text: #16171B;
            --c-text-secondary: #4B4D58;
            --c-text-muted: #6B6E7B;
            --c-text-faint: #9AA0AE;
            --c-page-bg: #E8E9ED;
            /* 🩹 ظل أخف وأكثر حيادية يناسب خلفية فاتحة — نفس الظل الداكن
               الثقيل كان سيبدو "متسخاً" تحت لوحة بيضاء بدل بروز أنيق. */
            --shadow-shell: 0 24px 60px -20px rgba(15, 23, 42, .14);
            --shadow-card: 0 1px 2px rgba(15, 23, 42, .06), 0 8px 20px -10px rgba(15, 23, 42, .12);
        }

        /* ============================================================
           🎯 ألوان "الهيكل" (Chrome) — الشريط الجانبي فقط. بقرار صريح
           (بناءً على المرجع الأحدث): الشريط الجانبي يبقى **داكناً دائماً**
           بصرف النظر عن الوضع المختار لبقية اللوحة (Canvas)، تماماً كما
           تفعل تطبيقات مثل VS Code وSlack — هوية العلامة والتنقل الرئيسي
           لا "تتبدّل" مع تفضيل القراءة النهاري/الليلي للمحتوى، فيبقى
           الشريط مرجعاً بصرياً ثابتاً يمنح المستخدم إحساساً بالاستقرار
           المكاني بصرف النظر عن حالة القراءة الحالية.

           🎓 لماذا متغيرات منفصلة (--sb-*) بدل إعادة استخدام --c-* نفسها؟
           لأن --c-* بطبيعتها **يجب** أن تتبدّل مع [data-theme] (هذا جوهر
           وظيفتها بكل بقية الملف) — لو استخدمناها بالشريط الجانبي، كان
           الشريط سيتبدّل هو الآخر رغماً عنّا. فصل مجموعة متغيرات ثانية لا
           علاقة لها بـ[data-theme] إطلاقاً هو الطريقة الوحيدة لإعطاء
           عنصر واحد استثناءً من قاعدة عامة، بلا !important ولا مضاعفة
           قواعد ولا أي JS إضافي — مجرد نطاق توكِن (token scope) مختلف. */
        :root {
            --sb-bg: #14161C;
            --sb-surface-hover: #1E212A;
            --sb-border: #262832;
            --sb-text: #F2F2F4;
            --sb-text-secondary: #C7C9D4;
            --sb-text-faint: #868996;
            /* 🎨 تراكب نحاسي دافئ للصف النشط بدل الرمادي المحايد السابق —
               لمسة أكثر "دفئاً" تنسجم مع الطابع النحاسي العام للعلامة،
               مطابقة للمرجع الأحدث الذي زوّدنا به صاحب المشروع. */
            --sb-active-bg: rgba(255, 107, 0, .14);
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            height: 100%;
        }

        /* 🎯 الخلفية شبه السوداء خلف اللوحة — تُبرز اللوحة العائمة كإطار
           مستقل، تماماً كما بالتصميم المرجعي (تصميم لوحة تحكم حديث.jpg) */
        body {
            font-family: 'Cairo', 'Segoe UI', Tahoma, sans-serif;
            background-color: var(--c-page-bg) !important;
            color: var(--c-text);
            padding: var(--shell-gap);
            transition: background-color .2s ease;
        }

        a {
            text-decoration: none;
        }

        /* 🆕 اللوحة العائمة: زوايا دائرية + ظل عميق + خلفية أغمق قليلاً من
           الخلفية العامة — بدل امتداد كامل الشاشة كما كان سابقاً. */
        .app-shell {
            display: flex;
            min-height: calc(100vh - (var(--shell-gap) * 2));
            border-radius: 26px;
            overflow: hidden;
            box-shadow: var(--shadow-shell);
            background-color: var(--c-dark);
            transition: background-color .2s ease, box-shadow .2s ease;
        }

        /* ============ الشريط الجانبي — يستخدم --sb-* الثابتة، لا --c-* المتبدّلة ============ */
        .sidebar {
            width: var(--sidebar-w);
            flex-shrink: 0;
            background-color: var(--sb-bg);
            border-inline-start: 1px solid var(--sb-border);
            color: var(--sb-text-secondary);
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            /* 🩹 لا height هنا عمداً — .app-shell يضبط min-height فقط
               (وليس height ثابتة)، وارتفاع النسبة المئوية (height:100%)
               لا يُحسَب إلا مقابل ارتفاع "محدَّد" صراحة للأب؛ min-height
               وحدها لا تُعتبر "محدَّدة" بهذا المعنى، فكانت height:100%
               تؤول عملياً لـ auto (أي: تنكمش الشريط لحجم محتواه فقط —
               بالضبط الخلل الذي ظهر بالسكرين شوت). الحل الصحيح: الاعتماد
               على align-items:stretch الافتراضي لـ .app-shell (لم نُغيّره
               إطلاقاً)، الذي يمدّ أي عنصر flex ليطابق ارتفاع الحاوية
               الفعلي أياً كان مصدره (min-height أو محتوى أطول من فيوبورت
               واحد) — بلا أي اعتماد على حساب نسبة مئوية إطلاقاً. */
            overflow-y: auto;
            transition: transform .25s ease;
            z-index: 1040;
        }

        .sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: var(--sb-border);
            border-radius: 999px;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: 1.25rem 1.25rem;
            border-bottom: 1px solid var(--sb-border);
        }

        .sidebar-brand .icon-badge {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: var(--c-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1rem;
            flex-shrink: 0;
            box-shadow: 0 4px 14px rgba(255, 107, 0, .35);
        }

        .sidebar-brand .title {
            color: var(--sb-text);
            font-weight: 700;
            font-size: .95rem;
            line-height: 1.25;
        }

        .sidebar-brand .subtitle {
            color: var(--sb-text-faint);
            font-size: .7rem;
        }

        /* 🎯 عنوان مركزي مع خط قصير تحته — يطابق ترويسة "Main Menu"
           بالتصميم المرجعي، بدل النص المحاذي لليسار المسطّح سابقاً. */
        .sidebar-section-label {
            font-size: .78rem;
            font-weight: 700;
            color: var(--sb-text);
            text-align: center;
            padding: 1.35rem 1.25rem .55rem;
            position: relative;
        }

        .sidebar-section-label::after {
            content: '';
            display: block;
            width: 34px;
            height: 2px;
            background: var(--c-primary);
            border-radius: 999px;
            margin: .5rem auto 0;
        }

        .sidebar-group-toggle {
            display: flex;
            align-items: center;
            gap: .65rem;
            width: 100%;
            padding: .6rem 1.25rem;
            font-size: .82rem;
            font-weight: 600;
            color: var(--sb-text-secondary);
            background: transparent;
            border: none;
            cursor: pointer;
            transition: color .15s ease, background-color .15s ease;
        }

        .sidebar-group-toggle:hover {
            color: var(--sb-text);
            background-color: var(--sb-surface-hover);
        }

        .sidebar-group-toggle[aria-expanded="true"] {
            color: var(--c-primary);
        }

        .sidebar-group-toggle .chevron {
            margin-inline-start: auto;
            font-size: .65rem;
            color: var(--sb-text-faint);
            transition: transform .2s ease;
        }

        .sidebar-group-toggle[aria-expanded="true"] .chevron {
            transform: rotate(180deg);
            color: var(--c-primary);
        }

        .sidebar-group-header {
            display: flex;
            align-items: center;
            gap: .65rem;
            padding: .6rem 1.25rem;
            font-size: .82rem;
            font-weight: 600;
            color: var(--sb-text-faint);
        }

        .sidebar-badge-soon {
            font-size: .6rem;
            font-weight: 700;
            background: var(--sb-surface-hover);
            color: var(--sb-text-faint);
            padding: .1rem .5rem;
            border-radius: 999px;
            margin-inline-start: auto;
            border: 1px solid var(--sb-border);
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: .7rem;
            padding: .55rem 1.25rem;
            margin: .1rem .6rem;
            border-radius: 8px;
            color: var(--sb-text-secondary);
            font-size: .83rem;
            font-weight: 500;
            transition: background-color .15s ease, color .15s ease;
        }

        .sidebar-link i {
            width: 18px;
            text-align: center;
            font-size: .78rem;
            color: var(--sb-text-faint);
            flex-shrink: 0;
            transition: color .15s ease;
        }

        .sidebar-link:hover {
            background-color: var(--sb-surface-hover);
            color: var(--sb-text);
        }

        .sidebar-link:hover i {
            color: var(--c-primary);
        }

        /* 🩹 تحديث بناءً على المرجع الأحدث: الصف النشط يحمل تراكباً نحاسياً
           دافئاً (--sb-active-bg) بدل الرمادي المحايد الذي اعتمدناه سابقاً
           بعد المرجع الأول — نفس الخط الجانبي النحاسي (accent bar) باقٍ
           كما هو. راجع تعليق --sb-active-bg بأعلى الملف لتفاصيل السبب. */
        .sidebar-link.active {
            background-color: var(--sb-active-bg);
            color: var(--sb-text);
            font-weight: 700;
            box-shadow: inset 3px 0 0 var(--c-primary);
        }

        .sidebar-link.active i {
            color: var(--c-primary);
        }

        .sidebar-link.disabled {
            opacity: .4;
            cursor: not-allowed;
            pointer-events: none;
        }

        .sidebar-footer {
            margin-top: auto;
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--sb-border);
            display: flex;
            align-items: center;
            gap: .65rem;
        }

        .sidebar-footer .avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: var(--sb-surface-hover);
            border: 1px solid var(--sb-border);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--sb-text-secondary);
            font-size: .85rem;
        }

        .sidebar-footer .name {
            color: var(--sb-text);
            font-size: .78rem;
            font-weight: 600;
        }

        .sidebar-footer .role {
            color: var(--sb-text-faint);
            font-size: .66rem;
        }

        /* ============ المنطقة الرئيسية ============ */
        .main-wrap {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            background-color: var(--c-dark);
        }

        .topbar {
            height: var(--topbar-h);
            background: var(--c-surface);
            border-bottom: 1px solid var(--c-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            position: sticky;
            top: 0;
            z-index: 1030;
        }

        .topbar .page-title {
            font-weight: 700;
            font-size: 1.02rem;
            color: var(--c-text);
        }

        .topbar .page-title small {
            display: block;
            font-weight: 400;
            font-size: .72rem;
            color: var(--c-text-faint);
            margin-top: .1rem;
        }

        .sidebar-toggle-btn {
            display: none;
            border: 1px solid var(--c-border);
            background: var(--c-surface-hover);
            border-radius: 8px;
            width: 38px;
            height: 38px;
            align-items: center;
            justify-content: center;
            color: var(--c-text-muted);
        }

        /* 🆕 شريط بحث تزييني بالـ topbar — بصري فقط حالياً، بلا اتصال
           خلفي فعلي (لا يوجد endpoint بحث عام موحّد بعد). أي ربط فعلي
           لاحقاً (مثلاً بـ searchProducts()/searchPharmacies() الموجودتين
           أصلاً بـ InventoryController) خارج نطاق "إعادة التصميم البصري". */
        .dt-search {
            display: flex;
            align-items: center;
            gap: .6rem;
            width: 320px;
            max-width: 100%;
            padding: .55rem 1rem;
            border-radius: 999px;
            /* 🩹 حدّ نحاسي دائم بدل حدّ محايد يظهر فقط عند التركيز — كلا
               المرجعين اللذين زوّدنا بهما صاحب المشروع أظهرا شريط بحث
               بحدّ نحاسي ثابت، وليس تفاعلياً فقط. */
            border: 1.5px solid var(--c-primary);
            background: transparent;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        .dt-search:focus-within {
            box-shadow: 0 0 0 3px var(--c-primary-soft);
        }

        .dt-search i {
            color: var(--c-primary);
            font-size: .85rem;
        }

        .dt-search input {
            background: transparent;
            border: none;
            outline: none;
            color: var(--c-text);
            font-family: inherit;
            font-size: .82rem;
            width: 100%;
        }

        .dt-search input::placeholder {
            color: var(--c-text-faint);
        }

        .dt-icon-btn {
            position: relative;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 1px solid var(--c-border);
            background: var(--c-surface-hover);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--c-text-muted);
            font-size: .9rem;
            flex-shrink: 0;
            transition: border-color .15s ease, color .15s ease;
        }

        /* 🎨 مبدّل الوضع الداكن/الفاتح تحديداً — حدّ نحاسي مميَّز يفرّقه
           بصرياً عن باقي أزرار الأيقونات المحايدة (الإشعارات مثلاً)،
           مطابقة للمرجع الأحدث. */
        .dt-icon-btn--accent {
            border-color: var(--c-primary);
            color: var(--c-primary);
        }

        .dt-icon-btn .dt-badge-dot {
            position: absolute;
            top: -3px;
            inset-inline-end: -3px;
            min-width: 16px;
            height: 16px;
            padding: 0 3px;
            border-radius: 999px;
            background: var(--c-danger);
            color: #fff;
            font-size: .6rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--c-surface);
        }

        .dt-user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 2px solid var(--c-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--c-surface-hover);
            color: var(--c-text-muted);
            flex-shrink: 0;
        }

        .content-area {
            padding: 1.5rem 1.75rem 3rem;
        }

        .sidebar-backdrop {
            display: none;
        }

        /* ============ عناصر مشتركة تُستهلك بشاشات أخرى غير Bootstrap-only ============ */
        .surface-card {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            border-radius: 14px;
        }

        .table th {
            background-color: var(--c-surface-hover) !important;
            color: var(--c-text) !important;
            font-weight: 600;
            border-color: var(--c-border) !important;
        }

        .table td {
            border-color: var(--c-border) !important;
            color: var(--c-text-muted);
        }

        /* 🆕 إصلاح تباين: .text-dark/.text-muted/.text-secondary من Bootstrap
   تحمل ألوانها الافتراضية (مصمَّمة لخلفية فاتحة) ولا تقرأ متغيّرات
   الثيم إطلاقاً — بخلاف .table th/.table td أعلاه المُعاد تلوينهما
   صراحة. أي استخدام لهذه الأصناف خارج جدول يظهر بتباين شبه معدوم فوق
   خلفية داكنة فعلياً. نفس فلسفة Design Tokens المتَّبعة بالملف بأكمله:
   توجيه هذه الأصناف الثلاثة لمتغيرات الثيم مركزياً هنا فقط، بدل تصحيح
   كل استخدام لها بكل ملف Blade على حدة — هذا هو بالضبط الفرق بين حلّ
   المشكلة مرة واحدة، وحلّها عشرات المرات مستقبلاً بكل شاشة جديدة. */
        .text-dark {
            color: var(--c-text) !important;
        }

        .text-muted {
            color: var(--c-text-muted) !important;
        }

        .text-secondary {
            color: var(--c-text-secondary) !important;
        }

        /* 🆕 [توسيع 2026-09] نفس مشكلة .text-dark/.text-muted/.text-secondary
           أعلاه بالضبط، لكن هذه المرة على جانب الخلفية لا النص: .bg-white/
           .bg-light/.table-light/.alert-light/.border-light من Bootstrap
           تحمل قيماً بيضاء/فاتحة ثابتة (مصمَّمة لواجهة فاتحة فقط) ولا تقرأ
           متغيّرات الثيم إطلاقاً — تماماً بخلاف .surface-card المُعرَّفة
           أدناه والتي تقرأ var(--c-surface) بشكل صحيح من البداية.
           بما أن عشرات الجداول والبطاقات بكل الشاشات تُغلَّف بـ.bg-white
           (كبديل مؤقت عن .surface-card قبل إتمام إعادة التصميم)، وتحمل
           بداخلها نصوصاً بأصناف .text-dark/.text-muted/.text-secondary
           المُعاد توجيهها أعلاه — فحين يتحوَّل النص لأبيض بالوضع الداكن
           (لأن هذه الأصناف مصمَّمة أصلاً لخلفية داكنة) فوق خلفية بيضاء
           ثابتة لا تتفاعل، ينتج بالضبط "نص أبيض فوق خلفية بيضاء" —
           غير مرئي كلياً. نفس التوجيه المركزي هنا يحل كل الحالات دفعة
           واحدة بلا لمس أي ملف Blade على حدة، ونفس المبدأ المتَّبع بهذا
           الملف من البداية. */
        .bg-white {
            background-color: var(--c-surface) !important;
        }

        .bg-light {
            background-color: var(--c-surface-hover) !important;
        }

        .table-light,
        tr.table-light,
        thead.table-light,
        td.table-light,
        th.table-light {
            --bs-table-bg: var(--c-surface-hover);
            --bs-table-color: var(--c-text);
            background-color: var(--c-surface-hover) !important;
            color: var(--c-text) !important;
        }

        .alert-light {
            background-color: var(--c-surface-hover) !important;
            border-color: var(--c-border) !important;
            color: var(--c-text-secondary) !important;
        }

        .border-light,
        .border-light-subtle {
            border-color: var(--c-border) !important;
        }

        /* 🆕 [2026-09] .form-text (نص المساعدة الصغير تحت الحقول) من
           Bootstrap يحمل لوناً رمادياً ثابتاً غير مرتبط بالثيم إطلاقاً
           (بخلاف .text-muted المُعاد توجيهها أعلاه) — على خلفية داكنة
           يظهر شبه معدوم التباين. نفس السلوك الدلالي لـ.text-muted
           تماماً، فيُوجَّه لنفس المتغيّر. */
        .form-text {
            color: var(--c-text-muted) !important;
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
            color: var(--c-primary);
            border: 1px solid rgba(255, 107, 0, .3);
        }

        /* 🩹 [نُقلت هنا 2026-09] كانت مُعرَّفة فقط بـ shortages_blade.php
           واستُخدمت لاحقاً بصفحتين أخريين (reverse-transfers، inventory-turnover)
           بلا نسخ تعريفها معها — كل توجيه Push بأي Blade مُستقل يُحمَّل
           فقط لتلك الصفحة تحديداً، لا لبقية الصفحات. النتيجة: الشارة بتلك
           الصفحتين كانت تسقط لتنسيق Bootstrap الافتراضي لـ.badge المجرَّدة
           (والذي يفرض color:#fff افتراضياً — مصمَّم للاقتران بصنف .bg-*)، لا
           للون الرمادي الفعلي المقصود هنا — نص أبيض فوق خلفية فاتحة، غير مرئي.
           النقل لهذا الملف (المُحمَّل بكل صفحة دون استثناء) يحل المشكلة
           جذرياً: أي صفحة تستخدم class="badge brand-badge" تحصل عليه تلقائياً،
           بلا أي احتمال نسيان نسخه لاحقاً — بدل معالجة كل صفحة على حدة.
           ⚠️ ملاحظة لأي تعديل مستقبلي على هذا التعليق: لا تكتب رمز "@"
           متبوعاً مباشرة باسم توجيه واستدعاء بأقواس داخل أي تعليق بهذا
           الملف — Blade لا يُميّز بين تعليق CSS الحقيقي والنص العادي،
           ويُترجم أي نمط كهذا كتوجيه فعلي حتى لو كان مجرد شرح نصّي (هذا
           بالضبط ما كسر الصفحة بأكملها سابقاً: كتابة اسم توجيه "بوش"
           بصيغته الفعلية بأقواسه هنا فتح توجيهاً حقيقياً بلا نظير مغلق
           له أبداً — أعِد المحاولة بصياغة وصفية بحتة كهذه، لا رمزية). */
        /* 🩹 [مُصحَّحة 2026-09] كانت تستخدم var(--c-secondary)/var(--c-bg) —
             متغيّرات مُصمَّمة أصلاً لتتفاعل مع تبديل الوضع الداكن/الفاتح
             بالقماش الخارجي (الشريط العلوي + الخلفية). لكن هذه الشارة تعيش
             دائماً داخل بطاقة "بيضاء ثابتة" (bg-white) لا تتفاعل مع التبديل
             إطلاقاً — فحين يتحوَّل --c-secondary للون فاتح بالوضع الداكن،
             ينتج تبايناً ضعيفاً جداً فوق الخلفية البيضاء الثابتة. الحل:
             ألوان ثابتة مُختارة خصيصاً للتباين الجيد فوق الأبيض دائماً،
             بصرف النظر عن وضع الشريط العلوي. */
        .brand-badge {
            background-color: #F3F4F6;
            color: #495057;
            border: 1px solid #E5E7EB;
        }

        /* 🆕 مكوّن الشبكة المشتركة لعرض قوائم الأسماء — راجع الفصل 10
           بالتقرير التعليمي للسبب الكامل لوجودها هنا لا بصفحة تقرير
           منفردة. ألوان ثابتة لنفس سبب .brand-badge أعلاه بالضبط — هذا
           المكوّن يعيش دائماً بمحتوى أبيض ثابت. */
        .names-list {
            max-height: 200px;
            overflow-y: auto;
            background-color: #F8F9FA;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            padding: .6rem;
        }

        .names-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .4rem .6rem;
        }

        .name-chip {
            font-size: .76rem;
            color: #1f2937;
            background-color: #fff;
            border: 1px solid #E5E7EB;
            border-radius: 6px;
            padding: .3rem .55rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* 🆕 [نُقلت ووُحِّدت 2026-09] كانت مُكرَّرة حرفياً بثلاث صفحات
           تقارير (قيمة المخزون/المناقلات/النواقص) بنفس الكود بالضبط —
           بما فيه نفس أخطاء الألوان (var(--c-dark)/var(--c-secondary) على
           بطاقات بيضاء ثابتة). توحيد واحد يصحّح الثلاث معاً، لا ثلاث
           تصحيحات منفصلة قد تنحرف عن بعضها لاحقاً. */
        /* 🆕 [2026-09] كانت الأربع كتل التالية (.filter-bar/.report-toggle-btn/
           .kpi-card/.kpi-value/.kpi-label) بألوان بيضاء/داكنة ثابتة — نفس
           فئة مشكلة .bg-white أعلاه لكن بمكوّنات صفحات التقارير تحديداً
           (قيمة المخزون/النواقص/المناقلات). توجيهها لمتغيّرات الثيم
           يجعلها تتبع اللوحة المحيطة بدل بقائها "جزيرة بيضاء" ثابتة وسط
           واجهة داكنة. */
        .filter-bar {
            background-color: var(--c-surface-hover);
            border-radius: 10px;
            border: 1px solid var(--c-border);
        }

        .report-toggle-btn {
            border: 1px solid var(--c-border);
            background: var(--c-surface);
            color: var(--c-text-secondary);
            font-weight: 600;
            font-size: .82rem;
            padding: .5rem 1.1rem;
            border-radius: 8px;
        }

        .report-toggle-btn.active {
            background-color: var(--c-primary);
            border-color: var(--c-primary);
            color: #fff;
        }

        .kpi-card {
            border: 1px solid var(--c-border);
            border-radius: 10px;
            background: var(--c-surface);
            padding: 1rem 1.25rem;
        }

        .kpi-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--c-text);
        }

        .kpi-label {
            font-size: .75rem;
            color: var(--c-text-secondary);
            font-weight: 600;
        }

        .form-control,
        .form-select {
            background-color: var(--c-dark) !important;
            border-color: var(--c-border) !important;
            color: var(--c-text) !important;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--c-primary) !important;
            box-shadow: 0 0 0 .15rem rgba(255, 107, 0, .18) !important;
        }

        @media (max-width: 991.98px) {
            .sidebar {
                position: fixed;
                top: 0;
                /* 🩹 bottom:0 يعطي ارتفاعاً ضمنياً كاملاً للفيوبورت بلا أي
                   اعتماد على height — أسلوب أكثر مقاومة للأخطاء من ربط
                   position:fixed بخاصية height منفصلة قد تتغيّر أو تُحذف
                   بمكان آخر بالملف مستقبلاً (بالضبط كما حصل هنا). */
                bottom: 0;
                right: 0;
                left: auto;
                transform: translateX(100%);
                box-shadow: -8px 0 32px rgba(0, 0, 0, .5);
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
                background: rgba(0, 0, 0, .6);
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
    @php
        // هل يوجد رابط نشط داخل كل قسم؟ (يُستخدم لفتح القائمة تلقائياً عند التواجد داخلها)
        $operationsActive = request()->routeIs(
            'inventory.upload.form',
            'inventory.upload',
            'inventory.stock.upload.*',
            'inventory.invoice*',
            'inventory.shortages.dashboard',
            'inventory.shortages.upload*',
            'inventory.shortages.import',
            'inventory.reverse-transfers.*',
            'inventory.pharmacy-transfers.*',
            'inventory.pharmacy-transfer.*',
            'inventory.pharmacy-minimum-stock.*',
            'inventory.watched-products.*',
            'inventory.excluded-companies.*',
            'inventory.standard-stock.*', // 🆕 (ب.1)
            'inventory.finance-settlements.*', // 🆕 (ب.3)
            'inventory.reverse-transfer.*', // 🆕 (ب.6)
            'import-history.*', // 🆕 (أ.1)
        );
        $purchasingActive = request()->routeIs('purchasing.*');
        $reportsActive = request()->routeIs('inventory.reports.*'); // 🆕
    @endphp

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

            {{-- 🆕 [صلاحيات] كل قسم مُقيَّد بصلاحية المستخدم الفعلية —
                 هذا العرض ليس الحماية الحقيقية (تلك بالميدلوير بـ web.php)،
                 بل اتساق بصري فقط: لا يرى المستخدم رابطاً يؤدي لخطأ 403. --}}
            @if (auth()->user()->hasModuleAccess('transfers_operations'))
                {{-- قسم العمليات (المناقلات) --}}
                <button type="button" class="sidebar-group-toggle" data-bs-toggle="collapse"
                    data-bs-target="#collapseOperations" aria-expanded="{{ $operationsActive ? 'true' : 'false' }}"
                    aria-controls="collapseOperations">
                    <i class="fa-solid fa-arrow-right-arrow-left"></i>
                    <span>العمليات (المناقلات)</span>
                    <i class="fa-solid fa-chevron-down chevron"></i>
                </button>
                <div class="collapse {{ $operationsActive ? 'show' : '' }}" id="collapseOperations">
                    <a href="{{ route('inventory.upload.form') }}"
                        class="sidebar-link {{ request()->routeIs('inventory.upload.form') || request()->routeIs('inventory.upload') ? 'active' : '' }}">
                        <i class="fa-solid fa-cloud-arrow-up"></i> رفع المبيعات والجرد اليومي
                    </a>
                    <a href="{{ route('inventory.stock.upload.form') }}"
                        class="sidebar-link {{ request()->routeIs('inventory.stock.upload.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-warehouse"></i> جرد المستودع المركزي
                    </a>

                    <a href="{{ route('inventory.invoices.index') }}"
                        class="sidebar-link {{ request()->routeIs('inventory.invoice*') ? 'active' : '' }}">
                        <i class="fa-solid fa-file-invoice-dollar"></i> الفواتير الصادرة
                    </a>
                    <a href="{{ route('inventory.shortages.dashboard') }}"
                        class="sidebar-link {{ request()->routeIs('inventory.shortages.dashboard') ? 'active' : '' }}">
                        <i class="fa-solid fa-boxes-stacked"></i> النواقص والعجز
                    </a>
                    <a href="{{ route('inventory.pharmacy-minimum-stock.form') }}"
                        class="sidebar-link {{ request()->routeIs('inventory.pharmacy-minimum-stock.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-gauge-high"></i> المخزون القياسي للصيدليات
                    </a>
                    {{-- 🆕 (ب.1) --}}
                    <a href="{{ route('inventory.standard-stock.lookup') }}"
                        class="sidebar-link {{ request()->routeIs('inventory.standard-stock.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-magnifying-glass-chart"></i> عرض المخزون القياسي
                    </a>
                    <a href="{{ route('inventory.watched-products.form') }}"
                        class="sidebar-link {{ request()->routeIs('inventory.watched-products.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-eye"></i> أصناف للمراقبة
                    </a>
                    <a href="{{ route('inventory.excluded-companies.form') }}"
                        class="sidebar-link {{ request()->routeIs('inventory.excluded-companies.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-ban"></i> شركات مستثناة من الترميم
                    </a>
                    <a href="{{ route('inventory.reverse-transfers.report') }}"
                        class="sidebar-link {{ request()->routeIs('inventory.reverse-transfers.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-rotate-left"></i> المناقلات العكسية
                    </a>
                    <a href="{{ route('inventory.pharmacy-transfers.review') }}"
                        class="sidebar-link {{ request()->routeIs('inventory.pharmacy-transfers.*') || request()->routeIs('inventory.pharmacy-transfer.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-right-left"></i> مناقلات مباشرة بين الصيدليات
                    </a>
                    <a href="{{ route('inventory.shortages.upload.form') }}"
                        class="sidebar-link {{ request()->routeIs('inventory.shortages.upload*') || request()->routeIs('inventory.shortages.import') ? 'active' : '' }}">
                        <i class="fa-solid fa-file-import"></i> استيراد نواقص تاريخية
                    </a>
                    {{-- 🆕 (ب.3) --}}
                    <a href="{{ route('inventory.finance-settlements.index') }}"
                        class="sidebar-link {{ request()->routeIs('inventory.finance-settlements.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-coins"></i> مواد تحتاج تسوية من المالية
                    </a>
                    {{-- 🆕 (أ.1) مشترك بين القسمين — يظهر هنا وبقسم المشتريات --}}
                    <a href="{{ route('import-history.index') }}"
                        class="sidebar-link {{ request()->routeIs('import-history.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-clock-rotate-left"></i> سجل الرفع
                    </a>
                </div>
            @endif

            @if (auth()->user()->hasModuleAccess('purchasing_operations'))
                {{-- قسم المشتريات --}}
                <button type="button" class="sidebar-group-toggle tw-mt-2" data-bs-toggle="collapse"
                    data-bs-target="#collapsePurchasing" aria-expanded="{{ $purchasingActive ? 'true' : 'false' }}"
                    aria-controls="collapsePurchasing">
                    <i class="fa-solid fa-cart-shopping"></i>
                    <span>المشتريات</span>
                    <i class="fa-solid fa-chevron-down chevron"></i>
                </button>
                <div class="collapse {{ $purchasingActive ? 'show' : '' }}" id="collapsePurchasing">
                    <a href="{{ route('purchasing.product-master-data.show') }}"
                        class="sidebar-link {{ request()->routeIs('purchasing.product-master-data.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-pen-to-square"></i> بيانات المادة
                    </a>

                    {{-- 🆕 يستبدل رابط "الحد الأدنى للمخزون" القديم بالكامل --}}
                    <a href="{{ route('purchasing.manual-sales-rate.form') }}"
                        class="sidebar-link {{ request()->routeIs('purchasing.manual-sales-rate.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-chart-line"></i> معدّل مبيعات المستودع
                    </a>

                    <a href="{{ route('purchasing.orders.create') }}"
                        class="sidebar-link {{ request()->routeIs('purchasing.orders.create') || request()->routeIs('purchasing.orders.resolve-conflicts') ? 'active' : '' }}">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> توليد فواتير مقترحة
                    </a>
                    <a href="{{ route('purchasing.orders.index') }}"
                        class="sidebar-link {{ request()->routeIs('purchasing.orders.index') || request()->routeIs('purchasing.orders.show') ? 'active' : '' }}">
                        <i class="fa-solid fa-file-invoice"></i> فواتير الشراء المقترحة
                    </a>

                    <a href="{{ route('purchasing.suppliers.index') }}"
                        class="sidebar-link {{ request()->routeIs('purchasing.suppliers.*') && !request()->routeIs('purchasing.suppliers.companies.bulk-import-form') ? 'active' : '' }}">
                        <i class="fa-solid fa-truck-field"></i> الموردون والشركات المفضّلة
                    </a>
                    {{-- 🆕 --}}
                    <a href="{{ route('purchasing.suppliers.companies.bulk-import-form') }}"
                        class="sidebar-link {{ request()->routeIs('purchasing.suppliers.companies.bulk-import-form') ? 'active' : '' }}">
                        <i class="fa-solid fa-file-excel"></i> استيراد شركات الموردين (ملف)
                    </a>

                    <a href="{{ route('purchasing.receiving.choose') }}"
                        class="sidebar-link {{ request()->routeIs('purchasing.receiving.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-truck-ramp-box"></i> تسجيل استلام فعلي
                    </a>

                    {{-- 🆕 (أ.1) نفس الشاشة المشتركة — لمن لا يملك صلاحية المناقلات --}}
                    @unless (auth()->user()->hasModuleAccess('transfers_operations'))
                        <a href="{{ route('import-history.index') }}"
                            class="sidebar-link {{ request()->routeIs('import-history.*') ? 'active' : '' }}">
                            <i class="fa-solid fa-clock-rotate-left"></i> سجل الرفع
                        </a>
                    @endunless

                    {{-- ⛔ حُذفت: "الأصناف قيد التصفية" (clearance) — مُجمَّدة
             ⛔ حُذفت: "استيراد تاريخي" (historical-import) — مُجمَّد
             روابط budget-settings وcost-variance وcategories لم تكن
             موجودة بالشريط أصلاً، لا حاجة لحذفها. --}}
                </div>
            @endif

            @if (auth()->user()->hasModuleAccess('transfers_reports') || auth()->user()->hasModuleAccess('purchasing_reports'))
                {{-- 🆕 قسم التقارير — أصبح فعلياً، بنفس نمط "العمليات"/"المشتريات" --}}
                <button type="button" class="sidebar-group-toggle tw-mt-2" data-bs-toggle="collapse"
                    data-bs-target="#collapseReports" aria-expanded="{{ $reportsActive ? 'true' : 'false' }}"
                    aria-controls="collapseReports">
                    <i class="fa-solid fa-chart-simple"></i>
                    <span>تقارير المناقلات (العمليات)</span>
                    <i class="fa-solid fa-chevron-down chevron"></i>
                </button>
                <div class="collapse {{ $reportsActive ? 'show' : '' }}" id="collapseReports">
                    @if (auth()->user()->hasModuleAccess('transfers_reports'))
                        <a href="{{ route('inventory.reports.inventory-value') }}"
                            class="sidebar-link {{ request()->routeIs('inventory.reports.inventory-value') ? 'active' : '' }}">
                            <i class="fa-solid fa-warehouse"></i> تقرير قيمة المخزون
                        </a>
                        <a href="{{ route('inventory.reports.transfers-value') }}"
                            class="sidebar-link {{ request()->routeIs('inventory.reports.transfers-value') ? 'active' : '' }}">
                            <i class="fa-solid fa-arrow-right-arrow-left"></i> تقرير قيمة المناقلات
                        </a>
                        <a href="{{ route('inventory.reports.shortage-value') }}"
                            class="sidebar-link {{ request()->routeIs('inventory.reports.shortage-value') ? 'active' : '' }}">
                            <i class="fa-solid fa-triangle-exclamation"></i> تقرير قيمة النواقص
                        </a>
                        <a href="{{ route('inventory.reports.inventory-turnover') }}"
                            class="sidebar-link {{ request()->routeIs('inventory.reports.inventory-turnover') ? 'active' : '' }}">
                            <i class="fa-solid fa-rotate"></i> تقرير دوران المخزون
                        </a>
                        <a href="{{ route('inventory.reports.product-movement') }}"
                            class="sidebar-link {{ request()->routeIs('inventory.reports.product-movement') ? 'active' : '' }}">
                            <i class="fa-solid fa-timeline"></i> مراقبة حركة مواد محددة
                        </a>
                    @endif
                    {{-- 🆕 [ملاحظة لإضافة تقارير المشتريات لاحقاً] أضف هنا
                         @if (auth()->user()->hasModuleAccess('purchasing_reports')) مطابق تماماً
                         للأعلى، بروابط تقارير المشتريات حين تُبنى — لا تخلطها
                         بلا فحص صلاحية منفصل، وإلا سيراها مستخدم "مناقلات" أيضاً. --}}
                </div>
            @endif

            @if (auth()->user()->hasModuleAccess('purchasing_reports'))
                <button type="button" class="sidebar-group-toggle tw-mt-2" data-bs-toggle="collapse"
                    data-bs-target="#collapsePurchasingReports" aria-expanded="false"
                    aria-controls="collapsePurchasingReports">
                    <i class="fa-solid fa-chart-pie"></i>
                    <span>تقارير المشتريات</span>
                    <i class="fa-solid fa-chevron-down chevron"></i>
                </button>
                <div class="collapse {{ request()->routeIs('purchasing.reports.*') ? 'show' : '' }}"
                    id="collapsePurchasingReports">
                    <a href="{{ route('purchasing.reports.purchase-value') }}"
                        class="sidebar-link {{ request()->routeIs('purchasing.reports.purchase-value') ? 'active' : '' }}">
                        <i class="fa-solid fa-sack-dollar"></i> قيمة المشتريات
                    </a>
                    <a href="{{ route('purchasing.reports.purchase-shortage-value') }}"
                        class="sidebar-link {{ request()->routeIs('purchasing.reports.purchase-shortage-value') ? 'active' : '' }}">
                        <i class="fa-solid fa-triangle-exclamation"></i> نواقص المشتريات
                    </a>
                    <a href="{{ route('purchasing.reports.supplier-summary') }}"
                        class="sidebar-link {{ request()->routeIs('purchasing.reports.supplier-summary') ? 'active' : '' }}">
                        <i class="fa-solid fa-truck-field"></i> تقرير الموردين
                    </a>
                    <a href="{{ route('purchasing.reports.cost-variance') }}"
                        class="sidebar-link {{ request()->routeIs('purchasing.reports.cost-variance') ? 'active' : '' }}">
                        <i class="fa-solid fa-scale-unbalanced"></i> تذبذب الأسعار
                    </a>
                </div>
            @endif

            @if (auth()->user()->isAdmin())
                {{-- 🆕 إدارة المستخدمين — مدير النظام فقط --}}
                <a href="{{ route('admin.users.index') }}"
                    class="sidebar-link tw-mt-2 {{ request()->routeIs('admin.users.*') ? 'active' : '' }}"
                    style="font-weight:600;">
                    <i class="fa-solid fa-user-gear"></i> إدارة المستخدمين
                </a>
            @endif

            <div class="sidebar-footer">
                <div class="avatar"><i class="fa-solid fa-user"></i></div>
                <div class="flex-grow-1">
                    <div class="name">{{ auth()->user()->name }}</div>
                    <div class="role">{{ auth()->user()->role->name ?? 'بلا دور' }}</div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-sm p-0 border-0 bg-transparent"
                        style="color: var(--sb-text-faint);" title="تسجيل الخروج">
                        <i class="fa-solid fa-right-from-bracket"></i>
                    </button>
                </form>
            </div>
        </aside>

        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        {{-- ============ المنطقة الرئيسية ============ --}}
        <div class="main-wrap">
            <header class="topbar">
                <div class="tw-flex tw-items-center tw-gap-3">
                    <button type="button" class="sidebar-toggle-btn" id="sidebarToggleBtn">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <div class="page-title">
                        @yield('page_title', 'لوحة التحكم')
                        <small>@yield('page_subtitle', '')</small>
                    </div>
                </div>

                <div class="tw-hidden lg:tw-flex tw-items-center tw-gap-4">
                    {{-- 🎨 بصري فقط حالياً — راجع التعليق أعلى .dt-search --}}
                    <label class="dt-search">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" placeholder="ابحث هنا..." disabled>
                    </label>
                </div>

                <div class="tw-flex tw-items-center tw-gap-3">
                    <div class="tw-flex tw-gap-2">@yield('topbar_actions')</div>

                    {{-- 🆕 مبدّل الوضع الداكن/الفاتح — الأيقونة تمثّل الوضع
                         الذي سينتقل إليه الضغط، لا الوضع الحالي (اتفاقية
                         شائعة: شمس بالوضع الداكن = "اضغط للفاتح"). --}}
                    <button type="button" class="dt-icon-btn dt-icon-btn--accent" id="themeToggleBtn"
                        title="تبديل الوضع الداكن/الفاتح">
                        <i class="fa-solid fa-sun" id="themeToggleIcon"></i>
                    </button>

                    {{-- 🎨 بصري فقط أيضاً — لا يوجد نموذج إشعارات بالتطبيق بعد --}}
                    <button type="button" class="dt-icon-btn" title="الإشعارات (قيد التطوير)" disabled>
                        <i class="fa-solid fa-bell"></i>
                    </button>
                    <div class="dt-user-avatar">
                        <i class="fa-solid fa-user"></i>
                    </div>
                </div>
            </header>

            <main class="content-area">
                <x-alert-messages />
                <script>
                    const flashMsg = sessionStorage.getItem('import_flash_message');
                    if (flashMsg) {
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

    <script>
        // 🆕 مبدّل الوضع الداكن/الفاتح — الحالة الأولية سبق ضبطها بالفعل
        // بالسكربت المبكّر أعلى <head> (لمنع الوميض)، هذا السكربت فقط
        // يربط زر الضغط + يحفظ الاختيار + يحدّث أيقونة الزر لتعكس الحالة.
        (function() {
            const STORAGE_KEY = 'app_theme';
            const root = document.documentElement;
            const toggleBtn = document.getElementById('themeToggleBtn');
            const toggleIcon = document.getElementById('themeToggleIcon');

            function applyIcon(theme) {
                if (!toggleIcon) return;
                // الأيقونة تمثّل الوضع الذي سيؤدي إليه الضغط، لا الحالي
                toggleIcon.className = theme === 'light' ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
            }

            applyIcon(root.getAttribute('data-theme') || 'dark');

            toggleBtn?.addEventListener('click', () => {
                const current = root.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
                const next = current === 'light' ? 'dark' : 'light';
                root.setAttribute('data-theme', next);
                localStorage.setItem(STORAGE_KEY, next);
                applyIcon(next);
            });
        })();
    </script>
    @stack('scripts')

    <div id="globalLoadingOverlay" class="tw-hidden">
        <div class="loading-box">
            <div class="spinner-border tw-text-[var(--c-primary)] mb-3" role="status"></div>
            <h6 class="tw-text-white tw-font-bold tw-mb-1">جاري المعالجة، الرجاء الانتظار...</h6>
            <p class="tw-text-white/60 tw-text-sm tw-mb-0" id="loadingMessage">
                قد تستغرق العملية حتى دقيقتين حسب حجم الملف، لا تُغلق الصفحة أو تُحدّثها.
            </p>
        </div>
    </div>

    <style>
        #globalLoadingOverlay:not(.tw-hidden) {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .82);
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
            overlay.classList.remove('tw-hidden');
        }

        document.addEventListener('submit', function(e) {
            const form = e.target;
            if (form.dataset.loadingMessage !== undefined) {
                setTimeout(() => showGlobalLoading(form.dataset.loadingMessage), 10);
            }
        });
    </script>
</body>

</html>
