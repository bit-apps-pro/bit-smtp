<?php

namespace BitApps\SMTP\Tests\Unit\HTTP\Controllers;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\PreferencesController;
use BitApps\SMTP\HTTP\Requests\SavePreferencesRequest;
use BitApps\SMTP\HTTP\Services\LogService;
use BitApps\SMTP\Mail\Dispatch\FailureCategory;
use BitApps\SMTP\Mail\Notifications\AlertEvents;
use BitApps\SMTP\Mail\Notifications\HealthNotification;
use BitApps\SMTP\Settings\PluginSettings;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 *
 * @coversNothing
 */
final class PreferencesControllerTest extends BaseUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('__')->returnArg(1);

        Functions\when('wp_unslash')->returnArg(1);
    }

    public function testIndexReturnsDefaultsAndGroupMetadataOnFreshInstall(): void
    {
        Functions\when('get_option')->justReturn([]);

        (new PreferencesController())->index();

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $data = (array) Response::getData();

        $this->assertSame(PluginSettings::schema()->defaults(), $data['preferences']);

        $groupNames = array_column($data['groups'], 'name');
        $this->assertSame(['general', 'reliability', 'health', 'notifications', 'privacy'], $groupNames);

        $logStoreBody = $this->fieldMetadata($data['groups'], 'general', 'log_store_body');
        $this->assertSame('enum', $logStoreBody['type']);
        $this->assertSame('full', $logStoreBody['default']);
        $this->assertSame(['full', 'redacted', 'metadata'], $logStoreBody['choices']);
    }

    public function testSavePersistsValidatedValuesAndEchoesThemBack(): void
    {
        $store = [];
        $this->stubOptionsStore($store);

        $request = Mockery::mock(SavePreferencesRequest::class);
        $request->shouldReceive('validated')->once()->andReturn(['log_retention_days' => 60]);

        (new PreferencesController(new LogService()))->save($request);

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $data = (array) Response::getData();
        $this->assertSame(60, $data['preferences']['log_retention_days']);

        $this->assertSame(60, $store['bit_smtp_log_retention']);
        $this->assertSame(60, $store[PluginSettings::OPTION_NAME]['log_retention_days']);
    }

    public function testExportReturnsTheCurrentlyStoredPreferences(): void
    {
        Functions\when('get_option')->justReturn(['log_retention_days' => 14]);

        (new PreferencesController())->export();

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $data = (array) Response::getData();
        $this->assertSame(14, $data['preferences']['log_retention_days']);
    }

    public function testExportedEnvelopeRoundTripsBackThroughImport(): void
    {
        $store = [PluginSettings::OPTION_NAME => ['log_retention_days' => 90]];
        $this->stubOptionsStore($store);

        (new PreferencesController())->export();
        $exported = (array) Response::getData();
        $this->assertSame(90, $exported['preferences']['log_retention_days']);

        $store                  = [];
        $request                = new Request();
        $request['preferences'] = $exported['preferences'];

        (new PreferencesController(new LogService()))->import($request);

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $this->assertSame(90, $store[PluginSettings::OPTION_NAME]['log_retention_days']);
        $this->assertSame(90, ((array) Response::getData())['preferences']['log_retention_days']);
    }

    public function testSavePreferencesRequestRulesRejectAnInvalidEnumValue(): void
    {
        $request = new SavePreferencesRequest();
        $request->make(['log_store_body' => 'bogus'], $request->rules());

        $this->assertTrue($request->fails());
        $this->assertArrayHasKey('log_store_body', $request->errors());
    }

    public function testSavePreferencesRequestRulesAcceptAKnownEventSubscription(): void
    {
        $request = new SavePreferencesRequest();
        $request->make(
            ['notify_events' => [HealthNotification::EVENT_UNHEALTHY, HealthNotification::EVENT_OAUTH_EXPIRED]],
            $request->rules()
        );

        $this->assertFalse($request->fails());
        $this->assertSame(
            [HealthNotification::EVENT_UNHEALTHY, HealthNotification::EVENT_OAUTH_EXPIRED],
            $request->validated()['notify_events']
        );
    }

    public function testSavePreferencesRequestRulesRejectAnUnknownEventKey(): void
    {
        $request = new SavePreferencesRequest();
        $request->make(['notify_events' => [HealthNotification::EVENT_UNHEALTHY, 'not_an_event']], $request->rules());

        $this->assertTrue($request->fails());
        $this->assertArrayHasKey('notify_events', $request->errors());
    }

    public function testSavePreferencesRequestRulesAcceptKnownRetryClasses(): void
    {
        $request = new SavePreferencesRequest();
        $request->make(['retry_on_classes' => [FailureCategory::TRANSIENT, FailureCategory::RATE_LIMITED]], $request->rules());

        $this->assertFalse($request->fails());
        $this->assertSame(
            [FailureCategory::TRANSIENT, FailureCategory::RATE_LIMITED],
            $request->validated()['retry_on_classes']
        );
    }

    public function testSavePreferencesRequestRulesRejectANonRetryableClass(): void
    {
        $request = new SavePreferencesRequest();
        $request->make(['retry_on_classes' => [FailureCategory::TRANSIENT, FailureCategory::AUTH]], $request->rules());

        $this->assertTrue($request->fails());
        $this->assertArrayHasKey('retry_on_classes', $request->errors());
    }

    public function testSavePreferencesRequestRulesAcceptTheSendFailureSubscription(): void
    {
        $request = new SavePreferencesRequest();
        $request->make(['notify_events' => [AlertEvents::SEND_FAILURE]], $request->rules());

        $this->assertFalse($request->fails());
    }

    /**
     * @return array<string,array{0:string,1:int,2:bool}>
     */
    public static function boundedIntegerProvider(): array
    {
        return [
            'timeout below range'  => ['send_timeout_seconds', 4, true],
            'timeout lower bound'  => ['send_timeout_seconds', 5, false],
            'timeout upper bound'  => ['send_timeout_seconds', 120, false],
            'timeout above range'  => ['send_timeout_seconds', 300, true],
            'attempts below range' => ['retry_max_attempts', 0, true],
            'attempts upper bound' => ['retry_max_attempts', 10, false],
            'attempts above range' => ['retry_max_attempts', 11, true],
        ];
    }

    #[DataProvider('boundedIntegerProvider')]
    public function testSavePreferencesRequestRulesBoundTimeoutAndAttempts(string $key, int $value, bool $fails): void
    {
        $request = new SavePreferencesRequest();
        $request->make([$key => $value], $request->rules());

        $this->assertSame($fails, $request->fails());
    }

    public function testImportUpgradesALegacyExportInsteadOfRejectingIt(): void
    {
        $store = [];
        $this->stubOptionsStore($store);

        $request                            = new Request();
        $request['retry_on_classes']        = [];
        $request['notify_events']           = [HealthNotification::EVENT_UNHEALTHY];
        $request['notify_cooldown_minutes'] = 30;

        (new PreferencesController(new LogService()))->import($request);

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $saved = $store[PluginSettings::OPTION_NAME];
        $this->assertSame(FailureCategory::retryableClasses(), $saved['retry_on_classes']);
        $this->assertSame([AlertEvents::SEND_FAILURE, HealthNotification::EVENT_UNHEALTHY], $saved['notify_events']);
        $this->assertArrayNotHasKey('notify_cooldown_minutes', $saved);
    }

    public function testExportedRetryNothingSurvivesTheImportRoundTrip(): void
    {
        $store = [PluginSettings::OPTION_NAME => ['retry_on_classes' => []]];
        $this->stubOptionsStore($store);

        (new PreferencesController())->export();
        $exported = (array) Response::getData();

        $store                  = [];
        $request                = new Request();
        $request['preferences'] = $exported['preferences'];

        (new PreferencesController(new LogService()))->import($request);

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $this->assertSame([], $store[PluginSettings::OPTION_NAME]['retry_on_classes']);
    }

    /**
     * "Explicit none" path: empty notify_events array is deliberate unsubscribe, persists as empty, not defaulted.
     */
    public function testSaveAcceptsAndPersistsAnEmptyNotifyEventsSubscription(): void
    {
        $store = [PluginSettings::OPTION_NAME => ['notify_events' => [HealthNotification::EVENT_UNHEALTHY]]];
        $this->stubOptionsStore($store);

        $request = new SavePreferencesRequest();
        $request->make(['notify_events' => []], $request->rules());
        $this->assertFalse($request->fails());

        (new PreferencesController())->save($request);

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $data = (array) Response::getData();
        $this->assertSame([], $data['preferences']['notify_events']);
        $this->assertSame([], $store[PluginSettings::OPTION_NAME]['notify_events']);
    }

    public function testImportRejectsAnInvalidEnumValueAndDoesNotPersist(): void
    {
        Functions\expect('update_option')->never();

        $request                    = new Request();
        $request['log_store_body']  = 'bogus';

        (new PreferencesController())->import($request);

        $this->assertSame(Response::ERROR, Response::getStatus());
    }

    public function testImportIgnoresUnknownKeysAndPersistsOnlyKnownOnes(): void
    {
        $store = [];
        $this->stubOptionsStore($store);

        $request                      = new Request();
        $request['unknown_field']     = 'should-be-dropped';
        $request['logging_enabled']   = false;

        (new PreferencesController(new LogService()))->import($request);

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $data = (array) Response::getData();
        $this->assertArrayNotHasKey('unknown_field', $data['preferences']);
        $this->assertFalse($data['preferences']['logging_enabled']);

        $this->assertSame(0, $store['bit_smtp_logging_enabled']);
        $this->assertArrayNotHasKey('bit_smtp_logging_continuity_from', $store);
    }

    /**
     * FIX 1 regression guard: changed logging_enabled must go through LogService::setEnabled() to clear continuity.
     */
    public function testSaveWithChangedLoggingEnabledDisablesLoggingAndClearsContinuityMarker(): void
    {
        $store = [
            PluginSettings::OPTION_NAME        => ['logging_enabled' => true],
            'bit_smtp_logging_enabled'         => 1,
            'bit_smtp_logging_continuity_from' => '2026-03-01 00:00:00',
        ];
        $this->stubOptionsStore($store);

        $request = Mockery::mock(SavePreferencesRequest::class);
        $request->shouldReceive('validated')->once()->andReturn(['logging_enabled' => false]);

        (new PreferencesController(new LogService()))->save($request);

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $data = (array) Response::getData();
        $this->assertFalse($data['preferences']['logging_enabled']);
        $this->assertSame(0, $store['bit_smtp_logging_enabled']);
        $this->assertArrayNotHasKey('bit_smtp_logging_continuity_from', $store);
    }

    /**
     * FIX 1 regression guard: re-enabling logging must initialize continuity marker via LogService::setEnabled().
     */
    public function testSaveWithChangedLoggingEnabledEnablesLoggingAndInitializesContinuityMarker(): void
    {
        $store = [
            PluginSettings::OPTION_NAME => ['logging_enabled' => false],
            'bit_smtp_logging_enabled'  => 0,
        ];
        $this->stubOptionsStore($store);

        $request = Mockery::mock(SavePreferencesRequest::class);
        $request->shouldReceive('validated')->once()->andReturn(['logging_enabled' => true]);

        (new PreferencesController(new LogService()))->save($request);

        $data = (array) Response::getData();
        $this->assertTrue($data['preferences']['logging_enabled']);
        $this->assertSame(1, $store['bit_smtp_logging_enabled']);
        $this->assertArrayHasKey('bit_smtp_logging_continuity_from', $store);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $store['bit_smtp_logging_continuity_from']
        );
    }

    /**
     * FIX 1: unchanged logging_enabled must not call setEnabled() and reset continuity marker.
     */
    public function testSaveWithUnchangedLoggingEnabledDoesNotResetContinuityMarker(): void
    {
        $store = [
            PluginSettings::OPTION_NAME        => ['logging_enabled' => true],
            'bit_smtp_logging_enabled'         => 1,
            'bit_smtp_logging_continuity_from' => '2026-03-01 00:00:00',
        ];
        $this->stubOptionsStore($store);

        $request = Mockery::mock(SavePreferencesRequest::class);
        $request->shouldReceive('validated')->once()->andReturn([
            'logging_enabled' => true,
            'log_store_body'  => 'redacted',
        ]);

        (new PreferencesController(new LogService()))->save($request);

        $data = (array) Response::getData();
        $this->assertTrue($data['preferences']['logging_enabled']);
        $this->assertSame('redacted', $data['preferences']['log_store_body']);

        $this->assertSame('2026-03-01 00:00:00', $store['bit_smtp_logging_continuity_from']);
    }

    /**
     * Pull one field's metadata out of the index() `groups` payload by group and field key.
     *
     * @param array<int,array<string,mixed>> $groups
     *
     * @return array<string,mixed>
     */
    private function fieldMetadata(array $groups, string $groupName, string $fieldKey): array
    {
        foreach ($groups as $group) {
            if ($group['name'] !== $groupName) {
                continue;
            }

            foreach ($group['fields'] as $field) {
                if ($field['key'] === $fieldKey) {
                    return $field;
                }
            }
        }

        $this->fail(\sprintf('Field "%s" not found in group "%s"', $fieldKey, $groupName));
    }

    /**
     * Wires get_option/update_option/delete_option against in-memory map, mirrors real preferences/LogService persistence.
     *
     * @param array<string,mixed> $store
     */
    private function stubOptionsStore(array &$store): void
    {
        Functions\when('get_option')->alias(static function (string $key, $default = false) use (&$store) {
            return \array_key_exists($key, $store) ? $store[$key] : $default;
        });
        Functions\when('update_option')->alias(static function (string $key, $value) use (&$store): bool {
            $store[$key] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(static function (string $key) use (&$store): bool {
            unset($store[$key]);

            return true;
        });
    }
}
