{{-- resources/views/purchasing/supplier-companies-bulk-import.blade.php --}}
@extends('layouts.admin')

@section('page_title', 'استيراد شركات الموردين')
@section('page_subtitle', 'المشتريات — ربط جماعي مورد↔شركات مفضَّلة')

@section('content')
    <div class="surface-card p-4 shadow-sm" style="max-width: 760px; margin: 0 auto;">

        <div class="mb-4">
            <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-file-excel text-primary me-1"></i> رفع ملف ربط الموردين
                بالشركات المفضَّلة</h6>
            <p class="text-muted small mb-0">
                كل رفعة لهذا الملف **تستبدل بالكامل** قائمة الشركات المفضَّلة المحفوظة لأي مورد مذكور بالملف — موردون غير
                مذكورين بالملف لا تتأثر قوائمهم إطلاقاً. مورد غير موجود بالنظام يُنشَأ تلقائياً.
            </p>
        </div>

        <div class="mb-4">
            <h6 class="fw-bold text-dark mb-2 small"><i class="fa-solid fa-table-list text-secondary me-1"></i> التنسيق
                المطلوب (عرض متغيّر)</h6>
            <div class="border rounded-2 overflow-hidden shadow-sm">
                <table class="table table-bordered table-hover align-middle mb-0 text-center small">
                    <thead>
                        <tr>
                            <th>اسم المورد</th>
                            <th>شركة 1</th>
                            <th>شركة 2</th>
                            <th>شركة 3</th>
                            <th>...</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="text-muted">
                            <td class="fw-bold text-dark">شركة ألفا فارما</td>
                            <td>ابن زهر</td>
                            <td>يونيفارما</td>
                            <td></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="form-text small mt-1">
                ⚠️ عدد أعمدة الشركات غير ثابت — يتوقف النظام عند أول خلية فارغة بكل صف (قد تكون شركتين لمورد، وعشراً
                لآخر بنفس الملف).
            </div>
        </div>

        <form action="{{ route('purchasing.suppliers.companies.bulk-import') }}" method="POST"
            enctype="multipart/form-data" data-loading-message="جاري معالجة الملف...">
            @csrf
            <x-file-upload-zone name="links_file" label="ملف الربط" :required="true" accept=".xlsx,.xls,.csv"
                hint="حتى 20MB" />
            <button type="submit" class="btn btn-brand w-100 py-2 fw-medium rounded-2 mt-2">
                <i class="fa-solid fa-upload me-1"></i> رفع ومعالجة الملف
            </button>
        </form>
    </div>
@endsection
