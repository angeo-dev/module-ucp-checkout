<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Turns a UCP item id into what Quote::addProduct() needs.
 *
 * Same visibility rules as the storefront: enabled, assigned to the
 * website, visible — except a configurable's child, which is reached
 * through its parent (the quote then holds a normal configurable item,
 * exactly as if the shopper had picked the options). Hidden products answer
 * like missing ones, so the endpoint cannot be used to probe the catalog.
 */
class ItemResolver
{
    private const BUYABLE_TYPES = [ProductType::TYPE_SIMPLE, ProductType::TYPE_VIRTUAL];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ConfigurableResource $configurableResource,
        private readonly ItemReference $itemReference
    ) {
    }

    /**
     * @return array{product: Product, buy_request: array<string, mixed>, variant_id: int}
     * @throws ItemException
     */
    public function resolve(mixed $itemId, int $qty, int $storeId, int $websiteId): array
    {
        $ref = $this->itemReference->parse($itemId);
        if ($ref === null) {
            throw new ItemException('invalid', 'Item id is missing or not a known format.');
        }

        $product = $this->load($ref, $storeId);
        if ($product === null || !$this->isSellable($product, $websiteId)) {
            throw new ItemException('item_unavailable', 'This item is not available.');
        }

        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            throw new ItemException(
                'variant_required',
                'This product has options. Use a variant id (gid://magento/ProductVariant/…) from catalog lookup.'
            );
        }
        if (!in_array($product->getTypeId(), self::BUYABLE_TYPES, true)) {
            throw new ItemException('item_unavailable', 'This product type is sold on the store website only.');
        }
        if ((bool) $product->getData('required_options')) {
            throw new ItemException('item_unavailable', 'This product needs options that are chosen on the store website.');
        }

        $parent = $this->findConfigurableParent($product, $storeId, $websiteId);
        if ($parent !== null) {
            return [
                'product'     => $parent,
                'buy_request' => ['qty' => $qty, 'super_attribute' => $this->superAttributes($parent, $product)],
                'variant_id'  => (int) $product->getId(),
            ];
        }

        if ((int) $product->getVisibility() === Visibility::VISIBILITY_NOT_VISIBLE) {
            throw new ItemException('item_unavailable', 'This item is not available.');
        }

        return [
            'product'     => $product,
            'buy_request' => ['qty' => $qty],
            'variant_id'  => (int) $product->getId(),
        ];
    }

    /**
     * @param array{kind: string, value: string} $ref
     */
    private function load(array $ref, int $storeId): ?Product
    {
        try {
            $product = $ref['kind'] === ItemReference::KIND_SKU
                ? $this->productRepository->get($ref['value'], false, $storeId)
                : $this->productRepository->getById((int) $ref['value'], false, $storeId);
        } catch (NoSuchEntityException) {
            return null;
        }
        return $product instanceof Product ? $product : null;
    }

    private function isSellable(Product $product, int $websiteId): bool
    {
        return (int) $product->getStatus() === Status::STATUS_ENABLED
            && in_array($websiteId, array_map('intval', (array) $product->getWebsiteIds()), true);
    }

    private function findConfigurableParent(Product $child, int $storeId, int $websiteId): ?Product
    {
        foreach ($this->configurableResource->getParentIdsByChild((int) $child->getId()) as $parentId) {
            try {
                $parent = $this->productRepository->getById((int) $parentId, false, $storeId);
            } catch (NoSuchEntityException) {
                continue;
            }
            if ($parent instanceof Product
                && $this->isSellable($parent, $websiteId)
                && (int) $parent->getVisibility() !== Visibility::VISIBILITY_NOT_VISIBLE
            ) {
                return $parent;
            }
        }
        return null;
    }

    /**
     * @return array<int, int> attribute id => option id, from the child's values
     */
    private function superAttributes(Product $parent, Product $child): array
    {
        /** @var Configurable $type */
        $type = $parent->getTypeInstance();
        $map = [];
        foreach ($type->getConfigurableAttributesAsArray($parent) as $attribute) {
            $code = (string) ($attribute['attribute_code'] ?? '');
            $value = $child->getData($code);
            if ($code !== '' && $value !== null && $value !== '') {
                $map[(int) $attribute['attribute_id']] = (int) $value;
            }
        }
        return $map;
    }
}
