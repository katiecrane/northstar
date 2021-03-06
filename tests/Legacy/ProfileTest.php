<?php

use Northstar\Models\User;

class ProfileTest extends TestCase
{
    /**
     * Test that a user can see their own profile.
     * GET /profile
     *
     * @test
     */
    public function testGetProfile()
    {
        $user = User::create([
            'email' => $this->faker->email,
            'first_name' => $this->faker->firstName,
            'last_name' => $this->faker->lastName,
        ]);

        // Try to register an account that already exists, but with different capitalization
        $this->asUserUsingLegacyAuth($user)->withLegacyApiKeyScopes(['user'])->get('v1/profile');
        $this->assertResponseStatus(200);
        $this->seeJsonSubset([
            'data' => [
                'id' => $user->id,
                'email' => $user->email,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
            ],
        ]);
    }

    /**
     * Test that a user can modify their own profile.
     * POST /profile
     *
     * @test
     */
    public function testUpdateProfile()
    {
        $user = User::create([
            'email' => $this->faker->email,
            'first_name' => $this->faker->firstName,
            'last_name' => $this->faker->lastName,
            'drupal_id' => 123456,
            'role' => 'user',
        ]);

        $this->asUserUsingLegacyAuth($user)->withLegacyApiKeyScopes(['user'])->json('POST', 'v1/profile', [
            'mobile' => '(555) 123-4567',
            'language' => 'en',
            'drupal_id' => 666666,
            'role' => 'admin',
        ]);

        $this->assertResponseStatus(200);
        $this->seeJsonSubset([
            'data' => [
                'id' => $user->id,
                'email' => $user->email,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'drupal_id' => 123456, // shouldn't have changed, field is read-only for users!
                'role' => 'user', // shouldn't have changed, field is read-only for users!
                'mobile' => '5551234567', // should be normalized!
                'language' => 'en',
            ],
        ]);
    }
}
