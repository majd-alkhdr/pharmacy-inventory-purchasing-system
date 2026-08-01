<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\ImportCategoriesMasterRequest;
use App\Http\Requests\Purchasing\ImportCategoryLinksRequest;
use App\Http\Requests\Purchasing\SaveCategoryMappingRequest;
use App\Imports\CategoriesMasterImport;
use App\Imports\CategoryProductLinksImport;
use App\Models\Category;
use App\Models\PendingCategoryLink;
use App\Services\Purchasing\CategoryImportService;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryImportService $importService,
    ) {
    }

    public function showForm()
    {
        return view('purchasing.categories-import', [
            'categoriesCount' => Category::count(),
            'pendingLinksCount' => PendingCategoryLink::count(),
        ]);
    }

    /** الملف الأول: قائمة التصنيفات الرئيسية — عمود واحد فقط */
    public function uploadMasterList(ImportCategoriesMasterRequest $request)
    {
        $filePath = $request->file('categories_file')->store('imports');

        $import = new CategoriesMasterImport();
        Excel::import($import, Storage::path($filePath));
        Storage::delete($filePath);

        $created = $this->importService->importMasterList($import->names);

        return back()->with('success', "✅ تم إضافة {$created} تصنيف جديد لقائمة التصنيفات الرسمية (الإجمالي الآن: " . Category::count() . ').');
    }

    /** الملف الثاني: ربط المنتجات بالتصنيفات الموجودة */
    public function uploadLinks(ImportCategoryLinksRequest $request)
    {
        $sessionId = uniqid('category_link_session_');
        $filePath = $request->file('links_file')->store('imports');

        $import = new CategoryProductLinksImport();
        Excel::import($import, Storage::path($filePath));
        Storage::delete($filePath);

        if ($import->rows->isEmpty()) {
            return back()->with('error', 'الملف فارغ أو لا يحتوي على بيانات صالحة.');
        }

        $result = $this->importService->importLinks($sessionId, $import->rows);

        return view('purchasing.categories-import-result', ['result' => $result, 'sessionId' => $sessionId]);
    }

    /** شاشة مطابقة يدوية لكل منتج بقي بلا ربط (تصنيفه بالملف لم يكن معروفاً) */
    public function showMapping(string $sessionId)
    {
        $pending = PendingCategoryLink::forSession($sessionId)->with('product')->get();

        if ($pending->isEmpty()) {
            return redirect()->route('purchasing.categories.form')->with('info', 'لا توجد روابط معلّقة بهذه الجلسة.');
        }

        return view('purchasing.categories-mapping', [
            'sessionId'  => $sessionId,
            'pending'     => $pending,
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function saveMapping(SaveCategoryMappingRequest $request)
    {
        $resolved = $this->importService->resolvePendingLinks($request->sessionId(), $request->decisions());

        return redirect()->route('purchasing.categories.form')
            ->with('success', "✅ تم ربط {$resolved} منتج بتصنيفاتها بنجاح.");
    }
}
