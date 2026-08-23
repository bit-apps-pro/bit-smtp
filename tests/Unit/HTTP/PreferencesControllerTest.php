<?php

namespace BitApps\SMTP\Tests\Unit\HTTP;

use BitApps\SMTP\Deps\BitApps\WPKit\Http\Request\Request;
use BitApps\SMTP\Deps\BitApps\WPKit\Http\Response;
use BitApps\SMTP\HTTP\Controllers\PreferencesController;
use BitApps\SMTP\HTTP\Requests\SavePreferencesRequest;
use BitApps\SMTP\Settings\PluginSettings;
use BitApps\SMTP\Tests\BaseUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;

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
        // The base Request constructor always reads $_GET through wp_unslash(), even when empty.
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
        $this->assertSame(['general', 'reliability', 'health', 'privacy'], $groupNames);

        $logStoreBody = $this->fieldMetadata($data['groups'], 'general', 'log_store_body');
        $this->assertSame('enum', $logStoreBody['type']);
        $this->assertSame('full', $logStoreBody['default']);
        $this->assertSame(['full', 'redacted', 'metadata'], $logStoreBody['choices']);
    }

    public function testSavePersistsValidatedValuesAndEchoesThemBack(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\expect('update_option')
            ->once()
            ->with('bit_smtp_preferences', Mockery::on(static function (array $values): bool {
                return $values['log_retention_days'] === 60;
            }), 'yes')
            ->andReturn(true);

        $request = Mockery::mock(SavePreferencesRequest::class);
        $request->shouldReceive('validated')->once()->andReturn(['log_retention_days' => 60]);

        (new PreferencesController())->save($request);

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $data = (array) Response::getData();
        $this->assertSame(60, $data['preferences']['log_retention_days']);
    }

    public function testExportReturnsTheCurrentlyStoredPreferences(): void
    {
        Functions\when('get_option')->justReturn(['log_retention_days' => 14]);

        (new PreferencesController())->export();

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $data = (array) Response::getData();
        $this->assertSame(14, $data['preferences']['log_retention_days']);
    }

    public function testSavePreferencesRequestRulesRejectAnInvalidEnumValue(): void
    {
        $request = new SavePreferencesRequest();
        $request->make(['log_store_body' => 'bogus'], $request->rules());

        $this->assertTrue($request->fails());
        $this->assertArrayHasKey('log_store_body', $request->errors());
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
        Functions\when('get_option')->justReturn([]);
        Functions\expect('update_option')
            ->once()
            ->with('bit_smtp_preferences', Mockery::on(static function (array $values): bool {
                return !\array_key_exists('unknown_field', $values) && $values['logging_enabled'] === false;
            }), 'yes')
            ->andReturn(true);

        $request                      = new Request();
        $request['unknown_field']     = 'should-be-dropped';
        $request['logging_enabled']   = false;

        (new PreferencesController())->import($request);

        $this->assertSame(Response::SUCCESS, Response::getStatus());
        $data = (array) Response::getData();
        $this->assertArrayNotHasKey('unknown_field', $data['preferences']);
        $this->assertFalse($data['preferences']['logging_enabled']);
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
}
