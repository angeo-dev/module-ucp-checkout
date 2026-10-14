<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Test\Unit\Model;

use Angeo\UcpCheckout\Model\ItemReference;
use PHPUnit\Framework\TestCase;

class ItemReferenceTest extends TestCase
{
    public function testParsesCatalogIdsAndSkus(): void
    {
        $ref = new ItemReference();
        self::assertSame(['kind' => 'variant', 'value' => '42'], $ref->parse('gid://magento/ProductVariant/42'));
        self::assertSame(['kind' => 'product', 'value' => '7'], $ref->parse('gid://magento/Product/7'));
        self::assertSame(['kind' => 'sku', 'value' => '24-MB02'], $ref->parse(' 24-MB02 '));
        self::assertSame(['kind' => 'sku', 'value' => '1001'], $ref->parse(1001));
    }

    public function testRejectsUnusableIds(): void
    {
        $ref = new ItemReference();
        self::assertNull($ref->parse(''));
        self::assertNull($ref->parse(null));
        self::assertNull($ref->parse(['id' => 1]));
        self::assertNull($ref->parse('gid://shopify/ProductVariant/1'));
        self::assertNull($ref->parse('gid://magento/ProductVariant/abc'));
        self::assertNull($ref->parse(str_repeat('x', 300)));
    }

    public function testVariantGid(): void
    {
        self::assertSame('gid://magento/ProductVariant/5', ItemReference::variantGid(5));
    }
}
