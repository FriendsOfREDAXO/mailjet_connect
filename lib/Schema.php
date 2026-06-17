<?php

declare(strict_types=1);

namespace FriendsOfREDAXO\MailjetConnect;

use rex;
use rex_sql_column;
use rex_sql_index;
use rex_sql_table;

final class Schema
{
    public static function ensureEventTable(): void
    {
        $table = rex_sql_table::get(rex::getTable('mailjet_connect_event'));

        $table
            ->ensureColumn(new rex_sql_column('id', 'int(10) unsigned', false, null, 'auto_increment'))
            ->ensureColumn(new rex_sql_column('event_hash', 'varchar(64)', false, ''))
            ->ensureColumn(new rex_sql_column('event_type', 'varchar(32)', false, ''))
            ->ensureColumn(new rex_sql_column('event_time', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new rex_sql_column('email', 'varchar(255)', false, ''))
            ->ensureColumn(new rex_sql_column('message_id', 'varchar(64)', false, ''))
            ->ensureColumn(new rex_sql_column('message_guid', 'varchar(64)', false, ''))
            ->ensureColumn(new rex_sql_column('custom_id', 'varchar(255)', false, ''))
            ->ensureColumn(new rex_sql_column('blocked', 'tinyint(1)', false, '0'))
            ->ensureColumn(new rex_sql_column('hard_bounce', 'tinyint(1)', false, '0'))
            ->ensureColumn(new rex_sql_column('error_related_to', 'varchar(64)', false, ''))
            ->ensureColumn(new rex_sql_column('error_message', 'text', true))
            ->ensureColumn(new rex_sql_column('comment', 'text', true))
            ->ensureColumn(new rex_sql_column('smtp_reply', 'text', true))
            ->ensureColumn(new rex_sql_column('payload_json', 'mediumtext', true))
            ->ensureColumn(new rex_sql_column('sync_status', 'varchar(32)', false, 'unknown'))
            ->ensureColumn(new rex_sql_column('sync_message', 'text', true))
            ->ensureColumn(new rex_sql_column('sync_attempts', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new rex_sql_column('sync_processed_at', 'datetime', true))
            ->ensureColumn(new rex_sql_column('createdate', 'datetime', false, 'CURRENT_TIMESTAMP'))
            ->ensureColumn(new rex_sql_column('updatedate', 'datetime', false, 'CURRENT_TIMESTAMP'))
            ->ensurePrimaryIdColumn()
            ->ensureIndex(new rex_sql_index('event_hash', ['event_hash'], rex_sql_index::UNIQUE))
            ->ensureIndex(new rex_sql_index('event_type', ['event_type']))
            ->ensureIndex(new rex_sql_index('email', ['email']))
            ->ensureIndex(new rex_sql_index('event_time', ['event_time']))
            ->ensureIndex(new rex_sql_index('sync_status', ['sync_status']))
            ->ensure();
    }
}
