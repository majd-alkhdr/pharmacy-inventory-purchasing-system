<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\UpdateInvoiceItemsRequest;
use App\Models\Invoice;
use App\Services\Inventory\InvoiceEditing\InvoiceEditingService;
use RuntimeException;

/**
 * 🎯 (ب.2) حفظ تعديلات فاتورة مناقلة قبل التصدير. نحيل: الطلب يتحقق ويُطبِّع المدخلات،
 * والخدمة تقرر وتكتب (مع المخزون المركزي وسجل النشاط بمعاملة واحدة).
 */
class InvoiceEditController extends Controller
{
    public function __construct(
        private readonly InvoiceEditingService $editingService,
    ) {
    }

    public function update(UpdateInvoiceItemsRequest $request, int $id)
    {
        $invoice = Invoice::findOrFail($id);

        try {
            $summary = $this->editingService->apply(
                $invoice,
                $request->quantityChanges(),
                $request->removals(),
                $request->additions(),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('inventory.invoice.show', $invoice->id)->with('success', $summary->message());
    }
}
