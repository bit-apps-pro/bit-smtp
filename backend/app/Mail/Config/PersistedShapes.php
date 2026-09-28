<?php

declare(strict_types=1);

namespace BitApps\SMTP\Mail\Config;

/**
 * PHPStan shape aliases for persistence and REST boundaries (analysis-only, not runtime).
 *
 * @phpstan-type PersistedConnectionV2 array{
 *   id: string, provider: string, kind: string, name: string, enabled: bool,
 *   fromEmail: string, fromName: string, replyToEmail: string,
 *   settings: array<string, mixed>,
 *   credentials: array<string, array{source: string, value: string}>
 * }
 * @phpstan-type PersistedSettingsV2 array{
 *   schema_version: int, enabled: bool, default_connection_id: string,
 *   fallback_connection_ids: list<string>,
 *   connections: list<PersistedConnectionV2>,
 *   features: array<string, mixed>
 * }
 * @phpstan-type ProviderMetadataShape array{
 *   key: string, label: string, kind: string,
 *   supports_webhook: bool, supports_webhook_provisioning: bool,
 *   oauth_redirect_url?: string,
 *   fields: list<array<string, mixed>>
 * }
 */
interface PersistedShapes
{
}
