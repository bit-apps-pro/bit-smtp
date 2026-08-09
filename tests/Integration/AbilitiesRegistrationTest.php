<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Integration;

/**
 * @internal
 *
 * @coversNothing
 */
final class AbilitiesRegistrationTest extends IntegrationTestCase
{
    /**
     * @return array<int,string>
     */
    private const ABILITIES = [
        'bit-smtp/get-email-analytics',
        'bit-smtp/analyze-plugin-email',
        'bit-smtp/analyze-deliverability',
        'bit-smtp/explain-routing',
        'bit-smtp/detect-email-anomalies',
    ];

    public function testAnEarlyRegistryConsumerStillDiscoversTheAnalyticsCategoryAndFiveReadOnlyAdministratorAbilities(): void
    {
        $category = wp_get_ability_category('bit-smtp-analytics');

        self::assertNotNull($category);
        self::assertSame('Bit SMTP Email Analytics', $category->get_label());

        foreach (self::ABILITIES as $name) {
            $ability = wp_get_ability($name);

            self::assertNotNull($ability, "{$name} should be registered");
            self::assertSame($name, $ability->get_name());
            self::assertSame('bit-smtp-analytics', $ability->get_category());
            self::assertNotEmpty($ability->get_label());
            self::assertNotEmpty($ability->get_description());
            self::assertNotEmpty($ability->get_input_schema());
            self::assertNotEmpty($ability->get_output_schema());
            self::assertSame([
                'readonly'    => true,
                'destructive' => false,
                'idempotent'  => true,
            ], $ability->get_meta()['annotations']);
            self::assertTrue($ability->get_meta()['show_in_rest']);
        }
    }
}
