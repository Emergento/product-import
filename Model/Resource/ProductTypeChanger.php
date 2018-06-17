<?php

namespace BigBridge\ProductImport\Model\Resource;

use BigBridge\ProductImport\Api\Data\BundleProduct;
use BigBridge\ProductImport\Api\Data\ConfigurableProduct;
use BigBridge\ProductImport\Api\Data\DownloadableProduct;
use BigBridge\ProductImport\Api\Data\GroupedProduct;
use BigBridge\ProductImport\Api\Data\Product;
use BigBridge\ProductImport\Api\Data\SimpleProduct;
use BigBridge\ProductImport\Api\Data\VirtualProduct;
use BigBridge\ProductImport\Api\ImportConfig;
use BigBridge\ProductImport\Model\Data\Placeholder;
use BigBridge\ProductImport\Model\Persistence\Magento2DbConnection;
use BigBridge\ProductImport\Model\Resource\Storage\BundleStorage;
use BigBridge\ProductImport\Model\Resource\Storage\ConfigurableStorage;
use BigBridge\ProductImport\Model\Resource\Storage\DownloadableStorage;
use BigBridge\ProductImport\Model\Resource\Storage\GroupedStorage;

/**
 * @author Patrick van Bergen
 */
class ProductTypeChanger
{
    /**  @var Magento2DbConnection */
    protected $db;

    /** @var MetaData */
    protected $metaData;

    /** @var ConfigurableStorage */
    protected $configurableStorage;

    /** @var GroupedStorage */
    protected $groupedStorage;

    /** @var BundleStorage */
    protected $bundleStorage;

    /** @var DownloadableStorage */
    protected $downloadableStorage;

    public function __construct(
        Magento2DbConnection $db,
        MetaData $metaData,
        ConfigurableStorage $configurableStorage,
        GroupedStorage $groupedStorage,
        BundleStorage $bundleStorage,
        DownloadableStorage $downloadableStorage)
    {
        $this->db = $db;
        $this->metaData = $metaData;
        $this->groupedStorage = $groupedStorage;
        $this->bundleStorage = $bundleStorage;
        $this->configurableStorage = $configurableStorage;
        $this->downloadableStorage = $downloadableStorage;
    }

    public function handleTypeChanges(array $updatedProducts, ImportConfig $config)
    {
        if (empty($updatedProducts)) {
            return;
        }

        $productIds = array_column($updatedProducts, 'id');

        $oldTypes = $this->db->fetchMap("
            SELECT `entity_id`, `type_id`
            FROM `" . $this->metaData->productEntityTable . "`
            WHERE `entity_id` IN (" . implode(',', $productIds) . ")
        ");

        foreach ($updatedProducts as $product) {

            if (!array_key_exists($product->id, $oldTypes)) {
                continue;
            }

            $oldType = $oldTypes[$product->id];
            $newType = $product->getType();

            if ($oldType !== $newType) {
                $this->convertProductType($product, $oldType, $config);
            }
        }
    }

    protected function convertProductType(Product $product, string $oldType, ImportConfig $config)
    {
        $newType = $product->getType();

        if ($config->productTypeChange === ImportConfig::PRODUCT_TYPE_CHANGE_FORBIDDEN) {

            // if this is a placeholder product, that is only replaced in a later batch, allow it
            if ($product->global()->getName() !== Placeholder::PLACEHOLDER_NAME) {

                $product->addError("Type conversion is not allowed");
                return;
            }
        }

        if ($config->productTypeChange === ImportConfig::PRODUCT_TYPE_CHANGE_NON_DESTRUCTIVE) {
            if (in_array($oldType, [
                GroupedProduct::TYPE_GROUPED,
                BundleProduct::TYPE_BUNDLE,
                ConfigurableProduct::TYPE_CONFIGURABLE,
                DownloadableProduct::TYPE_DOWNLOADABLE
            ])) {
                $product->addError("Type conversion losing data from {$oldType} to {$newType} is not allowed");
                return;
            }
        }

        if ($config->productTypeChange === ImportConfig::PRODUCT_TYPE_CHANGE_NON_DESTRUCTIVE) {
            if (in_array($newType, [VirtualProduct::TYPE_VIRTUAL])) {
                $product->addError("Type conversion losing data from {$oldType} to {$newType} is not allowed");
                return;
            }
        }

        // remove data from the old type
        switch ($oldType) {
            case SimpleProduct::TYPE_SIMPLE:
            case VirtualProduct::TYPE_VIRTUAL:
                break;
            case DownloadableProduct::TYPE_DOWNLOADABLE:
                $this->downloadableStorage->removeLinksAndSamples([$product]);
                break;
            case GroupedProduct::TYPE_GROUPED:
                $this->groupedStorage->removeLinkedProducts([$product]);
                break;
            case BundleProduct::TYPE_BUNDLE:
                $this->bundleStorage->removeOptions([$product]);
                break;
            case ConfigurableProduct::TYPE_CONFIGURABLE:
                $this->configurableStorage->removeLinkedVariants([$product]);
                break;
            default:
                $product->addError("Type conversion from {$oldType} to {$newType} is not supported");
        }

        // prepare for the new type
        switch ($newType) {
            case VirtualProduct::TYPE_VIRTUAL:
                foreach ($product->getStoreViews() as $storeView) {
                    $storeView->setWeight(null);
                }
                break;
        }
    }
}