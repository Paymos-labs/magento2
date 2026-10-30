<?php

declare(strict_types=1);

// Run only on an approved isolated store. This file never boots a local Magento host.
$cases = [
    'install_upgrade' => 'Fresh install and upgrade retain invoice history, intent identity and unique native marker; setup:di:compile succeeds.',
    'protected_full_entry' => 'Actual credit memo Save: ACL/form key/order/invoice checks precede intent commit and provider HTTP; pending has no credit memo.',
    'managed_overpayment' => 'Actual Paymos panel: 120/100 returns 20; one note, refunded=0, paid state and stock unchanged.',
    'partial_manual_refusal' => 'Partial items, adjusted shipping/total, previous manual credit memo and direct REST/service bypass cause zero provider POST.',
    'ordered_over_then_full' => 'After over20 Completed, full100 creates exactly one offline original-total credit memo with saved tax/items/stock choices.',
    'native_atomicity' => 'Kill before/after native commit; stock/voucher and marker use the same sales connection; restart inspects exact document.',
    'reorg_recompletion' => 'Over rewind while full is pending, then full rewind/recompletion: historical document stays, no repeated stock, credit or provider POST.',
    'mail_lost_ack' => 'SMTP accepted then death before acknowledgement: committed started becomes unknown, cron and callback never resend.',
    'scoped_callback_recovery' => 'Signed dedicated endpoint scope, both environment keys, project mismatch, lost callback and callback-before-binding use fresh primary GETs.',
    'other_gateway' => 'Unrelated payment methods retain native Save and ordinary credit memo email.',
];

$root = isset($argv[1]) ? realpath($argv[1]) : false;
$installed = $root !== false && is_file($root . '/app/bootstrap.php') && is_file($root . '/vendor/autoload.php');
$reason = $installed
    ? 'Native store path found; no approved isolated scenario driver or executed package evidence supplied. No native assertions executed.'
    : 'Magento native package, Composer vendor and an approved isolated store fixture are unavailable. Unit doubles are not native proof.';
$results = [];
foreach ($cases as $id => $expected) {
    $results[] = ['case' => $id, 'status' => 'skipped', 'reason' => $reason, 'expected' => $expected];
}
echo json_encode([
    'fixture_contract' => 1,
    'scenario' => 'cms-magento',
    'php' => PHP_VERSION,
    'native_package_version' => null,
    'native_certified' => false,
    'required_evidence' => ['exact Magento and module-sales package versions', 'source hashes for CreditmemoService, Save, CreditmemoSender',
        'real admin session and dedicated sandbox project with invoice_refunds_v1', 'original paid order/invoice and item/tax/stock snapshots',
        'HTTP requests and verified GETs without secrets', 'connection/engine proof for every native effect',
        'before/after native SQL and operation/withdrawal/creditmemo IDs', 'process-death and SMTP acceptance evidence'],
    'passed' => 0, 'failed' => 0, 'skipped' => count($results), 'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
