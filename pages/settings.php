<?php

declare(strict_types=1);

/** @var rex_addon $this */

$addon = rex_addon::get('mailjet_connect');
$smtpToken = rex_csrf_token::factory('mailjet_connect_show_smtp');
$applySmtpToken = rex_csrf_token::factory('mailjet_connect_apply_smtp_phpmailer');

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

$smtpHost = 'in-v3.mailjet.com';
$smtpPort = 587;
$smtpSecurity = 'tls';
$smtpUser = trim((string) $addon->getConfig('api_key', ''));
$smtpPass = trim((string) $addon->getConfig('api_secret', ''));
$showSmtpSettings = rex_post('show_smtp_settings', 'bool', false) && $smtpToken->isValid();

if (rex_post('apply_smtp_to_phpmailer', 'bool', false)) {
    if (!$applySmtpToken->isValid()) {
        echo rex_view::warning($addon->i18n('mailjet_connect_csrf_failed'));
    } else {
        $phpMailerAddon = rex_addon::get('phpmailer');
        if (!$phpMailerAddon->isAvailable()) {
            echo rex_view::warning($addon->i18n('mailjet_connect_smtp_apply_phpmailer_missing'));
        } elseif ('' === $smtpUser || '' === $smtpPass) {
            echo rex_view::warning($addon->i18n('mailjet_connect_smtp_apply_credentials_missing'));
        } else {
            $phpMailerConfig = (array) $phpMailerAddon->getConfig();
            $phpMailerConfig['mailer'] = 'smtp';
            $phpMailerConfig['host'] = $smtpHost;
            $phpMailerConfig['port'] = $smtpPort;
            $phpMailerConfig['smtpsecure'] = $smtpSecurity;
            $phpMailerConfig['security_mode'] = false;
            $phpMailerConfig['smtpauth'] = true;
            $phpMailerConfig['username'] = $smtpUser;
            $phpMailerConfig['password'] = $smtpPass;
            $phpMailerAddon->setConfig($phpMailerConfig);

            echo rex_view::success($addon->i18n('mailjet_connect_smtp_apply_success'));
        }
    }
}

if (rex_post('show_smtp_settings', 'bool', false) && !$smtpToken->isValid()) {
    echo rex_view::warning($addon->i18n('mailjet_connect_csrf_failed'));
}

$smtpContent = '<p class="help-block">' . rex_escape($addon->i18n('mailjet_connect_smtp_hint')) . '</p>';
$smtpContent .= '<form method="post" style="margin-bottom:10px">';
$smtpContent .= $smtpToken->getHiddenField();
$smtpContent .= '<input type="hidden" name="show_smtp_settings" value="1">';
$smtpContent .= '<button type="submit" class="btn btn-default">' . rex_escape($addon->i18n('mailjet_connect_smtp_show_button')) . '</button>';
$smtpContent .= '</form>';

$smtpContent .= '<form method="post" style="margin-bottom:10px">';
$smtpContent .= $applySmtpToken->getHiddenField();
$smtpContent .= '<input type="hidden" name="apply_smtp_to_phpmailer" value="1">';
$smtpContent .= '<button type="submit" class="btn btn-primary" data-confirm="' . rex_escape($addon->i18n('mailjet_connect_smtp_apply_confirm')) . '">' . rex_escape($addon->i18n('mailjet_connect_smtp_apply_button')) . '</button>';
$smtpContent .= '</form>';

if ($showSmtpSettings) {
    $smtpConfigText = "Host: {$smtpHost}\n"
        . "Port: {$smtpPort}\n"
        . "SMTPSecure: {$smtpSecurity} (STARTTLS)\n"
        . "SMTPAuth: true\n"
        . "Username: {$smtpUser}\n"
        . "Password: {$smtpPass}";

    $smtpContent .= '<div class="alert alert-info">' . rex_escape($addon->i18n('mailjet_connect_smtp_credentials_notice')) . '</div>';
    $smtpContent .= '<textarea class="form-control" rows="8" readonly>' . rex_escape($smtpConfigText) . '</textarea>';
}

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $addon->i18n('mailjet_connect_settings'), false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');

$smtpFragment = new rex_fragment();
$smtpFragment->setVar('class', 'edit', false);
$smtpFragment->setVar('title', $addon->i18n('mailjet_connect_smtp_title'), false);
$smtpFragment->setVar('body', $smtpContent, false);
echo $smtpFragment->parse('core/page/section.php');
