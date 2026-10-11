<?php
// app/Http/Requests/Purchasing/ImportSupplierCompanyLinksRequest.php
namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class ImportSupplierCompanyLinksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['links_file' => ['required', 'file', 'mimes:xlsx,xls,csv']];
    }

    public function messages(): array
    {
        return [
            'links_file.required' => 'يرجى اختيار الملف.',
            'links_file.mimes'    => 'صيغة الملف غير مدعومة — يُقبَل Excel أو CSV فقط.',
        ];
    }
}
