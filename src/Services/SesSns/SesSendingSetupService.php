<?php

namespace Topoff\Messenger\Services\SesSns;

use Illuminate\Support\Arr;
use RuntimeException;
use Topoff\Messenger\Contracts\SesSnsProvisioningApi;

class SesSendingSetupService
{
    public function __construct(protected SesSnsProvisioningApi $api) {}

    /**
     * @return array{ok: bool, steps: array<int, array{label: string, ok: bool, details: string}>, dns_records: array<int, array{name: string, type: string, values: array<int, string>}>}
     */
    public function setup(): array
    {
        $steps = [];
        $dnsRecords = [];
        $identities = $this->identities();
        $configurationSets = $this->configurationSets();

        // 1. Loop each identity → create SES identity, configure MAIL FROM, build DNS records
        foreach ($identities as $identityKey => $identityConfig) {
            $identity = $this->resolveIdentity($identityConfig);
            $suffix = count($identities) > 1 ? " [{$identityKey}]" : '';
            $identityData = $this->api->getEmailIdentity($identity);
            $identityDnsRecords = [];

            if ($identityData === null) {
                $identityData = $this->api->createEmailIdentity($identity);
                $steps[] = ['label' => 'SES identity'.$suffix, 'ok' => true, 'details' => 'Created: '.$identity];
                $identityData = $this->api->getEmailIdentity($identity) ?? $identityData;
            } else {
                $steps[] = ['label' => 'SES identity'.$suffix, 'ok' => true, 'details' => 'Already exists: '.$identity];
            }

            foreach ($this->buildDnsRecords($identity, $identityData) as $record) {
                $identityDnsRecords[] = $record;
                $dnsRecords[] = $record;
            }

            $mailFromDomain = trim((string) ($identityConfig['mail_from_domain'] ?? ''));
            if ($mailFromDomain !== '') {
                $this->api->putEmailIdentityMailFromAttributes(
                    $identity,
                    $mailFromDomain,
                    $this->mailFromBehaviorOnMxFailure(),
                );
                $steps[] = ['label' => 'SES custom MAIL FROM'.$suffix, 'ok' => true, 'details' => 'Configured: '.$mailFromDomain];

                foreach ($this->buildMailFromDnsRecords($mailFromDomain) as $record) {
                    $identityDnsRecords[] = $record;
                    $dnsRecords[] = $record;
                }
            } else {
                $steps[] = ['label' => 'SES custom MAIL FROM'.$suffix, 'ok' => true, 'details' => 'Skipped (not configured).'];
            }

            $this->upsertDnsIfConfigured($identity, $identityDnsRecords, $steps);

            $verified = (bool) Arr::get($identityData, 'VerifiedForSendingStatus', false);
            $steps[] = [
                'label' => 'SES verification status'.$suffix,
                'ok' => true,
                'details' => $verified
                    ? 'Identity already verified for sending.'
                    : 'Identity not yet verified. Apply DNS records and wait for SES verification.',
            ];
        }

        // 2. Loop each config set → create config set
        foreach ($configurationSets as $key => $set) {
            $configSetName = $set['configuration_set'];
            $suffix = count($configurationSets) > 1 ? " [{$key}]" : '';

            if ($configSetName === '') {
                continue;
            }

            if (! $this->api->configurationSetExists($configSetName)) {
                $this->api->createConfigurationSet($configSetName);
                $steps[] = ['label' => 'SES configuration set'.$suffix, 'ok' => true, 'details' => 'Created: '.$configSetName];
            } else {
                $steps[] = ['label' => 'SES configuration set'.$suffix, 'ok' => true, 'details' => 'Already exists: '.$configSetName];
            }
        }

        // 3. Assign default config set per identity
        $assignedIdentities = [];
        foreach ($configurationSets as $set) {
            $configSetName = $set['configuration_set'];
            $identityKey = $set['identity'] ?? 'default';

            if ($configSetName === '' || isset($assignedIdentities[$identityKey])) {
                continue;
            }

            if (! isset($identities[$identityKey])) {
                continue;
            }

            $identity = $this->resolveIdentity($identities[$identityKey]);
            $this->api->putEmailIdentityConfigurationSetAttributes($identity, $configSetName);
            $steps[] = ['label' => 'SES default configuration set ['.$identityKey.']', 'ok' => true, 'details' => 'Assigned '.$configSetName.' to identity: '.$identity];
            $assignedIdentities[$identityKey] = true;
        }

        // 4. Tenant associations
        $this->associateTenantIfConfigured($identities, $configurationSets, $steps);

        return [
            'ok' => true,
            'steps' => $steps,
            'dns_records' => $dnsRecords,
        ];
    }

    /**
     * @return array{ok: bool, checks: array<int, array{key: string, label: string, ok: bool, details: string}>, dns_records: array<int, array{name: string, type: string, values: array<int, string>}>, identities_details: array<string, array<string, mixed>>}
     */
    public function check(): array
    {
        $checks = [];
        $dnsRecords = [];
        $identitiesDetails = [];
        $identities = $this->identities();
        $configurationSets = $this->configurationSets();

        foreach ($identities as $identityKey => $identityConfig) {
            $identity = $this->resolveIdentity($identityConfig);
            $suffix = count($identities) > 1 ? " [{$identityKey}]" : '';
            $keySuffix = count($identities) > 1 ? "_{$identityKey}" : '';
            $identityData = $this->api->getEmailIdentity($identity);

            $this->addCheck(
                $checks,
                'identity_exists'.$keySuffix,
                'SES identity exists'.$suffix,
                $identityData !== null,
                $identity
            );

            $mailFromAddress = trim((string) ($identityConfig['mail_from_address'] ?? ''));
            if ($mailFromAddress === '') {
                $mailFromAddress = trim((string) config('mail.from.address', ''));
            }

            if ($mailFromAddress === '') {
                $this->addCheck(
                    $checks,
                    'mail_from_address_matches_identity'.$keySuffix,
                    'mail_from_address matches SES identity'.$suffix,
                    false,
                    'mail_from_address is empty.'
                );
            } else {
                $matches = $this->mailFromAddressMatchesIdentity($identity, $mailFromAddress);
                $this->addCheck(
                    $checks,
                    'mail_from_address_matches_identity'.$keySuffix,
                    'mail_from_address matches SES identity'.$suffix,
                    $matches,
                    $matches
                        ? sprintf('mail_from_address "%s" matches identity "%s".', $mailFromAddress, $identity)
                        : sprintf('mail_from_address "%s" does not match identity "%s".', $mailFromAddress, $identity)
                );
            }

            $dkimStatus = (string) Arr::get($identityData, 'DkimAttributes.Status', '');
            if ($identityData !== null) {
                foreach ($this->buildDnsRecords($identity, $identityData) as $record) {
                    $record['identity'] = $identity;
                    $record['status'] = $dkimStatus;
                    $dnsRecords[] = $record;
                }
            }

            $mailFromDomain = trim((string) ($identityConfig['mail_from_domain'] ?? ''));
            $mailFromStatus = (string) Arr::get($identityData, 'MailFromAttributes.MailFromDomainStatus', '');
            if ($mailFromDomain !== '') {
                foreach ($this->buildMailFromDnsRecords($mailFromDomain) as $record) {
                    $record['identity'] = $identity;
                    $record['status'] = $mailFromStatus;
                    $dnsRecords[] = $record;
                }
            }

            $verifiedForSending = (bool) Arr::get($identityData, 'VerifiedForSendingStatus', false);
            $this->addCheck(
                $checks,
                'identity_verified'.$keySuffix,
                'SES identity verified for sending'.$suffix,
                $verifiedForSending,
                $verifiedForSending ? 'Verified' : 'Pending verification'
            );

            if ($mailFromDomain !== '') {
                $this->addCheck(
                    $checks,
                    'mail_from_status'.$keySuffix,
                    'SES MAIL FROM domain status'.$suffix,
                    in_array($mailFromStatus, ['SUCCESS', 'PENDING'], true),
                    $mailFromStatus !== '' ? $mailFromStatus : 'Not set'
                );
            }

            // Build per-identity detail record
            $identityDomain = $this->identityDomainFromIdentity($identity);
            $identityDetail = [
                'identity' => $identity,
                'domain' => $identityDomain,
                'dkim' => [
                    'status' => $dkimStatus,
                    'signing_enabled' => (bool) Arr::get($identityData, 'DkimAttributes.SigningEnabled', false),
                    'current_signing_key_length' => (string) Arr::get($identityData, 'DkimAttributes.CurrentSigningKeyLength', ''),
                    'next_signing_key_length' => (string) Arr::get($identityData, 'DkimAttributes.NextSigningKeyLength', ''),
                    'last_key_generation_timestamp' => (string) Arr::get($identityData, 'DkimAttributes.LastKeyGenerationTimestamp', ''),
                    'tokens' => (array) Arr::get($identityData, 'DkimAttributes.Tokens', []),
                ],
                'mail_from' => [
                    'domain' => (string) Arr::get($identityData, 'MailFromAttributes.MailFromDomain', ''),
                    'status' => $mailFromStatus,
                    'behavior_on_mx_failure' => (string) Arr::get($identityData, 'MailFromAttributes.BehaviorOnMxFailure', ''),
                ],
                'dmarc' => [
                    'record_name' => '_dmarc.'.$identityDomain,
                    'record_value' => '"v=DMARC1; p=none;"',
                ],
                'dns_records' => [],
            ];

            // Attach DKIM CNAME records
            if ($identityData !== null) {
                foreach ($this->buildDnsRecords($identity, $identityData) as $record) {
                    $identityDetail['dns_records'][] = array_merge($record, ['status' => $dkimStatus]);
                }
            }

            // Attach MAIL FROM MX/TXT records
            if ($mailFromDomain !== '') {
                foreach ($this->buildMailFromDnsRecords($mailFromDomain) as $record) {
                    $identityDetail['dns_records'][] = array_merge($record, ['status' => $mailFromStatus]);
                }
            }

            // Attach DMARC TXT record
            $identityDetail['dns_records'][] = [
                'name' => '_dmarc.'.$identityDomain,
                'type' => 'TXT',
                'values' => ['"v=DMARC1; p=none;"'],
                'status' => 'RECOMMENDED',
            ];

            $identitiesDetails[$identityKey] = $identityDetail;
        }

        $tenantName = $this->tenantName();
        if ($tenantName !== null) {
            $tenantExists = $this->api->tenantExists($tenantName);
            $this->addCheck($checks, 'tenant_exists', 'SES tenant exists', $tenantExists, $tenantName);

            if ($tenantExists) {
                $region = (string) config('messenger.ses_sns.aws.region', 'eu-central-1');
                $accountId = $this->api->getCallerAccountId();

                foreach ($identities as $identityKey => $identityConfig) {
                    $identity = $this->resolveIdentity($identityConfig);
                    $suffix = count($identities) > 1 ? " [{$identityKey}]" : '';
                    $keySuffix = count($identities) > 1 ? "_{$identityKey}" : '';

                    $identityArn = sprintf('arn:aws:ses:%s:%s:identity/%s', $region, $accountId, $identity);
                    $identityAssociated = $this->api->tenantHasResourceAssociation($tenantName, $identityArn);
                    $this->addCheck(
                        $checks,
                        'tenant_identity_association'.$keySuffix,
                        'SES tenant has identity association'.$suffix,
                        $identityAssociated,
                        $identityAssociated ? $identityArn : 'Missing association for: '.$identityArn
                    );
                }

                foreach ($configurationSets as $key => $set) {
                    $configSetName = $set['configuration_set'];
                    $suffix = count($configurationSets) > 1 ? " [{$key}]" : '';
                    $keySuffix = count($configurationSets) > 1 ? "_{$key}" : '';

                    if ($configSetName !== '') {
                        $configurationSetArn = sprintf('arn:aws:ses:%s:%s:configuration-set/%s', $region, $accountId, $configSetName);
                        $configurationSetAssociated = $this->api->tenantHasResourceAssociation($tenantName, $configurationSetArn);
                        $this->addCheck(
                            $checks,
                            'tenant_configuration_set_association'.$keySuffix,
                            'SES tenant has configuration set association'.$suffix,
                            $configurationSetAssociated,
                            $configurationSetAssociated ? $configurationSetArn : 'Missing association for: '.$configurationSetArn
                        );
                    }
                }
            }
        }

        $ok = collect($checks)->every(fn (array $check): bool => $check['ok']);

        return [
            'ok' => $ok,
            'checks' => $checks,
            'dns_records' => $dnsRecords,
            'identities_details' => $identitiesDetails,
        ];
    }

    /**
     * @return array<string, array{identity_domain?: string, mail_from_domain?: string, mail_from_address?: string}>
     */
    protected function identities(): array
    {
        $identities = (array) config('messenger.ses_sns.sending.identities', []);

        if ($identities === []) {
            throw new RuntimeException('No SES identities configured. Set messenger.ses_sns.sending.identities.');
        }

        return $identities;
    }

    /**
     * Resolve the SES identity string (domain or email) from an identity config entry.
     */
    protected function resolveIdentity(array $config): string
    {
        $domain = trim((string) ($config['identity_domain'] ?? ''));
        if ($domain !== '') {
            return $domain;
        }

        $email = trim((string) ($config['identity_email'] ?? ''));
        if ($email !== '') {
            return $email;
        }

        throw new RuntimeException('Identity entry has no identity_domain or identity_email.');
    }

    /**
     * @return array<string, array{configuration_set: string, event_destination: string, identity?: string}>
     */
    protected function configurationSets(): array
    {
        $sets = (array) config('messenger.ses_sns.configuration_sets', []);

        if ($sets === []) {
            throw new RuntimeException('No configuration sets configured. Set messenger.ses_sns.configuration_sets.');
        }

        return $sets;
    }

    /**
     * @param  array<int, array{name: string, type: string, values: array<int, string>}>  $dnsRecords
     * @param  array<int, array{label: string, ok: bool, details: string}>  $steps
     */
    protected function upsertDnsIfConfigured(string $identity, array $dnsRecords, array &$steps): void
    {
        $route53Enabled = (bool) config('messenger.ses_sns.sending.route53.enabled', false);
        $autoCreate = (bool) config('messenger.ses_sns.sending.route53.auto_create_records', false);

        if (! $route53Enabled || ! $autoCreate) {
            $steps[] = ['label' => 'Route53 DNS automation', 'ok' => true, 'details' => 'Skipped by configuration.'];

            return;
        }

        $hostedZoneId = (string) config('messenger.ses_sns.sending.route53.hosted_zone_id', '');
        if ($hostedZoneId === '') {
            $hostedZoneId = (string) $this->api->findHostedZoneIdByDomain($identityDomain = $this->identityDomainFromIdentity($identity));
            if ($hostedZoneId === '') {
                throw new RuntimeException('Route53 hosted zone not found for domain: '.$identityDomain);
            }
        }

        $ttl = (int) config('messenger.ses_sns.sending.route53.ttl', 300);
        foreach ($dnsRecords as $record) {
            $this->api->upsertRoute53Record(
                $hostedZoneId,
                $record['name'],
                $record['type'],
                $record['values'],
                $ttl
            );
        }

        $steps[] = ['label' => 'Route53 DNS automation', 'ok' => true, 'details' => 'Upserted '.count($dnsRecords).' record(s) in zone '.$hostedZoneId];
    }

    /**
     * @param  array<string, mixed>  $identityData
     * @return array<int, array{name: string, type: string, values: array<int, string>}>
     */
    protected function buildDnsRecords(string $identity, array $identityData): array
    {
        $records = [];
        $domain = $this->identityDomainFromIdentity($identity);
        $region = (string) config('messenger.ses_sns.aws.region', 'eu-central-1');

        $dkimTokens = (array) Arr::get($identityData, 'DkimAttributes.Tokens', []);
        foreach ($dkimTokens as $tokenRaw) {
            $token = trim((string) $tokenRaw);
            if ($token === '') {
                continue;
            }

            $records[] = [
                'name' => $token.'._domainkey.'.$domain,
                'type' => 'CNAME',
                'values' => [$token.'.dkim.'.$region.'.amazonses.com'],
            ];
        }

        return $records;
    }

    /**
     * @return array<int, array{name: string, type: string, values: array<int, string>}>
     */
    protected function buildMailFromDnsRecords(string $mailFromDomain): array
    {
        $region = (string) config('messenger.ses_sns.aws.region', 'eu-central-1');

        return [
            [
                'name' => $mailFromDomain,
                'type' => 'MX',
                'values' => ['10 feedback-smtp.'.$region.'.amazonses.com'],
            ],
            [
                'name' => $mailFromDomain,
                'type' => 'TXT',
                'values' => ['"v=spf1 include:amazonses.com -all"'],
            ],
        ];
    }

    protected function identityDomainFromIdentity(string $identity): string
    {
        if (str_contains($identity, '@')) {
            return substr(strrchr($identity, '@') ?: '', 1);
        }

        return $identity;
    }

    protected function mailFromAddressMatchesIdentity(string $identity, string $mailFromAddress): bool
    {
        if (str_contains($identity, '@')) {
            return strtolower($mailFromAddress) === strtolower($identity);
        }

        if (! str_contains($mailFromAddress, '@')) {
            return false;
        }

        $mailFromAddressDomain = substr(strrchr($mailFromAddress, '@') ?: '', 1);

        return strtolower($mailFromAddressDomain) === strtolower($identity);
    }

    protected function mailFromBehaviorOnMxFailure(): string
    {
        return (string) config('messenger.ses_sns.sending.mail_from_behavior_on_mx_failure', 'USE_DEFAULT_VALUE');
    }

    protected function tenantName(): ?string
    {
        $value = trim((string) config('messenger.ses_sns.tenant.name', ''));

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, array{identity_domain?: string, mail_from_domain?: string, mail_from_address?: string}>  $identities
     * @param  array<string, array{configuration_set: string, event_destination: string, identity?: string}>  $configurationSets
     * @param  array<int, array{label: string, ok: bool, details: string}>  $steps
     */
    protected function associateTenantIfConfigured(array $identities, array $configurationSets, array &$steps): void
    {
        $tenantName = $this->tenantName();
        if ($tenantName === null) {
            $steps[] = ['label' => 'SES tenant', 'ok' => true, 'details' => 'Skipped (not configured).'];

            return;
        }

        if (! $this->api->tenantExists($tenantName)) {
            $this->api->createTenant($tenantName);
            $steps[] = ['label' => 'SES tenant', 'ok' => true, 'details' => 'Created: '.$tenantName];
        } else {
            $steps[] = ['label' => 'SES tenant', 'ok' => true, 'details' => 'Already exists: '.$tenantName];
        }

        $region = (string) config('messenger.ses_sns.aws.region', 'eu-central-1');
        $accountId = $this->api->getCallerAccountId();

        foreach ($identities as $identityKey => $identityConfig) {
            $identity = $this->resolveIdentity($identityConfig);
            $suffix = count($identities) > 1 ? " [{$identityKey}]" : '';
            $identityArn = sprintf('arn:aws:ses:%s:%s:identity/%s', $region, $accountId, $identity);

            if (! $this->api->tenantHasResourceAssociation($tenantName, $identityArn)) {
                $this->api->associateTenantResource($tenantName, $identityArn);
                $steps[] = ['label' => 'SES tenant identity association'.$suffix, 'ok' => true, 'details' => 'Associated: '.$identityArn];
            } else {
                $steps[] = ['label' => 'SES tenant identity association'.$suffix, 'ok' => true, 'details' => 'Already associated: '.$identityArn];
            }
        }

        foreach ($configurationSets as $key => $set) {
            $configSetName = $set['configuration_set'];
            $suffix = count($configurationSets) > 1 ? " [{$key}]" : '';

            if ($configSetName === '') {
                $steps[] = ['label' => 'SES tenant configuration set association'.$suffix, 'ok' => true, 'details' => 'Skipped (configuration set not configured).'];

                continue;
            }

            $configurationSetArn = sprintf('arn:aws:ses:%s:%s:configuration-set/%s', $region, $accountId, $configSetName);
            if (! $this->api->tenantHasResourceAssociation($tenantName, $configurationSetArn)) {
                $this->api->associateTenantResource($tenantName, $configurationSetArn);
                $steps[] = ['label' => 'SES tenant configuration set association'.$suffix, 'ok' => true, 'details' => 'Associated: '.$configurationSetArn];
            } else {
                $steps[] = ['label' => 'SES tenant configuration set association'.$suffix, 'ok' => true, 'details' => 'Already associated: '.$configurationSetArn];
            }
        }
    }

    /**
     * @param  array<int, array{key: string, label: string, ok: bool, details: string}>  $checks
     */
    protected function addCheck(array &$checks, string $key, string $label, bool $ok, string $details): void
    {
        $checks[] = [
            'key' => $key,
            'label' => $label,
            'ok' => $ok,
            'details' => $details,
        ];
    }
}
