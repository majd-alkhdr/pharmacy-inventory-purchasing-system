<?php

declare(strict_types=1);

namespace App\Services\Shared\ImportHistory;

use App\Enums\ImportFileType;
use App\Enums\ImportLogStatus;
use App\Models\ImportLog;
use Closure;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * 🎯 (أ.1) نقطة الكتابة **الوحيدة** لسجل الرفع الموحَّد. كل شاشة رفع بالقسمين تمر
 * من هنا — المتزامنة عبر track()، والخلفية (Jobs) عبر begin() بالكونترولر ثم
 * completeForSession()/syncSessionStatus() من داخل خدمات المعالجة.
 *
 * 🎓 لماذا تبدأ السجلَّ الكونترولراتُ لا المستوردات نفسها؟ لأن اسم الملف الأصلي
 * (getClientOriginalName) ومن رفعه (auth) معلومتان **لا توجدان إلا بطبقة HTTP** —
 * بعد store('imports') يصبح الاسم hash عشوائياً، وداخل Job لا يوجد مستخدم مصادَق.
 * المستورد يبقى نقياً (يحلّل ملفاً ويُعيد نتيجة)، والسجل يُغلَق بنتيجته من الخارج.
 *
 * 🎓 لماذا لم نوسّع ImportDiagnostics بدل خدمة جديدة؟ ImportDiagnostics "مُجمِّع
 * تحذيرات عملية استيراد واحدة" بالذاكرة — حياته تنتهي بانتهاء الاستيراد. السجل كيان
 * دائم بقاعدة البيانات بدورة حياة مختلفة (يبدأ قبل القراءة، قد ينتظر مطابقة بشرية،
 * يُغلَق بعد ساعات أحياناً). نُعيد استخدام ImportDiagnostics كما هو: صيغته toArray()
 * هي بالضبط ما يُخزَّن بعمود diagnostics — صيغة تحذيرات واحدة بالنظام كله.
 */
final class ImportHistoryService
{
    public function begin(ImportFileType $type, UploadedFile|string $file, ?string $sessionId = null): ImportLog
    {
        return ImportLog::create([
            'file_type' => $type,
            'original_filename' => $file instanceof UploadedFile ? $file->getClientOriginalName() : $file,
            'session_id' => $sessionId,
            'user_id' => auth()->id(),
            'status' => ImportLogStatus::Processing,
            'started_at' => now(),
        ]);
    }

    public function complete(ImportLog $log, ImportStats|ProvidesImportStats $stats): ImportLog
    {
        $stats = $stats instanceof ProvidesImportStats ? $stats->importStats() : $stats;

        $log->update([
            'status' => $stats->hasWarnings() ? ImportLogStatus::SucceededWithWarnings : ImportLogStatus::Succeeded,
            'rows_succeeded' => $stats->succeeded,
            'rows_skipped' => $stats->skipped,
            'rows_warned' => $stats->warned,
            'diagnostics' => $stats->diagnostics,
            'message' => $stats->message,
            'finished_at' => now(),
        ]);

        return $log;
    }

    public function fail(ImportLog $log, string $message): ImportLog
    {
        $log->update(['status' => ImportLogStatus::Failed, 'message' => $message, 'finished_at' => now()]);

        return $log;
    }

    /**
     * غلاف للرفع المتزامن: يفتح السجل، ينفّذ العمل، ويضمن ألا يبقى السجل "قيد المعالجة"
     * للأبد مهما حدث — استثناء ⇒ "فشل" برسالته ثم يُعاد رميه (السلوك القائم بالكونترولر
     * لا يتغيّر)، وعمل انتهى دون إغلاق صريح ⇒ "نجح" بلا عدّادات (أفضل من سجل معلَّق).
     *
     * @template T
     * @param Closure(ImportLog): T $work
     * @return T
     */
    public function track(ImportFileType $type, UploadedFile|string $file, Closure $work): mixed
    {
        $log = $this->begin($type, $file);

        try {
            $result = $work($log);
        } catch (Throwable $e) {
            $this->fail($log, $e->getMessage());

            throw $e;
        }

        if (! $log->refresh()->status->isTerminal()) {
            $log->update(['status' => ImportLogStatus::Succeeded, 'finished_at' => now()]);
        }

        return $result;
    }

    /**
     * يُغلق سجل ملف بعينه ضمن جلسة خلفية فور انتهاء استيراده هو تحديداً — قبل اكتمال
     * بقية خطوات الجلسة (ترميم، فواتير). هذا ضروري لا تجميلي: سجل الكشف يجب أن يصبح
     * "ناجحاً" لحظة كتابة اللقطة، لأن SurplusEngine يعتبر آخر كشف **ناجح** بداية الدورة،
     * والمناقلات العكسية التي يُنشئها الترميم بعد ثوانٍ تُوسَم بتلك الدورة.
     */
    public function completeForSession(string $sessionId, ImportFileType $type, ImportStats|ProvidesImportStats $stats): ?ImportLog
    {
        $log = $this->openSessionLogs($sessionId, $type)->last();

        return $log ? $this->complete($log, $stats) : null;
    }

    /**
     * سجلات ملفات من نوع معيّن بجلسة لم تُغلق بنجاح بعد — بترتيب إنشائها (= ترتيب الملفات
     * بطلب الرفع). جلسة أُعيد تشغيلها بعد مطابقة يدوية تجد سجلها "بانتظار إجراء" هنا نفسه.
     *
     * @return \Illuminate\Support\Collection<int, ImportLog>
     */
    public function openSessionLogs(string $sessionId, ImportFileType $type): \Illuminate\Support\Collection
    {
        return ImportLog::query()
            ->where('session_id', $sessionId)
            ->ofType($type)
            ->whereNotIn('status', ImportLogStatus::successfulValues())
            ->orderBy('id')
            ->get();
    }

    /**
     * يُزامن كل ملفات الجلسة **غير المُغلقة بعد** مع حالة الجلسة. تُستدعى من
     * RecordsImportSession::markSession() — فكل خدمة خلفية تستخدم ذلك الـ Trait (الرفع
     * اليومي، جرد المركزي، النواقص، المطابقات المؤجَّلة) تُحدِّث السجل بلا سطر إضافي.
     *
     * ملف أُغلق صراحة (completeForSession) لا تُغيَّر حالته — فشل الترميم بعد نجاح
     * استيراد الكشف لا يجعل "رفع الكشف" فاشلاً (اللقطة كُتبت فعلاً)؛ تُلحَق الرسالة فقط.
     */
    public function syncSessionStatus(string $sessionId, string $sessionStatus, ?string $message): void
    {
        $status = ImportLogStatus::fromSessionStatus($sessionStatus);

        ImportLog::where('session_id', $sessionId)->get()->each(function (ImportLog $log) use ($status, $message): void {
            if ($log->status->isTerminal()) {
                if ($status === ImportLogStatus::Failed && $message) {
                    $log->update(['message' => trim(($log->message ? $log->message . ' | ' : '') . 'خطوة لاحقة بنفس الدفعة: ' . $message)]);
                }

                return;
            }

            $log->update([
                'status' => $status,
                'message' => $message,
                'finished_at' => $status->isTerminal() ? now() : null,
            ]);
        });
    }
}
