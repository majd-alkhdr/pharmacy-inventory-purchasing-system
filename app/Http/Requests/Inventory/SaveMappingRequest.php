<?php

namespace App\Http\Requests\Inventory;

class SaveMappingRequest extends AbstractMappingRequest
{
    // لا حاجة لإضافة شيء — نستفيد بالكامل من الأصل المشترك.
    // بقاء ملف مستقل (بدل استخدام الأصل مباشرة) يسمح مستقبلاً بإضافة
    // قواعد تحقق خاصة بمطابقة "المبيعات" فقط دون التأثير على مطابقة النواقص.
}
