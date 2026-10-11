<?php

namespace App\Http\Requests;

use App\Enums\ImportFileType;
use App\Enums\ImportLogStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 🎯 (أ.1) فلترة سجل الرفع الموحَّد. الشاشة مشتركة بين القسمين، فالصلاحية هنا "أي قسم
 * عمليات" — لا يكفي middleware('module:x') الأحادي لأن المطلوب "هذا أو ذاك".
 */
class ImportHistoryFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ($user->hasModuleAccess('transfers_operations') || $user->hasModuleAccess('purchasing_operations'));
    }

    public function rules(): array
    {
        return [
            'file_type' => ['nullable', Rule::enum(ImportFileType::class)],
            'status'    => ['nullable', Rule::enum(ImportLogStatus::class)],
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }

    /** @return array{file_type: ?ImportFileType, status: ?ImportLogStatus, date_from: ?string, date_to: ?string} */
    public function filters(): array
    {
        return [
            'file_type' => $this->filled('file_type') ? ImportFileType::from($this->validated('file_type')) : null,
            'status' => $this->filled('status') ? ImportLogStatus::from($this->validated('status')) : null,
            'date_from' => $this->validated('date_from'),
            'date_to' => $this->validated('date_to'),
        ];
    }
}
