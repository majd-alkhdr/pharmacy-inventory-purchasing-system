<?php
// app/Services/Inventory/Support/BuildsReplenishmentSummary.php

namespace App\Services\Inventory\Support;

use App\Services\Inventory\RestockingResult;

/**
 * 🆕 [مُعدَّلة 2026-09] كانت تستقبل ParLevelReplenishmentResult حصراً —
 * الآن تستقبل RestockingResult الموحَّد (تُعيده كل استراتيجيات الترميم
 * الثلاث، لا فقط استراتيجية المخزون القياسي). أُضيف عدد الفواتير
 * (invoicesCreated) للرسالة، وهو حقل لم يكن موجوداً بالشكل القديم.
 */
trait BuildsReplenishmentSummary
{
    /**
     * @param array{fulfilled_count:int,partial_count:int,blocked_by_minimum_count?:int} $shortageFollowUp
     * @param array<string> $excludedCompanies
     */
    protected function buildReplenishmentSummaryMessage(
        RestockingResult $replenish,
        array $shortageFollowUp,
        array $excludedCompanies = [],
        ?string $extra = null
    ): string {
        // 🆕 [إصلاح جوهري] قبل هذا، "صفر فواتير" كان يُعرَض دائماً بنفس الرسالة
        // العامة أدناه — حتى لو السبب الحقيقي معروفاً تماماً (مثلاً: كل المبيعات
        // هذه الدفعة كانت لأزواج بلا جرد صيدلية مسجَّل مسبقاً، أو بلا مخزون قياسي
        // محدَّد لها). المستخدم كان يرى "Run & done" بلا أي تفسير لغياب الفواتير.
        // الآن: إن كان السبب أحد هذين تحديداً، تُعرَض رسالة أدق وقابلة للتصرف.
        if ($replenish->pairsEvaluated === 0 && $replenish->invoicesCreated === 0) {
            if ($replenish->skippedNoBaseline > 0 || $replenish->skippedNoStandard > 0) {
                $msg = "ℹ️ بِيعت مواد هذه الدفعة، لكن لم يُقيَّم أي زوج (صيدلية+مادة) منها فعلياً: ";
                $parts = [];
                if ($replenish->skippedNoBaseline > 0) {
                    $parts[] = "{$replenish->skippedNoBaseline} زوجاً بلا جرد صيدلية مسجَّل مسبقاً له (ارفع كشف مستودعات أو ملف جرد صيدليات لهذه الصيدلية أولاً)";
                }
                if ($replenish->skippedNoStandard > 0) {
                    $parts[] = "{$replenish->skippedNoStandard} زوجاً بلا مخزون قياسي محدَّد له (أضِفه من شاشة «المخزون القياسي للصيدليات»)";
                }
                $msg .= implode('، و', $parts) . '. لم يتم تنفيذ أي ترميم لهذا السبب تحديداً — الرصيد وحده تحدَّث حيثما توفَّر جرد سابق.';
            } else {
                $msg = 'ℹ️ لا توجد بيانات كافية لهذه الدفعة (لا جرد صيدليات مطابق لمخزون قياسي محدَّد، ولا بيانات مبيعات صالحة) — لم يتم تنفيذ أي ترميم جديد هذه المرة.';
            }
        } else {
            $msg = "✅ تم تنفيذ {$replenish->invoicesCreated} فاتورة"
                . ($replenish->pairsEvaluated > 0 ? " من أصل {$replenish->pairsEvaluated} تركيبة (صيدلية+مادة) مُقيَّمة: " : ': ')
                . "تغطية كاملة فورية لـ {$replenish->fulfilledCount}، وتغطية جزئية لـ {$replenish->partialCount}، "
                . "وسُجِّل عجز جديد أو مُحدَّث لـ {$replenish->shortagesRecorded}، وأُغلق تلقائياً {$replenish->shortagesResolved} "
                . "(إما لأن الرصيد بلغ الهدف، أو لعدم وجود بيانات مبيعات/معيار قياسي متعلّق بها بعد الآن).";
        }

        // 🆕 [تشخيص] فجوات حقيقية قُمعت بصمت — راجع تعليق RestockingResult
        // للفلسفة الكاملة وراء هذا السلوك نفسه (قرار عمل أصلي موروث، لا خطأ
        // بالحساب). هذا فقط يجعله مرئياً بدل صمت تام بلا أي أثر ظاهر للمستخدم.
        if ($replenish->skippedGapBelowTolerance > 0 || $replenish->skippedGapBelowPackSize > 0) {
            $parts = [];
            if ($replenish->skippedGapBelowTolerance > 0) {
                $parts[] = "{$replenish->skippedGapBelowTolerance} ضمن حد التسامح (الحد الأدنى للفرق)";
            }
            if ($replenish->skippedGapBelowPackSize > 0) {
                $parts[] = "{$replenish->skippedGapBelowPackSize} أصغر من عبوة كاملة واحدة";
            }
            $msg .= ' ℹ️ توجد فجوة حقيقية لم تُترجَم لفاتورة أو نقص مُسجَّل عند '
                . implode(' و', $parts) . ' — لا خطأ، هذا سلوك مقصود (تجاهل الفروق الصغيرة جداً)؛ '
                . 'إن كانت غير مرغوبة، راجع الحد الأدنى للفرق أو حجم العبوة لهذه المادة.';
        }

        if (($shortageFollowUp['fulfilled_count'] ?? 0) > 0 || ($shortageFollowUp['partial_count'] ?? 0) > 0) {
            $msg .= " كما تم ترميم نواقص معلَّقة سابقة: {$shortageFollowUp['fulfilled_count']} بالكامل و{$shortageFollowUp['partial_count']} جزئياً من الرصيد المركزي المتوفر الآن.";
        }

        if (($shortageFollowUp['blocked_by_minimum_count'] ?? 0) > 0) {
            $msg .= " ⚠️ تم تجاوز {$shortageFollowUp['blocked_by_minimum_count']} نقص قديم لأن رصيد الصيدلية لا يزال أعلى من مخزونها القياسي.";
        }

        // 🆕 المناقلات العكسية — راجع ReverseTransferRecorderService
        if ($replenish->reverseTransfersCreated > 0) {
            // 🆕 (ب.6) "مقترحة" لا "منفَّذة" — لا مخزون يتحرك حتى يُضغط "تنفيذ" بشاشتها.
            $msg .= " 🔄 كما تم اقتراح {$replenish->reverseTransfersCreated} مناقلة عكسية (صيدليات لديها فائض يتجاوز مخزونها القياسي) — بانتظار التنفيذ بشاشة المناقلات العكسية.";
        }

        if (!empty($excludedCompanies)) {
            $msg .= ' ⚠️ تم استثناء الشركات التالية من الترميم بهذه الدفعة: ' . implode('، ', $excludedCompanies) . '.';
        }

        return $extra ? $msg . $extra : $msg;
    }
}
