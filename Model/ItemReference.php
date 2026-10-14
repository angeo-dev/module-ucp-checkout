<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

/**
 * Parses `line_items[].item.id`. Accepted forms:
 *  - gid://magento/ProductVariant/{id} — what Angeo_UcpCatalog returns for a
 *    buyable variant (a simple product, or a configurable's child);
 *  - gid://magento/Product/{id}        — a product; configurables need a variant;
 *  - anything else is treated as a SKU.
 */
class ItemReference
{
    public const KIND_VARIANT = 'variant';
    public const KIND_PRODUCT = 'product';
    public const KIND_SKU     = 'sku';

    private const MAX_LENGTH = 255;

    /**
     * @return array{kind: string, value: string}|null null when unusable
     */
    public function parse(mixed $id): ?array
    {
        if (!is_string($id) && !is_int($id)) {
            return null;
        }
        $id = trim((string) $id);
        if ($id === '' || strlen($id) > self::MAX_LENGTH) {
            return null;
        }
        if (preg_match('#^gid://magento/ProductVariant/(\d+)$#', $id, $m)) {
            return ['kind' => self::KIND_VARIANT, 'value' => $m[1]];
        }
        if (preg_match('#^gid://magento/Product/(\d+)$#', $id, $m)) {
            return ['kind' => self::KIND_PRODUCT, 'value' => $m[1]];
        }
        if (str_starts_with($id, 'gid://')) {
            return null;
        }
        return ['kind' => self::KIND_SKU, 'value' => $id];
    }

    public static function variantGid(int $productId): string
    {
        return 'gid://magento/ProductVariant/' . $productId;
    }
}
