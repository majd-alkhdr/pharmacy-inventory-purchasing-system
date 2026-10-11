<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 🎯 "ماذا حدث؟" بسجل النشاط الشامل (AuditLog) — كل ميزة جديدة بالقسمين تكتب
 * لنفس الجدول عبر AuditLogger، والفرق الوحيد بينها هو قيمة من هنا.
 *
 * 🎓 لماذا Enum مركزي لا ثوابت متفرقة بكل خدمة؟ لأن شاشة السجل (أو أي تقرير
 * لاحق) تحتاج تسمية عربية موحَّدة لكل إجراء بصرف النظر عن مصدره، ولأن البحث
 * "كل تعديلات الكميات" يجب أن يكون استعلاماً واحداً على قيم معروفة مسبقاً.
 */
enum AuditAction: string
{
    // ── فاتورة المناقلة (ب.2) ──
    case InvoiceItemQuantityChanged = 'invoice_item.quantity_changed';
    case InvoiceItemRemoved = 'invoice_item.removed';
    case InvoiceItemAdded = 'invoice_item.added';

    // ── مطابقة التحضير (ب.3) ──
    case ReconciliationUploaded = 'reconciliation.uploaded';
    case ReconciliationReasonSet = 'reconciliation.reason_set';
    case FinanceSettlementFlagged = 'finance_settlement.flagged';
    case FinanceSettlementCleared = 'finance_settlement.cleared';
    case FinanceSettlementExported = 'finance_settlement.exported';

    // ── تنفيذ المناقلات (ب.6/ب.7/ج.3) ──
    case ReverseTransferExecuted = 'reverse_transfer.executed';
    case ReverseTransferDismissed = 'reverse_transfer.dismissed';
    case PharmacyTransferExecuted = 'pharmacy_transfer.executed';
    case PharmacyTransferDismissed = 'pharmacy_transfer.dismissed';

    // ── فاتورة الشراء (ج.2) ──
    case PurchaseItemApprovedQtyChanged = 'purchase_item.approved_qty_changed';
    case PurchaseItemRemoved = 'purchase_item.removed';

    public function label(): string
    {
        return match ($this) {
            self::InvoiceItemQuantityChanged => 'تعديل كمية بفاتورة مناقلة',
            self::InvoiceItemRemoved => 'حذف مادة من فاتورة مناقلة',
            self::InvoiceItemAdded => 'إضافة مادة لفاتورة مناقلة',
            self::ReconciliationUploaded => 'رفع ملف تحضير لمطابقة مناقلة',
            self::ReconciliationReasonSet => 'تحديد سبب فرق مطابقة',
            self::FinanceSettlementFlagged => 'إحالة مادة لتسوية المالية',
            self::FinanceSettlementCleared => 'إلغاء إحالة مادة لتسوية المالية',
            self::FinanceSettlementExported => 'تصدير مواد التسوية للمالية',
            self::ReverseTransferExecuted => 'تنفيذ مناقلة عكسية',
            self::ReverseTransferDismissed => 'رفض مناقلة عكسية',
            self::PharmacyTransferExecuted => 'تنفيذ مناقلة بين صيدليتين',
            self::PharmacyTransferDismissed => 'رفض مناقلة بين صيدليتين',
            self::PurchaseItemApprovedQtyChanged => 'تعديل الكمية المعتمدة بفاتورة شراء',
            self::PurchaseItemRemoved => 'حذف مادة من فاتورة شراء',
        };
    }
}
