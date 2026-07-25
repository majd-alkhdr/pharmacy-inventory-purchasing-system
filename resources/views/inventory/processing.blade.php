{{-- resources/views/inventory/processing.blade.php --}}
@extends('layouts.admin')

@section('page_title', $title ?? 'جاري المعالجة')
@section('page_subtitle', 'العمليات (المناقلات)')

@section('content')
    <div class="surface-card p-5 text-center shadow-sm" style="max-width: 520px; margin: 0 auto;">
        <div class="spinner-border text-primary mb-3" style="width:3rem;height:3rem;" role="status"></div>
        <h5 class="fw-bold text-dark mb-2">{{ $title }}</h5>
        <p class="text-muted small mb-0" id="statusMessage">
            جاري المعالجة في الخلفية، هذه الصفحة ستنتقل تلقائياً عند الانتهاء. لا تُغلق الصفحة أو تُحدّثها.
        </p>
    </div>
@endsection

@push('scripts')
    <script>
        (function() {
            const statusUrl = "{{ $statusRoute }}";
            const fallbackRedirect = "{{ $redirectOnDone ?? route('inventory.upload.form') }}";

            function poll() {
                fetch(statusUrl)
                    .then(r => r.json())
                    .then(data => {
                        if (data.status === 'processing') {
                            setTimeout(poll, 2500);
                            return;
                        }

                        if (data.status === 'needs_pending_products' || data.status === 'needs_mapping') {
                            window.location.href = data.redirect_url;
                            return;
                        }

                        if (data.status === 'completed') {
                            sessionStorage.setItem('import_flash_message', data.message || 'تمت العملية بنجاح.');
                            sessionStorage.setItem('import_flash_type', 'success');
                            window.location.href = fallbackRedirect;
                            return;
                        }

                        if (data.status === 'failed') {
                            document.getElementById('statusMessage').innerHTML =
                                '<span class="text-danger fw-bold">' + (data.message || 'حدث خطأ غير متوقع.') +
                                '</span>';
                            return;
                        }

                        setTimeout(poll, 2500);
                    })
                    .catch(() => setTimeout(poll, 4000)); // إعادة محاولة عند فشل الشبكة المؤقت
            }

            poll();
        })();
    </script>
@endpush
