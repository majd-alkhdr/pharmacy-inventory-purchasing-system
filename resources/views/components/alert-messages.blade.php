{{--
    🎯 نُقلت من admin.blade.php (كانت 4 كتل @if متتالية مكررة البنية).
    الفائدة الحقيقية: لو غداً أضفت نوع رسالة خامس (مثلاً session('debug'))،
    تضيفه هنا مرة وحدة، بدل ما تدوّر على كل layout بالمشروع يستخدم نفس النمط.
--}}
@php
    $flashTypes = [
        'success' => ['icon' => 'fa-circle-check',       'class' => 'alert-success', 'text' => 'text-success'],
        'warning' => ['icon' => 'fa-triangle-exclamation','class' => 'alert-warning', 'text' => 'text-warning'],
        'error'   => ['icon' => 'fa-circle-xmark',        'class' => 'alert-danger',  'text' => 'text-danger'],
        'info'    => ['icon' => 'fa-circle-info',         'class' => 'alert-info',    'text' => 'text-info'],
    ];
@endphp

@foreach ($flashTypes as $key => $style)
    @if (session($key))
        <div class="alert {{ $style['class'] }} border-0 shadow-sm p-2.5 d-flex align-items-center mb-3">
            <i class="fa-solid {{ $style['icon'] }} me-2 {{ $style['text'] }}"></i>
            <div class="small" style="white-space: pre-line">{{ session($key) }}</div>
        </div>
    @endif
@endforeach
