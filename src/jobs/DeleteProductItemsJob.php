<?php

namespace fipschen95\commercedoofinder\jobs;

use Craft;
use craft\queue\BaseJob;
use fipschen95\commercedoofinder\CommerceDoofinder;

/**
 * Deletes every variant item for one deleted product. Delete is
 * 404-tolerant in {@see \fipschen95\commercedoofinder\services\DoofinderClient::deleteItem()},
 * so an item that was never synced (e.g. it was disabled/unpublished the
 * whole time) doesn't turn a product delete into a failed job.
 */
class DeleteProductItemsJob extends BaseJob
{
    public string $productTitle = '';

    /**
     * @var string[]
     */
    public array $variantIds = [];

    public function execute($queue): void
    {
        $client = CommerceDoofinder::getInstance()?->getDoofinderClient();

        if ($client === null) {
            Craft::warning('Commerce Doofinder: skipped item deletion, not fully configured.', __METHOD__);

            return;
        }

        $total = count($this->variantIds);
        $done = 0;

        foreach ($this->variantIds as $variantId) {
            try {
                $client->deleteItem($variantId);
            } catch (\Throwable $e) {
                Craft::error("Commerce Doofinder: failed to delete item \"{$variantId}\" for \"{$this->productTitle}\": {$e->getMessage()}", __METHOD__);

                throw $e;
            }

            $this->setProgress($queue, ++$done / max($total, 1));
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('commerce-doofinder', 'Removing "{title}" from the Doofinder index', ['title' => $this->productTitle]);
    }
}
