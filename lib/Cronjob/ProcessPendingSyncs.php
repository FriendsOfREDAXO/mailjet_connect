<?php

declare(strict_types=1);

namespace FriendsOfREDAXO\MailjetConnect\Cronjob;

use FriendsOfREDAXO\MailjetConnect\YFormSync;
use rex_cronjob;

final class ProcessPendingSyncs extends rex_cronjob
{
    public function execute(): bool
    {
        $processedCount = (new YFormSync())->processStoredEvents(200);
        $this->setMessage('Verarbeitete Mailjet-Syncs: ' . $processedCount);

        return true;
    }

    public function getTypeName(): string
    {
        return 'Mailjet Connect: ausstehende Syncs verarbeiten';
    }
}
