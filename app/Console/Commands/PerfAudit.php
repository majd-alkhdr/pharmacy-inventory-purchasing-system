<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exports\AllInvoicesExport;
use App\Exports\AllPurchaseOrdersExport;
use App\Exports\FinanceSettlementExport;
use App\Exports\InvoiceExport;
use App\Exports\PharmacyTransferExport;
use App\Exports\PreparationExport;
use App\Exports\PreparationPharmacySheetExport;
use App\Exports\PreparationTemplateExport;
use App\Exports\PurchaseOrderExport;
use App\Exports\ReconciliationResultExport;
use App\Exports\ReverseTransferExport;
use App\Exports\ReverseTransfersExport;
use App\Exports\ShortagesExport;
use App\Models\FinanceSettlementItem;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\TransferReconciliation;
use App\Models\User;
use App\Services\Inventory\Reconciliation\TransferReconciliationService;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Events\BeforeWriting;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * 🎯 أداة تشخيص أداء (قراءة/قياس فقط) — لا تغيّر سلوك التطبيق.
 *
 * تعمل **فقط** على قاعدة النسخة `dawak_perf` (ترفض أي قاعدة أخرى)، وعلى production فقط مع --force-db=dawak_perf.
 * - الصفحات: طلبات داخلية عبر Kernel::handle() بجلسة مستخدم حقيقية (كوكي مشفّر + صف sessions)، فتُقاس
 *   استعلامات الجلسة/المستخدم/الصلاحيات كما بالإنتاج.
 * - التصدير: كلاسات app/Exports مباشرة (Excel::raw بالذاكرة) — لا مسارات التصدير التي تقفل الفواتير.
 * لكل تجربة: الزمن، عدد الاستعلامات ومجموع زمنها، أبطأ 5، الأشكال المتكررة (N+1)، ذروة الذاكرة، الحجم.
 *
 *   DB_DATABASE=dawak_perf php artisan perf:audit --pages --exports --json=C:/perf-audit/run.json
 *   php artisan perf:audit --force-db=dawak_perf --exports --autosize-off
 */
class PerfAudit extends Command
{
    private const PERF_DB = 'dawak_perf';

    protected $signature = 'perf:audit
        {--force-db= : اسم القاعدة صراحة (يجب أن يكون dawak_perf)}
        {--pages : قياس الصفحات}
        {--exports : قياس كلاسات التصدير}
        {--autosize-off : التصدير بعرض أعمدة ثابت بدل ShouldAutoSize (للمقارنة فقط، داخل هذه العملية)}
        {--runs=3 : عدد التجارب لكل هدف (الأولى باردة)}
        {--only= : أسماء أهداف مفصولة بفواصل}
        {--user= : معرّف المستخدم (الافتراضي: أول مدير نشِط)}
        {--json= : مسار ملف JSON للنتائج}';

    protected $description = 'قياس أداء الصفحات والتصدير على نسخة dawak_perf (تشخيص فقط)';

    /** @var list<array{sql: string, ms: float}> */
    private array $queries = [];

    public function handle(): int
    {
        if (!$this->guardDatabase()) {
            return self::FAILURE;
        }

        // لا شيء يُكتب بمجلد التطبيق الحيّ: العروض المترجمة والسجل بمجلد القياس.
        $scratch = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'perf-audit';
        @mkdir($scratch . '/views', 0777, true);
        config(['view.compiled' => $scratch . '/views', 'logging.channels.single.path' => $scratch . '/laravel.log']);
        app('view')->getEngineResolver()->forget('blade');
        app()->forgetInstance('blade.compiler');

        DB::listen(function (QueryExecuted $q): void {
            $this->queries[] = ['sql' => $q->sql, 'ms' => (float) $q->time];
        });

        $runs = max(1, (int) $this->option('runs'));
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $results = ['database' => DB::getDatabaseName(), 'php' => PHP_VERSION, 'opcache' => function_exists('opcache_get_status') && @opcache_get_status(false) !== false, 'runs' => $runs];

        if ($this->option('pages') || !$this->option('exports')) {
            $results['pages'] = $this->measurePages($runs, $only);
        }
        if ($this->option('exports')) {
            if ($this->option('autosize-off')) {
                $this->disableAutoSize();
            }
            $results['exports'] = $this->measureExports($runs, $only);
            $results['autosize'] = !$this->option('autosize-off');
        }

        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info("JSON: {$path}");
        }

        return self::SUCCESS;
    }

    private function guardDatabase(): bool
    {
        $forced = $this->option('force-db');
        if ($forced !== null) {
            if ($forced !== self::PERF_DB) {
                $this->error('--force-db يقبل dawak_perf فقط.');

                return false;
            }
            config(['database.connections.' . config('database.default') . '.database' => self::PERF_DB]);
            DB::purge();
        } elseif (app()->environment('production')) {
            $this->error('على production يلزم --force-db=dawak_perf صراحة.');

            return false;
        }

        if (DB::getDatabaseName() !== self::PERF_DB) {
            $this->error('رفض: القاعدة الحالية «' . DB::getDatabaseName() . '» — هذه الأداة تعمل على dawak_perf فقط.');

            return false;
        }

        return true;
    }

    // ───────────────────────────── الصفحات ─────────────────────────────

    /** @return array<string, string> اسم الهدف ← الرابط النسبي */
    private function pageTargets(): array
    {
        $biggest = fn (string $table, string $fk) => DB::table($table)->select($fk)->groupBy($fk)->orderByRaw('count(*) desc')->value($fk);

        $invoiceId = $biggest('invoice_items', 'invoice_id');
        $orderId = $biggest('purchase_order_items', 'purchase_order_id');
        $reconInvoice = DB::table('transfer_reconciliation_items')
            ->join('transfer_reconciliations', 'transfer_reconciliations.id', '=', 'transfer_reconciliation_items.transfer_reconciliation_id')
            ->groupBy('transfer_reconciliations.invoice_id')->orderByRaw('count(*) desc')->value('transfer_reconciliations.invoice_id');
        $reverseId = $biggest('reverse_transfer_items', 'reverse_transfer_id');
        $pharmacyTransferId = $biggest('pharmacy_transfer_items', 'pharmacy_transfer_id');
        $importLogId = DB::table('import_logs')->max('id');

        $targets = [
            'home' => route('home', [], false),
            'invoices.index' => route('inventory.invoices.index', [], false),
            'invoice.show' => $invoiceId ? route('inventory.invoice.show', $invoiceId, false) : null,
            'invoice.reconciliation' => $reconInvoice ? route('inventory.invoice.reconciliation', $reconInvoice, false) : null,
            'purchasing.orders.index' => route('purchasing.orders.index', [], false),
            'purchasing.orders.show' => $orderId ? route('purchasing.orders.show', $orderId, false) : null,
            'shortages.dashboard' => route('inventory.shortages.dashboard', [], false),
            'reverse-transfers.report' => route('inventory.reverse-transfers.report', [], false),
            'reverse-transfer.show' => $reverseId ? route('inventory.reverse-transfer.show', $reverseId, false) : null,
            'pharmacy-transfers.review' => route('inventory.pharmacy-transfers.review', [], false),
            'pharmacy-transfer.show' => $pharmacyTransferId ? route('inventory.pharmacy-transfer.show', $pharmacyTransferId, false) : null,
            'import-history.index' => route('import-history.index', [], false),
            'import-history.show' => $importLogId ? route('import-history.show', $importLogId, false) : null,
            'finance-settlements.index' => route('inventory.finance-settlements.index', [], false),
            'standard-stock.lookup' => route('inventory.standard-stock.lookup', [], false),
            'upload-sales' => route('inventory.upload.form', [], false),
            'upload-inventory' => route('inventory.stock.upload.form', [], false),
            'shortages.upload' => route('inventory.shortages.upload.form', [], false),
            'pharmacy-minimum-stock' => route('inventory.pharmacy-minimum-stock.form', [], false),
            'purchasing.generate' => route('purchasing.orders.create', [], false),
            'purchasing.suppliers.index' => route('purchasing.suppliers.index', [], false),
        ];
        foreach (['inventory-turnover', 'inventory-value', 'product-movement', 'shortage-value', 'transfers-value'] as $report) {
            $targets["reports.{$report}"] = route("inventory.reports.{$report}", [], false);
        }
        foreach (['cost-variance', 'purchase-shortage-value', 'purchase-value', 'supplier-summary'] as $report) {
            $targets["purchasing.reports.{$report}"] = route("purchasing.reports.{$report}", [], false);
        }

        return array_filter($targets);
    }

    private function measurePages(int $runs, array $only): array
    {
        $user = $this->option('user')
            ? User::findOrFail((int) $this->option('user'))
            : User::query()->where('is_active', true)->whereHas('role', fn ($q) => $q->where('key', Role::ADMIN))->firstOrFail();
        $cookie = $this->sessionCookieFor($user);
        $kernel = app(HttpKernel::class);

        $out = [];
        foreach ($this->pageTargets() as $name => $uri) {
            if ($only && !in_array($name, $only, true)) {
                continue;
            }
            for ($i = 1; $i <= $runs; $i++) {
                app('auth')->forgetGuards();
                app('session')->forgetDrivers();
                $request = Request::create($uri, 'GET', [], [config('session.cookie') => $cookie], [], ['HTTP_ACCEPT' => 'text/html', 'REMOTE_ADDR' => '127.0.0.1']);

                $measure = $this->measure(function () use ($kernel, $request) {
                    $response = $kernel->handle($request);
                    $kernel->terminate($request, $response);

                    return $response;
                });
                $response = $measure['result'];
                unset($measure['result']);
                $out[$name][] = ['uri' => $uri, 'run' => $i, 'status' => $response->getStatusCode(), 'bytes' => $this->responseBytes($response)] + $measure;
            }
            $this->report($name, $out[$name]);
        }

        return $out;
    }

    /** جلسة حقيقية بقاعدة dawak_perf + كوكي مشفّر كما يرسله المتصفح. */
    private function sessionCookieFor(User $user): string
    {
        $store = app('session')->driver();
        $store->setId(null);
        $store->start();
        $store->put('login_web_' . sha1(SessionGuard::class), $user->getAuthIdentifier());
        $store->save();
        $name = config('session.cookie');
        $encrypter = app('encrypter');

        return $encrypter->encrypt(CookieValuePrefix::create($name, $encrypter->getKey()) . $store->getId(), false);
    }

    private function responseBytes($response): ?int
    {
        if ($response instanceof BinaryFileResponse) {
            return $response->getFile()->getSize();
        }
        if ($response instanceof StreamedResponse) {
            return null;
        }

        return strlen((string) $response->getContent());
    }

    // ───────────────────────────── التصدير ─────────────────────────────

    /** @return array<string, Closure(): object> */
    private function exportTargets(): array
    {
        $bySize = function (string $table, string $fk): array {
            $rows = DB::table($table)->selectRaw("{$fk} id, count(*) n")->groupBy($fk)->orderBy('n')->get();
            if ($rows->isEmpty()) {
                return [];
            }

            return ['small' => $rows->first(), 'medium' => $rows[intdiv($rows->count(), 2)], 'large' => $rows->last()];
        };

        $targets = [];
        foreach ($bySize('invoice_items', 'invoice_id') as $size => $row) {
            $targets["InvoiceExport.{$size}({$row->n})"] = fn () => new InvoiceExport($row->id);
            $targets["PreparationTemplateExport.{$size}({$row->n})"] = fn () => new PreparationTemplateExport($row->id);
        }
        $topDate = DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->selectRaw('date(invoices.date) d, count(*) n')->groupByRaw('date(invoices.date)')->orderByDesc('n')->first();
        if ($topDate) {
            $targets["AllInvoicesExport.date({$topDate->n})"] = fn () => new AllInvoicesExport(['date' => $topDate->d]);
            $targets["PreparationExport.date({$topDate->d})"] = fn () => new PreparationExport(date: $topDate->d);
            $pharmacy = DB::table('invoices')->join('pharmacies', 'pharmacies.id', '=', 'invoices.pharmacy_id')->whereDate('invoices.date', $topDate->d)->select('pharmacies.id', 'pharmacies.name')->first();
            if ($pharmacy) {
                $targets['PreparationPharmacySheetExport.one'] = fn () => new class($topDate->d, (int) $pharmacy->id, (string) $pharmacy->name) implements \Maatwebsite\Excel\Concerns\WithMultipleSheets {
                    public function __construct(private string $d, private int $id, private string $name) {}

                    public function sheets(): array
                    {
                        return [new PreparationPharmacySheetExport(date: $this->d, pharmacyId: $this->id, pharmacyName: $this->name, sheetTitle: 'sheet')];
                    }
                };
            }
        }
        $targets['AllInvoicesExport.all(' . DB::table('invoice_items')->count() . ')'] = fn () => new AllInvoicesExport([]);

        foreach ($bySize('purchase_order_items', 'purchase_order_id') as $size => $row) {
            $targets["PurchaseOrderExport.{$size}({$row->n})"] = fn () => new PurchaseOrderExport(PurchaseOrder::findOrFail($row->id));
        }
        $targets['AllPurchaseOrdersExport.all'] = fn () => new AllPurchaseOrdersExport([]);

        foreach ($bySize('transfer_reconciliation_items', 'transfer_reconciliation_id') as $size => $row) {
            $targets["ReconciliationResultExport.{$size}({$row->n})"] = function () use ($row) {
                $reconciliation = TransferReconciliation::findOrFail($row->id);

                return new ReconciliationResultExport(app(TransferReconciliationService::class)->itemsForExport($reconciliation, false), Invoice::findOrFail($reconciliation->invoice_id));
            };
        }
        // نفس استعلام FinanceSettlementExportService::takeForExport بلا تعليم البنود (قراءة فقط).
        $targets['FinanceSettlementExport.all'] = fn () => new FinanceSettlementExport(FinanceSettlementItem::query()
            ->with(['invoice:id,date', 'pharmacy:id,name', 'product:id,name,barcode,company', 'reason:id,label'])
            ->orderBy('pharmacy_id')->orderBy('invoice_id')->get());

        foreach ($bySize('pharmacy_transfer_items', 'pharmacy_transfer_id') as $size => $row) {
            $targets["PharmacyTransferExport.{$size}({$row->n})"] = fn () => new PharmacyTransferExport((int) $row->id);
        }
        foreach ($bySize('reverse_transfer_items', 'reverse_transfer_id') as $size => $row) {
            $targets["ReverseTransferExport.{$size}({$row->n})"] = fn () => new ReverseTransferExport((int) $row->id);
        }
        $targets['ReverseTransfersExport.all'] = fn () => new ReverseTransfersExport([]);
        $targets['ShortagesExport.all'] = fn () => new ShortagesExport([]);

        return $targets;
    }

    private function measureExports(int $runs, array $only): array
    {
        $out = [];
        foreach ($this->exportTargets() as $name => $make) {
            $class = strstr($name, '.', true);
            if ($only && !in_array($name, $only, true) && !in_array($class, $only, true)) {
                continue;
            }
            for ($i = 1; $i <= $runs; $i++) {
                try {
                    // تحميل البيانات وحده (collection()/sheets()) ثم الملف كاملاً — الفرق = البناء والكتابة.
                    $data = $this->measure(function () use ($make) {
                        $export = $make();

                        return method_exists($export, 'collection') ? $export->collection()->count() : count($export->sheets());
                    });
                    $full = $this->measure(fn () => Excel::raw($make(), ExcelType::XLSX));
                    $bytes = strlen($full['result']);
                    unset($full['result']);
                    $out[$name][] = ['run' => $i, 'rows_or_sheets' => $data['result'], 'data_ms' => $data['ms'], 'data_queries' => $data['queries'], 'bytes' => $bytes] + $full;
                } catch (Throwable $e) {
                    $out[$name][] = ['run' => $i, 'error' => $e->getMessage()];
                    break;
                }
            }
            $this->report($name, $out[$name]);
        }

        return $out;
    }

    /** عرض ثابت بدل الحساب التلقائي — مستمع عام بهذه العملية فقط (لا يُمس أي كلاس). */
    private function disableAutoSize(): void
    {
        Writer::listen(BeforeWriting::class, function (BeforeWriting $event): void {
            foreach ($event->writer->getDelegate()->getAllSheets() as $sheet) {
                foreach ($sheet->getColumnDimensions() as $dimension) {
                    if ($dimension->getAutoSize()) {
                        $dimension->setAutoSize(false)->setWidth(20);
                    }
                }
            }
        });
    }

    // ───────────────────────────── القياس ─────────────────────────────

    private function measure(Closure $fn): array
    {
        $this->queries = [];
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $t0 = hrtime(true);
        $result = $fn();
        $ms = (hrtime(true) - $t0) / 1e6;

        $queries = $this->queries;
        usort($queries, fn ($a, $b) => $b['ms'] <=> $a['ms']);
        $shapes = [];
        foreach ($this->queries as $q) {
            $shape = preg_replace(['/\s+/', '/\b\d+\b/', '/\((\?(, )?)+\)/', "/'[^']*'/"], [' ', 'N', '(?…)', "'…'"], $q['sql']);
            $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
        }
        arsort($shapes);

        return [
            'result' => $result,
            'ms' => round($ms, 1),
            'queries' => count($this->queries),
            'query_ms' => round(array_sum(array_column($this->queries, 'ms')), 1),
            'slowest' => array_map(fn ($q) => ['ms' => $q['ms'], 'sql' => mb_strimwidth($q['sql'], 0, 300, '…')], array_slice($queries, 0, 5)),
            'repeated' => array_slice(array_filter($shapes, fn ($n) => $n >= 3), 0, 5, true),
            'peak_mb' => round((memory_get_peak_usage() - $before) / 1048576, 1),
        ];
    }

    private function report(string $name, array $runs): void
    {
        $rows = array_map(fn ($r) => [
            $name, $r['run'], $r['status'] ?? ($r['rows_or_sheets'] ?? '-'), $r['ms'] ?? '-', $r['data_ms'] ?? '-',
            $r['queries'] ?? '-', $r['query_ms'] ?? '-', $r['peak_mb'] ?? '-',
            isset($r['bytes']) ? number_format($r['bytes'] / 1024, 1) : '-', $r['error'] ?? (array_sum($r['repeated'] ?? []) ?: ''),
        ], $runs);
        $this->table(['target', 'run', 'status/rows', 'ms', 'data_ms', 'queries', 'query_ms', 'peak_mb', 'KB', 'N+1/error'], $rows);
    }
}
