<?php

namespace kernpfad\commercedoofinder\jobs;

use Craft;
use craft\queue\BaseJob;
use kernpfad\commercedoofinder\CommerceDoofinder;

/**
 * Upserts every variant item for one product. Payloads are built ahead of
 * time by {@see \kernpfad\commercedoofinder\services\CatalogSyncService}
 * from a real Product/Variant snapshot — this job only does network calls,
 * so a Doofinder failure here never affects the product save that queued it.
 */
class SyncProductItemsJob extends BaseJob
{
    public string $productTitle = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $variantPayloads = [];

    public function execute($queue): void
    {
        $client = CommerceDoofinder::getInstance()?->getDoofinderClient();

        if ($client === null) {
            Craft::warning('Commerce Doofinder: skipped item sync, not fully configured.', __METHOD__);

            return;
        }

        $total = count($this->variantPayloads);
        $done = 0;

        foreach ($this->variantPayloads as $payload) {
            try {
                $client->upsertItem($payload);
            } catch (\Throwable $e) {
                Craft::error("Commerce Doofinder: failed to sync item \"{$payload['id']}\" for \"{$this->productTitle}\": {$e->getMessage()}", __METHOD__);

                throw $e;
            }

            $this->setProgress($queue, ++$done / max($total, 1));
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('commerce-doofinder', 'Syncing "{title}" to the Doofinder index', ['title' => $this->productTitle]);
    }
}
