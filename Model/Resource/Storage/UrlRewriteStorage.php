<?php

namespace BigBridge\ProductImport\Model\Resource\Storage;

use BigBridge\ProductImport\Api\Data\Product;
use BigBridge\ProductImport\Model\Data\UrlRewriteInfo;
use BigBridge\ProductImport\Model\Persistence\Magento2DbConnection;
use BigBridge\ProductImport\Model\Resource\MetaData;
use BigBridge\ProductImport\Model\Resource\Serialize\ValueSerializer;

/**
 * @author Patrick van Bergen
 */
class UrlRewriteStorage
{
    /** @var  Magento2DbConnection */
    protected $db;

    /** @var  MetaData */
    protected $metaData;

    public function __construct(
        Magento2DbConnection $db,
        MetaData $metaData)
    {
        $this->db = $db;
        $this->metaData = $metaData;
    }

    public function updateRewrites(array $products, array $existingValues, ValueSerializer $valueSerializer)
    {
        $changedProducts = $this->getChangedProducts($products, $existingValues);

        $newRewriteValues = $this->getNewRewriteValues($changedProducts);

        $this->rewriteExistingRewrites($newRewriteValues, $valueSerializer);
    }

    public function getExistingProductValues(array $products)
    {
        if (empty($products)) {
            return [];
        }

        $productIds = array_column($products, 'id');

        $attributeId = $this->metaData->productEavAttributeInfo['url_key']->attributeId;

        $existingData = $this->db->fetchAllAssoc("
            SELECT URL_KEY.`entity_id` as product_id, URL_KEY.`value` AS url_key, GROUP_CONCAT(PG.`category_id` SEPARATOR ',') as category_ids, URL_KEY.`store_id`
            FROM `{$this->metaData->productEntityTable}_varchar` URL_KEY
            LEFT JOIN `{$this->metaData->urlRewriteProductCategoryTable}` PG ON PG.`product_id` = URL_KEY.`entity_id`
            WHERE 
                URL_KEY.`attribute_id` = ? AND
                URL_KEY.`entity_id` IN (" . $this->db->getMarks($productIds) . ")
            GROUP BY URL_KEY.`entity_id`, URL_KEY.`store_id` 
        ", array_merge([
            $attributeId
        ], $productIds));

        $data = [];
        foreach ($existingData as $existingDatum) {
            $productId = $existingDatum['product_id'];
            $storeId = $existingDatum['store_id'];
            $categoryIds = is_null($existingDatum['category_ids']) ? [] : explode(',', $existingDatum['category_ids']);
            $urlKey = $existingDatum['url_key'];
            $data[$storeId][$productId] = ['url_key' => $urlKey, 'category_ids' => $categoryIds];
        }

        return $data;
    }

    /**
     * @param Product[] $products
     * @param array $existingValues
     * @return array
     */
    protected function getChangedProducts(array $products, array $existingValues)
    {
        if (empty($products)) {
            return [];
        }

        $changedProducts = [];
        foreach ($products as $product) {
            foreach ($product->getStoreViews() as $storeView) {

                $storeViewId = $storeView->getStoreViewId();

                if (array_key_exists($storeViewId, $existingValues) && array_key_exists($product->id, $existingValues[$storeViewId])) {

                    $existingDatum = $existingValues[$storeViewId][$product->id];

                    // a product has changed if its url_key or its categories change
                    if ($storeView->getUrlKey() != $existingDatum['url_key']) {
                        $changedProducts[] = $product;
                    } elseif (array_diff($product->getCategoryIds(), $existingDatum['category_ids']) || array_diff($existingDatum['category_ids'], $product->getCategoryIds())) {
                        $changedProducts[] = $product;
                    }

                } else {
                    $changedProducts[] = $product;
                }
            }
        }

        return $changedProducts;
    }

    /**
     * @param UrlRewriteInfo[] $urlRewrites
     * @param ValueSerializer $valueSerializer
     * @return array
     */
    protected function getExistingUrlRewriteData(array $urlRewrites, ValueSerializer $valueSerializer)
    {
        // prepare information of existing rewrites
        $productIds = [];
        foreach ($urlRewrites as $urlRewriteInfo) {
            $productIds[$urlRewriteInfo->storeId][$urlRewriteInfo->productId] = $urlRewriteInfo->productId;
        }

        $data = [];
        foreach ($productIds as $storeId => $ids) {

            $oldUrlRewrites = $this->db->fetchAllAssoc("
                SELECT `url_rewrite_id`, `entity_id`, `request_path`, `target_path`, `redirect_type`, `metadata`
                FROM `{$this->metaData->urlRewriteTable}`
                WHERE
                    store_id = ? AND `entity_id` IN (" . $this->db->getMarks($ids) . ")
            ", array_merge([
                $storeId
            ], $ids));

            foreach ($oldUrlRewrites as $oldUrlRewrite) {

                $categoryId = $valueSerializer->extract($oldUrlRewrite['metadata'], 'category_id');
                $key = $oldUrlRewrite['entity_id'] . '/' . $categoryId;

                $data[$storeId][$key][] = $oldUrlRewrite;
            }
        }

        return $data;
    }

    protected function getNewRewriteValues(array $products): array
    {
        if (empty($products)) {
            return [];
        }

        // all store view ids, without 0
        $allStoreIds = array_diff($this->metaData->storeViewMap, ['0']);

        $productIds = array_column($products, 'id');
        $attributeId = $this->metaData->productEavAttributeInfo['url_key']->attributeId;

        $results = $this->db->fetchAllAssoc("
            SELECT `entity_id`, `store_id`, `value` AS `url_key`
            FROM `{$this->metaData->productEntityTable}_varchar`
            WHERE
                `attribute_id` = ? AND
                `entity_id` IN (" . $this->db->getMarks($productIds) . ")
        ", array_merge([$attributeId], $productIds));

        $urlKeys = [];
        foreach ($results as $result) {
            $productId = $result['entity_id'];
            $storeId = $result['store_id'];
            $urlKey = $result['url_key'];

            if ($storeId == 0) {
                // insert url key to all store views
                foreach ($allStoreIds as $aStoreId) {
                    // but do not overwrite explicit assignments
                    if (!array_key_exists($productId, $urlKeys) || !array_key_exists($aStoreId, $urlKeys[$productId])) {
                        $urlKeys[$productId][$aStoreId] = $urlKey;
                    }
                }
            } else {
                $urlKeys[$productId][$storeId] = $urlKey;
            }
        }

        // category ids per product
        $categoryIds = [];
        $results = $this->db->fetchAllAssoc("
            SELECT `product_id`, `category_id`
            FROM `{$this->metaData->categoryProductTable}`
            WHERE
                `product_id` IN (" . $this->db->getMarks($productIds) .")
        ", $productIds);

        $rewriteValues = [];
        foreach ($results as $result) {
            $categoryIds[$result['product_id']][$result['category_id']] = $result['category_id'];
        }

        foreach ($urlKeys as $productId => $urlKeyData) {
            foreach ($urlKeyData as $storeId => $urlKey) {

                $shortUrl = $urlKey . $this->metaData->productUrlSuffix;

                // url keys without categories
                $requestPath = $shortUrl;
                $targetPath = 'catalog/product/view/id/' . $productId;
                $rewriteValues[] = new UrlRewriteInfo($productId, $requestPath, $targetPath, 0, $storeId, null, 1);

                if (!array_key_exists($productId, $categoryIds)) {
                    continue;
                }

                // url keys with categories
                foreach ($categoryIds[$productId] as $directCategoryId) {

                    // here we check if the category id supplied actually exists
                    if (!array_key_exists($directCategoryId, $this->metaData->allCategoryInfo)) {
                        continue;
                    }

                    $path = "";
                    foreach ($this->metaData->allCategoryInfo[$directCategoryId]->path as $i => $parentCategoryId) {

                        // the root category is not used for the url path
                        if ($i === 0) {
                            continue;
                        }

                        $categoryInfo = $this->metaData->allCategoryInfo[$parentCategoryId];

                        // take the url_key from the store view, or default to the global url_key
                        $urlKey = array_key_exists($storeId, $categoryInfo->urlKeys) ? $categoryInfo->urlKeys[$storeId] : $categoryInfo->urlKeys[0];

                        $path .= $urlKey . "/";

                        $requestPath = $path . $shortUrl;
                        $targetPath = 'catalog/product/view/id/' . $productId . '/category/' . $parentCategoryId;
                        $metadata = ['category_id' => (string)$parentCategoryId];

                        $rewriteValues[] = new UrlRewriteInfo($productId, $requestPath, $targetPath, 0, $storeId, $metadata, 1);
                    }
                }
            }
        }

        return $rewriteValues;
    }

    /**
     * @param UrlRewriteInfo[] $urlRewrites
     * @param ValueSerializer $valueSerializer
     */
    protected function rewriteExistingRewrites(array $urlRewrites, ValueSerializer $valueSerializer)
    {
        if (empty($urlRewrites)) {
            return;
        }

        $existingUrlRewriteData = $this->getExistingUrlRewriteData($urlRewrites, $valueSerializer);

        $updatedRewrites = [];
        $oldRewriteIds = [];
        foreach ($urlRewrites as $urlRewriteInfo) {

            // distinct store_id, product_id, metadata

            $categoryId = empty($urlRewriteInfo->metadata) ? null : $urlRewriteInfo->metadata['category_id'];
            $key = $urlRewriteInfo->productId . '/' . $categoryId;

            if (!array_key_exists($urlRewriteInfo->storeId, $existingUrlRewriteData) || ! array_key_exists($key, $existingUrlRewriteData[$urlRewriteInfo->storeId])) {
                continue;
            }

            $oldUrlRewrites = $existingUrlRewriteData[$urlRewriteInfo->storeId][$key];

            // multiple old rewrites with matching store_id, product_id, metadata

            foreach ($oldUrlRewrites as $oldRewrite) {

                $oldRewriteId = $oldRewrite['url_rewrite_id'];
                $oldRequestPath = $oldRewrite['request_path'];
                $oldRedirectType = $oldRewrite['redirect_type'];

                $oldRewriteIds[] = $oldRewriteId;

                $updatedRedirectType = '301';

                if ($oldRedirectType == '0') {

                    if (!$this->metaData->saveRewritesHistory) {
                        // no history: ignore the existing entry
                        continue;
                    }

                }

                if ($oldRequestPath === $urlRewriteInfo->requestPath) {
                    // a redirect should not redirect to itself
                    continue;
                }

                $updatedTargetPath = $urlRewriteInfo->requestPath;

                // when a url rewrite changes to a redirect, its metadata always is a structure
                $metadata = $urlRewriteInfo->metadata;
                if ($metadata === null) {
                    $metadata = [];
                }

                // autogenerated changes to 0 for redirects
                $autogenerated = 0;

                // group rewrites
                // Note: this array grows to big that PHP "holds" 65K for it for a longer amount of time, this shows up in memory_get_usage
                $updatedRewrites[] =
                    new UrlRewriteInfo($urlRewriteInfo->productId, $oldRequestPath, $updatedTargetPath, $updatedRedirectType, $urlRewriteInfo->storeId, $metadata, $autogenerated);
            }
        }

        $this->db->deleteMultiple($this->metaData->urlRewriteTable, 'url_rewrite_id', $oldRewriteIds);

        $this->writeUrlRewrites($urlRewrites, $valueSerializer, true);

        $this->writeUrlRewrites($updatedRewrites, $valueSerializer, false);
    }

    /**
     * @param UrlRewriteInfo[] $urlRewrites
     * @param ValueSerializer $valueSerializer
     * @param $buildIndex
     */
    protected function writeUrlRewrites(array $urlRewrites, ValueSerializer $valueSerializer, $buildIndex)
    {
        if (empty($urlRewrites)) {
            return;
        }

        $newRewriteValues = [];
        foreach ($urlRewrites as $urlRewrite) {

            $metadata = $urlRewrite->metadata === null ? null : $valueSerializer->serialize($urlRewrite->metadata);

            $newRewriteValues[] = 'product';
            $newRewriteValues[] = $urlRewrite->productId;
            $newRewriteValues[] = $urlRewrite->requestPath;
            $newRewriteValues[] = $urlRewrite->targetPath;
            $newRewriteValues[] = $urlRewrite->redirectType;
            $newRewriteValues[] = $urlRewrite->storeId;
            $newRewriteValues[] = $urlRewrite->autogenerated;
            $newRewriteValues[] = $metadata;
        }

        // add new values
        // IGNORE works on the key request_path, store_id
        // when this combination already exists, it is ignored
        // this may happen if a main product is followed by one of its store views
        $this->db->insertMultipleWithIgnore(
            $this->metaData->urlRewriteTable,
            ['entity_type', 'entity_id', 'request_path', 'target_path', 'redirect_type', 'store_id', 'is_autogenerated', 'metadata'],
            $newRewriteValues,
            Magento2DbConnection::_2_KB);

        if ($buildIndex) {

            // the last insert id is guaranteed to be the first id generated
            $insertId = $this->db->getLastInsertId();

            if ($insertId != 0) {

                // the SUBSTRING_INDEX extracts the category id from the target_path
                $this->db->execute("
                    INSERT INTO `{$this->metaData->urlRewriteProductCategoryTable}` (`url_rewrite_id`, `category_id`, `product_id`)
                    SELECT `url_rewrite_id`, SUBSTRING_INDEX(`target_path`, '/', -1), `entity_id`
                    FROM `{$this->metaData->urlRewriteTable}`
                    WHERE 
                        `url_rewrite_id` >= ? AND
                        `target_path` LIKE '%/category/%' 
                ", [
                    $insertId
                ]);
            }
        }
    }
}

