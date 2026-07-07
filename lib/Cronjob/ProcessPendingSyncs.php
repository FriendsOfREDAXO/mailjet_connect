<?php

declare(strict_types=1);

namespace FriendsOfREDAXO\MailjetConnect\Cronjob;

use FriendsOfREDAXO\MailjetConnect\EventStore;
use FriendsOfREDAXO\MailjetConnect\YFormSync;
use rex_addon;
use rex_cronjob;

final class ProcessPendingSyncs extends rex_cronjob
{
    public function execute(): bool
    {
        $processedCount = (new YFormSync())->processStoredEvents(200);
        $keepDays = (int) rex_addon::get('mailjet_connect')->getConfig('keep_raw_payload_days', 180);
        $clearedPayloads = (new EventStore())->clearOldPayloads($keepDays);

        $this->setMessage('Verarbeitete Mailjet-Syncs: ' . $processedCount . ', bereinigte Rohdaten: ' . $clearedPayloads);

        return true;
    }

    public function getTypeName(): string
    {
        return 'Mailjet Connect: ausstehende Syncs verarbeiten';
    }
}
