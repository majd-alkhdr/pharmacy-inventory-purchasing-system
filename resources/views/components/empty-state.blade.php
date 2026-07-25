{{--
    🎯 نُقلت من التكرار الحاصل بـ @empty بكل من: invoices-index, shortages,
    invoice-details. نفس الأيقونة الرمادية + نفس النص الرمادي + نفس الـ padding.
    الاستخدام داخل <tbody>:
    <x-empty-state colspan="6" icon="fa-box-open" message="لا توجد سجلات تطابق الفلترة الحالية." />
--}}
@props(['colspan' => 1, 'icon' => 'fa-inbox', 'message' => 'لا توجد بيانات لعرضها.'])

<tr>
    <td colspan="{{ $colspan }}" class="text-center text-muted py-5 bg-light">
        <i class="fa-solid {{ $icon }} fa-2x mb-2 opacity-50"></i>
        <p class="mb-0 fw-medium">{{ $message }}</p>
    </td>
</tr>
