<?php

use League\OAuth2\Server\CryptKey;
use Northstar\Auth\Entities\AccessTokenEntity;
use Northstar\Auth\Entities\ClientEntity;
use Northstar\Auth\Entities\ScopeEntity;
use Northstar\Auth\Scope;
use Northstar\Models\Client;
use Northstar\Models\User;

class TestCase extends Illuminate\Foundation\Testing\TestCase
{
    /**
     * The base URL to use while testing the application.
     *
     * @var string
     */
    protected $baseUrl = 'http://localhost';

    /**
     * Additional server variables for the request.
     *
     * @var array
     */
    protected $serverVariables = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_Accept' => 'application/json',
    ];

    /**
     * The Faker generator, for creating test data.
     *
     * @var \Faker\Generator
     */
    protected $faker;

    /**
     * Setup the test environment. This is run before *every* single
     * test method, so avoid doing anything that takes too much time!
     *
     * @return void
     */
    public function setUp()
    {
        parent::setUp();

        // Get a new Faker generator from Laravel.
        $this->faker = app(\Faker\Generator::class);

        // Reset the testing database & run migrations.
        $this->app->make('db')->getMongoDB()->drop();
        $this->artisan('migrate');
    }

    /**
     * Use the given API key for this request.
     *
     * @param Client $client
     * @return $this
     */
    public function withLegacyApiKey(Client $client)
    {
        $this->serverVariables = array_replace($this->serverVariables, [
            'HTTP_X-DS-REST-API-Key' => $client->client_secret,
        ]);

        return $this;
    }

    /**
     * Set an API key with the given scopes on the request.
     *
     * @param array $scopes
     * @return $this
     */
    public function withLegacyApiKeyScopes(array $scopes)
    {
        $client = Client::create([
            'client_id' => 'testing'.$this->faker->uuid,
            'scope' => $scopes,
        ]);

        $this->withLegacyApiKey($client);

        return $this;
    }

    /**
     * Make the following request as a normal user with the `user` scope.
     *
     * @return $this
     */
    public function asNormalUser()
    {
        $user = factory(User::class)->create();

        return $this->asUser($user, ['user']);
    }

    /**
     * Make the following request as a staff user with the `user` and `role:staff` scopes.
     *
     * @return $this
     */
    public function asStaffUser()
    {
        $staff = factory(User::class, 'staff')->create();

        return $this->asUser($staff, ['user', 'role:staff']);
    }

    /**
     * Make the following request as an admin user with the `user` and `role:admin` scopes.
     *
     * @return $this
     */
    public function asAdminUser()
    {
        $admin = factory(User::class, 'admin')->create();

        return $this->asUser($admin, ['user', 'role:admin']);
    }

    /**
     * Create a signed JWT to authorize resource requests.
     *
     * @param User $user
     * @param array $scopes
     * @return $this
     */
    public function asUser($user, $scopes = [])
    {
        $accessToken = new AccessTokenEntity();
        $accessToken->setClient(new ClientEntity('phpunit', $scopes));
        $accessToken->setIdentifier(bin2hex(random_bytes(40)));
        $accessToken->setExpiryDateTime((new \DateTime())->add(new DateInterval('PT1H')));

        $accessToken->setUserIdentifier($user->id);
        $accessToken->setRole($user->role);

        foreach ($scopes as $identifier) {
            if (! array_key_exists($identifier, Scope::all())) {
                continue;
            }

            $entity = new ScopeEntity();
            $entity->setIdentifier($identifier);
            $accessToken->addScope($entity);
        }

        $header = 'Bearer '.$accessToken->convertToJWT(new CryptKey(base_path('storage/keys/private.key')));
        $this->serverVariables = array_replace($this->serverVariables, [
            'HTTP_Authorization' => $header,
        ]);

        return $this;
    }

    /**
     * Set the currently logged in user for the application. Use this instead of Laravel's
     * built-in $this->actingAs() or $this->be() because it will create an actual token in
     * the database to be manipulated/checked & set proper authentication header.
     *
     * @param User $user
     * @return $this
     */
    public function asUserUsingLegacyAuth(User $user)
    {
        $token = $user->login();
        $this->serverVariables = array_replace($this->serverVariables, [
            'HTTP_Authorization' => 'Bearer '.$token->key,
        ]);

        return $this;
    }

    /**
     * Get the raw Mongo document for inspection.
     *
     * @param $collection - Mongo Collection name
     * @param $id - The _id of the document to fetch
     * @return array
     */
    public function getMongoDocument($collection, $id)
    {
        $document = $this->app->make('db')->collection($collection)->where(['_id' => $id])->first();

        $this->assertNotNull($document, sprintf(
            'Unable to find document in collection [%s] with _id [%s].', $collection, $id
        ));

        return $document;
    }

    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

        return $app;
    }

    /**
     * Mock a class, and register with the IoC container.
     *
     * @param $class String - Class name to mock
     * @return \Mockery\MockInterface
     */
    public function mock($class)
    {
        $mock = Mockery::mock($class);

        $this->app->instance($class, $mock);

        return $mock;
    }
}
