<?php

namespace Tests\Feature\Auth;

use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * Public sign-up is a switch, and the safe position is off.
 *
 * This application stores the database passwords and SSH keys of every server
 * it backs up, so an open /register on a public hostname hands them to whoever
 * finds the URL. It is needed exactly once — to create the first account.
 *
 * The config file is evaluated directly here because the flag is read at config
 * load time; asserting against the already-booted config would only re-read
 * whatever the suite happened to start with.
 */
class RegistrationGateTest extends TestCase
{
    /**
     * Evaluates config/fortify.php with the flag set to $value.
     *
     * env() reads $_ENV and $_SERVER as well as getenv(), and phpunit.xml
     * populates all three — so all three have to move together or the config
     * keeps seeing the suite's own value.
     *
     * @return list<mixed>
     */
    private function featuresWithFlag(?string $value): array
    {
        $key = 'REGISTRATION_ENABLED';
        $previous = $_ENV[$key] ?? null;

        $this->putEnvEverywhere($key, $value);

        try {
            $config = require config_path('fortify.php');
        } finally {
            $this->putEnvEverywhere($key, $previous);
        }

        return $config['features'];
    }

    private function putEnvEverywhere(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public function test_registration_is_absent_when_the_flag_is_unset(): void
    {
        $this->assertNotContains(
            Features::registration(),
            $this->featuresWithFlag(null),
            'Sign-up must be off unless it is explicitly switched on.'
        );
    }

    public function test_registration_is_absent_when_the_flag_is_false(): void
    {
        $this->assertNotContains(Features::registration(), $this->featuresWithFlag('false'));
    }

    public function test_registration_is_present_when_the_flag_is_true(): void
    {
        $this->assertContains(Features::registration(), $this->featuresWithFlag('true'));
    }

    public function test_the_other_features_survive_the_filtering(): void
    {
        // array_filter() drops the null the flag leaves behind; it must not
        // drop anything else, and array_values() keeps the list contiguous.
        $features = $this->featuresWithFlag(null);

        $this->assertContains(Features::resetPasswords(), $features);
        $this->assertContains(Features::emailVerification(), $features);
        $this->assertSame(array_keys($features), range(0, count($features) - 1));
    }
}
