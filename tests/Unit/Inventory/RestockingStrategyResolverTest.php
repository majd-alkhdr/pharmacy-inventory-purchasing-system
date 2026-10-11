<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Enums\RestockingMode;
use App\Services\Inventory\HybridRestockingStrategy;
use App\Services\Inventory\InvoiceGenerationService;
use App\Services\Inventory\ParLevelCalculator;
use App\Services\Inventory\ParLevelReplenishmentService;
use App\Services\Inventory\ReverseTransferRecorderService;
use App\Services\Inventory\SalesBasedRestockingStrategy;
use App\Services\Inventory\SalesGatedStandardInventoryRestockingStrategy;
use App\Services\Inventory\SalesRemainderResolverService;
use App\Services\Inventory\ShortageRecorderService;
use App\Services\Inventory\StandardInventoryRestockingStrategy;
use App\Services\Inventory\RestockingStrategyResolver;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * 🎯 يحمي الإصلاح مباشرة عند مصدره: RestockingStrategyResolver::resolveSalesComponent().
 *
 * لماذا وحدوي (بلا قاعدة بيانات) بدل اختبار PendingProductService/SalesMappingService
 * كاملتين من طرف لطرف؟ كلتاهما تستدعيان SalesMappingService::matchAutomatically()
 * أولاً وبلا شرط — التي تُنفِّذ SQL خام بصياغة `UPDATE x JOIN y` (MySQL صحيحة
 * ومُستخدَمة بالإنتاج فعلياً)، وSQLite لا يدعم هذه الصياغة إطلاقاً (خطأ قواعدي،
 * لا خطأ منطقي) — قيد ببيئة الاختبار المحلية هذه، موجود مسبقاً وغير متعلق
 * بهذا الإصلاح. بدلاً من ذلك، هذا الاختبار يتحقق من **نفس القرار** الذي
 * تعتمد عليه نقطتا الاستدعاء المُصلَحتان (PendingProductService،
 * SalesMappingService) — عبر بناء السلسلة الحقيقية الكاملة لكل استراتيجية
 * (لا محاكاة/Mock) والتحقق من الهُوية (===) لا مجرد النوع.
 */
final class RestockingStrategyResolverTest extends TestCase
{
    private function resolver(): array
    {
        $sales = new SalesBasedRestockingStrategy(new InvoiceGenerationService(
            new ShortageRecorderService(),
            new SalesRemainderResolverService(),
        ));
        $standard = new StandardInventoryRestockingStrategy(new ParLevelReplenishmentService(
            new ReverseTransferRecorderService(),
        ));
        $hybrid = new HybridRestockingStrategy($sales, $standard);
        $salesGated = new SalesGatedStandardInventoryRestockingStrategy(
            new SalesRemainderResolverService(),
            new ParLevelCalculator(),
            new ReverseTransferRecorderService(),
        );

        return [new RestockingStrategyResolver($sales, $standard, $hybrid, $salesGated), $sales, $standard, $hybrid, $salesGated];
    }

    // ── resolve(): يجب ألا يتأثر بالإضافة إطلاقاً (الأوضاع الثلاثة القديمة + الرابع الجديد) ──

    public function test_resolve_maps_every_mode_to_its_own_strategy_instance(): void
    {
        [$resolver, $sales, $standard, $hybrid, $salesGated] = $this->resolver();

        $this->assertSame($sales, $resolver->resolve(RestockingMode::Sales));
        $this->assertSame($standard, $resolver->resolve(RestockingMode::StandardInventory));
        $this->assertSame($hybrid, $resolver->resolve(RestockingMode::Hybrid));
        $this->assertSame($salesGated, $resolver->resolve(RestockingMode::SalesGatedStandardInventory));
    }

    // ── resolveSalesComponent(): بالضبط الدالة التي أُصلحت نقطتا الاستدعاء لاستخدامها ──

    public function test_resolve_sales_component_returns_the_old_sales_strategy_for_plain_sales_mode(): void
    {
        [$resolver, $sales] = $this->resolver();

        $this->assertSame($sales, $resolver->resolveSalesComponent(RestockingMode::Sales));
    }

    public function test_resolve_sales_component_returns_the_old_sales_strategy_for_hybrid_mode_not_hybrid_itself(): void
    {
        // 🎯 هذا بالضبط ما كان سيفشل صامتاً بدون الإصلاح: Hybrid يجب أن يُعيد
        // استراتيجية المبيعات المفردة، لا HybridRestockingStrategy نفسها (التي
        // كانت ستُعيد تنفيذ المخزون القياسي مجدَّداً لو استُدعيت هنا خطأً).
        [$resolver, $sales, , $hybrid] = $this->resolver();

        $result = $resolver->resolveSalesComponent(RestockingMode::Hybrid);

        $this->assertSame($sales, $result);
        $this->assertNotSame($hybrid, $result);
    }

    public function test_resolve_sales_component_returns_the_new_strategy_for_the_sales_gated_mode_not_the_old_one(): void
    {
        // 🎯 هذا بالضبط الإصلاح نفسه: قبله، كان هذا السطر (بمكانين بالكود
        // الفعلي) يُعيد $sales دائماً بصرف النظر عن الوضع — توجيه خاطئ صامت.
        [$resolver, $sales, , , $salesGated] = $this->resolver();

        $result = $resolver->resolveSalesComponent(RestockingMode::SalesGatedStandardInventory);

        $this->assertSame($salesGated, $result);
        $this->assertNotSame($sales, $result);
    }

    public function test_resolve_sales_component_rejects_a_mode_that_does_not_use_sales_at_all(): void
    {
        [$resolver] = $this->resolver();

        $this->expectException(LogicException::class);

        $resolver->resolveSalesComponent(RestockingMode::StandardInventory);
    }
}
