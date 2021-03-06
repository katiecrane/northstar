<?php

namespace Northstar\Auth;

use Hash;
use Illuminate\Contracts\Auth\Guard as Auth;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Factory as Validation;
use Illuminate\Http\Request;
use Northstar\Exceptions\NorthstarValidationException;
use Northstar\Models\Token;
use Northstar\Models\User;
use Northstar\Services\Phoenix;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class Registrar
{
    /**
     * The authentication guard.
     * @var Auth
     */
    protected $auth;

    /**
     * Phoenix Drupal API wrapper.
     * @var Phoenix
     */
    protected $phoenix;

    /**
     * Laravel's validation factory.
     * @var Validation
     */
    protected $validation;

    /**
     * Registrar constructor.
     * @param Auth $auth
     * @param Phoenix $phoenix
     * @param Validation $validation
     */
    public function __construct(Auth $auth, Phoenix $phoenix, Validation $validation)
    {
        $this->auth = $auth;
        $this->phoenix = $phoenix;
        $this->validation = $validation;
    }

    /**
     * Authenticate a user based on the given credentials,
     * and create a new session token.
     *
     * @param array $credentials
     * @return mixed
     */
    public function login($credentials)
    {
        $user = $this->resolve($credentials);

        if (! $this->verify($user, $credentials)) {
            throw new UnauthorizedHttpException(null, 'Invalid credentials.');
        }

        return $this->createToken($user);
    }

    /**
     * Normalize the given credentials in the array or request (for example, before
     * validating, or before saving to the database).
     *
     * @param \ArrayAccess|array $credentials
     * @return mixed
     */
    public function normalize($credentials)
    {
        // If a username is given, figure out whether it's an email or mobile number.
        if (! empty($credentials['username'])) {
            $type = $this->isEmail($credentials['username']) ? 'email' : 'mobile';
            $credentials[$type] = $credentials['username'];
            unset($credentials['username']);
        }

        // Map id to Mongo's _id ObjectID field.
        if (! empty($credentials['id'])) {
            $credentials['_id'] = $credentials['id'];
            unset($credentials['id']);
        }

        if (! empty($credentials['email'])) {
            $credentials['email'] = $this->normalizeEmail($credentials['email']);
        }

        if (! empty($credentials['mobile'])) {
            $credentials ['mobile'] = $this->normalizeMobile($credentials['mobile']);
        }

        return $credentials;
    }

    /**
     * Sanitize an email address before verifying or saving to the database.
     * This method will likely be called multiple times per user, so it *must*
     * provide the same result if so.
     * @TODO: This should be moved into a Normalizer class to DRY up.
     *
     * @param string $email
     * @return string
     */
    public function normalizeEmail($email)
    {
        return trim(strtolower($email));
    }

    /**
     * Sanitize a mobile number before verifying or saving to the database.
     * This method will likely be called multiple times per user, so it *must*
     * provide the same result if so.
     * @TODO: This should be moved into a Normalizer class to DRY up.
     *
     * @param string $mobile
     * @return string
     */
    public function normalizeMobile($mobile)
    {
        // Remove all non-numeric characters.
        $sanitizedValue = preg_replace('/[^0-9]/', '', $mobile);

        // @TODO: Look-ups should be normalized too, but waiting until data is fixed in prod.
        // If it's 11-digits and the leading digit is a 1, then remove country code.
        // if (strlen($sanitizedValue) === 11 && $sanitizedValue[0] === '1') {
        //     $sanitizedValue = substr($sanitizedValue, 1);
        // }

        return $sanitizedValue;
    }

    /**
     * Confirm that the given value is an e-mail address.
     *
     * @param string $value
     * @return bool
     */
    protected function isEmail($value)
    {
        return filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate the given user and request.
     *
     * @param Request $request
     * @param User $user
     * @param array $additionalRules
     * @throws NorthstarValidationException
     */
    public function validate(Request $request, User $user = null, array $additionalRules = [])
    {
        $fields = $request->all();

        $existingId = isset($user->id) ? $user->id : 'null';
        $rules = [
            'email' => 'email|unique:users,email,'.$existingId.',_id|required_without_all:mobile,facebook_id',
            'mobile' => 'mobile|unique:users,mobile,'.$existingId.',_id|required_without_all:email,facebook_id',
            'facebook_id' => 'numeric|unique:users,facebook_id,'.$existingId.',_id|required_without_all:email,mobile',
            'drupal_id' => 'unique:users,drupal_id,'.$existingId.',_id',
        ];

        // If a user is provided, merge it into the request so we can validate
        // the state of the "updated" document, rather than just the changes.
        if ($user) {
            $fields = array_merge($user->toArray(), $fields);
        }

        $validator = $this->validation->make($fields, array_merge($rules, $additionalRules));

        if ($validator->fails()) {
            throw new NorthstarValidationException($validator->errors()->getMessages());
        }
    }

    /**
     * Resolve a user account from the given credentials.
     *
     * @param array $credentials
     * @return User|null
     */
    public function resolve($credentials)
    {
        $credentials = $this->normalize($credentials);

        $matches = (new User)->query();

        // For the first `where` query, we want to limit results... from then on,
        // we want to append (e.g. `SELECT * WHERE _ OR WHERE _ OR WHERE _`)
        $firstWhere = true;
        foreach (User::$uniqueIndexes as $type) {
            if (isset($credentials[$type])) {
                $matches = $matches->where($type, '=', $credentials[$type], ($firstWhere ? 'and' : 'or'));
                $firstWhere = false;
            }
        }

        // If we did not query by any fields, return null.
        if ($firstWhere) {
            return null;
        }

        // If we found one user, return it.
        $matches = $matches->get();
        if (count($matches) == 1) {
            return $matches[0];
        }

        // If we can't conclusively resolve one user so return null.
        return null;
    }

    /**
     * Resolve a user account from the given credentials, or throw
     * an exception to trigger a 404 if not able to.
     *
     * @param $credentials
     * @return User|null
     */
    public function resolveOrFail($credentials)
    {
        $user = $this->resolve($credentials);

        if (! $user) {
            throw new ModelNotFoundException;
        }

        return $user;
    }

    /**
     * Verify the given user and credentials. If the user has a Drupal
     * password & it matches, re-hash and save to the user document.
     *
     * @param User $user
     * @param array $credentials
     * @return bool
     */
    public function verify($user, $credentials)
    {
        if (! $user) {
            return false;
        }

        if (Hash::check($credentials['password'], $user->password)) {
            return true;
        }

        if (! $user->password && DrupalPasswordHash::check($credentials['password'], $user->drupal_password)) {
            // If this user has a Drupal-hashed password, rehash it, remove the
            // Drupal password field from the user document, and save the user.
            $user->password = $credentials['password'];
            $user->save();

            return true;
        }

        // Well, looks like we couldn't authenticate...
        return false;
    }

    /**
     * Create a new authentication token & set the active user.
     *
     * @param User $user
     * @return Token
     */
    public function createToken($user)
    {
        $token = $user->login();

        $this->auth->setUser($user);

        return $token;
    }

    /**
     * Create a new user.
     *
     * @param array $input - Profile fields
     * @param User $user - Optionally, user to update
     * @return User|null
     */
    public function register($input, $user = null)
    {
        // If there is no user provided, create a new one.
        if (! $user) {
            $user = new User;
        }

        $user->fill($input);
        $user->save();

        return $user;
    }

    /**
     * Attempt to create a Drupal user for the given account.
     *
     * @param User $user
     * @param string $password
     * @return mixed
     */
    public function createDrupalUser($user, $password)
    {
        // @TODO: we can't create a Drupal user without an email. Do we just create an @mobile one like we had done previously?
        try {
            $drupal_id = $this->phoenix->register($user, $password);
            $user->drupal_id = $drupal_id;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // If user already exists (403 Forbidden), try to find the user to get the UID.
            if ($e->getCode() == 403) {
                try {
                    $drupal_id = $this->phoenix->getUidByEmail($user->email);
                    $user->drupal_id = $drupal_id;
                } catch (\Exception $e) {
                    // @TODO: still ok to just continue and allow the user to be saved?
                }
            }
        }

        return $user;
    }
}
