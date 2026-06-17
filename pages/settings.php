<?php

declare(strict_types=1);

/** @var rex_addon $this */

$addon = rex_addon::get('mailjet_connect');

$webhookPath = rex_url::frontendController(['rex-api-call' => 'mailjet_connect_webhook'], false);
$webhookUrl = rtrim(rex::getServer(), '/') . '/' . ltrim($webhookPath, './');
$webhookUsername = (string) $addon->getConfig('webhook_username', '');
$webhookPassword = (string) $addon->getConfig('webhook_password', '');
$webhookToken = (string) $addon->getConfig('webhook_token', '');

if ('' !== $webhookUsername || '' !== $webhookPassword) {
    $webhookUrl = preg_replace('#^https?://#', 'https://' . rawurlencode($webhookUsername) . ':' . rawurlencode($webhookPassword) . '@', $webhookUrl);
}

if ('' !== $webhookToken) {
    $separator = str_contains($webhookUrl, '?') ? '&' : '?';
    $webhookUrl .= $separator . 'token=' . rawurlencode($webhookToken);
}

$webhookEventsConfig = $addon->getConfig('webhook_events', ['bounce', 'blocked', 'spam', 'sent']);
$webhookEventsValue = is_array($webhookEventsConfig)
    ? implode(',', $webhookEventsConfig)
    : trim((string) $webhookEventsConfig);

$form = rex_config_form::factory($addon->getName());

$form->addFieldset($addon->i18n('mailjet_connect_credentials'));

$field = $form->addTextField('api_key');
$field->setLabel($addon->i18n('mailjet_connect_api_key'));

$field = $form->addTextField('api_secret');
$field->setAttribute('type', 'password');
$field->setLabel($addon->i18n('mailjet_connect_api_secret'));

$form->addFieldset($addon->i18n('mailjet_connect_webhook'));

$field = $form->addTextField('webhook_username');
$field->setLabel($addon->i18n('mailjet_connect_webhook_username'));

$field = $form->addTextField('webhook_password');
$field->setAttribute('type', 'password');
$field->setLabel($addon->i18n('mailjet_connect_webhook_password'));

$field = $form->addTextField('webhook_token');
$field->setLabel($addon->i18n('mailjet_connect_webhook_token'));
$field->setNotice($addon->i18n('mailjet_connect_webhook_token_notice'));

$field = $form->addTextAreaField('webhook_events');
$field->setLabel($addon->i18n('mailjet_connect_webhook_events'));
$field->setNotice($addon->i18n('mailjet_connect_webhook_notice'));
$field->setAttribute('rows', '3');
$field->setValue($webhookEventsValue);

$field = $form->addReadOnlyField('webhook_url_preview');
$field->setLabel($addon->i18n('mailjet_connect_webhook_url'));
$field->setValue($webhookUrl);

$form->addFieldset($addon->i18n('mailjet_connect_runtime'));

$field = $form->addInputField('text', 'keep_raw_payload_days');
$field->setAttribute('type', 'number');
$field->setAttribute('min', '1');
$field->setLabel($addon->i18n('mailjet_connect_retention_days'));

$field = $form->addSelectField('enabled');
$field->setLabel($addon->i18n('mailjet_connect_enabled'));
$select = $field->getSelect();
$select->addOption($addon->i18n('mailjet_connect_enabled_yes'), '1');
$select->addOption($addon->i18n('mailjet_connect_enabled_no'), '0');

if (rex_post('config-submit', 'bool', false)) {
    $postedConfig = rex_post('config', 'array', []);
    $postedWebhookEvents = isset($postedConfig['webhook_events']) ? (string) $postedConfig['webhook_events'] : '';
    $events = array_filter(array_map('trim', preg_split('/[\s,]+/', $postedWebhookEvents, -1, PREG_SPLIT_NO_EMPTY) ?: []));
    $addon->setConfig('webhook_events', array_values($events));
}

$content = $form->get();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $addon->i18n('mailjet_connect_settings'), false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
