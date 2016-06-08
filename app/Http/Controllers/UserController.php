<?php

namespace Northstar\Http\Controllers;

use Illuminate\Http\Request;
use Northstar\Auth\Registrar;
use Northstar\Auth\Scope;
use Northstar\Exceptions\NorthstarValidationException;
use Northstar\Http\Transformers\UserTransformer;
use Northstar\Services\Phoenix;
use Northstar\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UserController extends Controller
{
    /**
     * Phoenix Drupal API wrapper.
     * @var Phoenix
     */
    protected $phoenix;

    /**
     * The registrar.
     * @var Registrar
     */
    protected $registrar;

    /**
     * @var UserTransformer
     */
    protected $transformer;

    /**
     * UserController constructor.
     * @param Phoenix $phoenix
     * @param Registrar $registrar
     */
    public function __construct(Phoenix $phoenix, Registrar $registrar)
    {
        $this->phoenix = $phoenix;
        $this->registrar = $registrar;

        $this->transformer = new UserTransformer();

        $this->middleware('scope:admin', ['except' => ['show']]);
    }

    /**
     * Display a listing of the resource.
     * GET /users
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        // Create an empty User query, which we can either filter (below)
        // or paginate to retrieve all user records.
        $query = $this->newQuery(User::class);

        $filters = $request->query('filter');
        $query = $this->filter($query, $this->registrar->normalize($filters), User::$indexes);

        $searches = $request->query('search');
        $query = $this->search($query, $this->registrar->normalize($searches), User::$indexes);

        return $this->paginatedCollection($query, $request);
    }

    /**
     * Store a newly created resource in storage.
     * POST /users
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     * @throws NorthstarValidationException
     */
    public function store(Request $request)
    {
        // This endpoint will upsert by default (so it will either create a new user, or
        // update a user if one with a matching index field is found).
        $existing = $this->registrar->resolve($request->only('id', 'email', 'mobile', 'drupal_id'));

        // If `?upsert=false` and a record already exists, return a custom validation error.
        if (! filter_var($request->query('upsert', 'true'), FILTER_VALIDATE_BOOLEAN) && $existing) {
            throw new NorthstarValidationException(['id' => ['A record matching one of the given indexes already exists.']], $existing);
        }

        // Normalize input and validate the request
        $request = $this->registrar->normalize($request);
        $this->registrar->validate($request, $existing);

        // Makes sure we can't "upsert" a record to have a changed index if already set.
        // @TODO: There must be a better way to do this...
        foreach (User::$indexes as $index) {
            if ($request->has($index) && ! empty($existing->{$index}) && $request->input($index) !== $existing->{$index}) {
                app('stathat')->ezCount('upsert conflict');
                logger('attempted to upsert an existing index', [
                    'index' => $index,
                    'new' => $request->input($index),
                    'existing' => $existing->{$index},
                ]);

                throw new NorthstarValidationException([$index => ['Cannot upsert an existing index.']]);
            }
        }

        $upserting = ! is_null($existing);
        $user = $this->registrar->register($request->all(), $existing);

        // Optionally, allow setting a custom "created_at" (useful for back-filling from other services).
        if ($request->has('created_at')) {
            $user->created_at = $request->input('created_at');
            $user->save();
        }

        // Should we try to make a Drupal account for this user?
        if ($request->has('create_drupal_user') && $request->has('password') && ! $user->drupal_id) {
            $user = $this->registrar->createDrupalUser($user, $request->input('password'));
            $user->save();
        }

        $code = $upserting ? 200 : 201;

        return $this->item($user, $code);
    }

    /**
     * Display the specified resource.
     * GET /users/:term/:id
     *
     * @param string $term - term to search by (eg. mobile, drupal_id, id, email, etc)
     * @param string $id - the actual value to search for
     *
     * @return \Illuminate\Http\Response
     * @throws NotFoundHttpException
     */
    public function show($term, $id)
    {
        // Restrict email/mobile profile lookup to admin keys.
        if (in_array($term, ['email', 'mobile'])) {
            Scope::gate('admin');
        }

        // Find the user.
        $user = $this->registrar->resolve([$term => $id]);

        if (! $user) {
            throw new NotFoundHttpException('The resource does not exist.');
        }

        return $this->item($user);
    }

    /**
     * Update the specified resource in storage.
     * PUT /users/:term/:id
     *
     * @param string $term - term to search by (eg. mobile, drupal_id, id, email, etc)
     * @param string $id - the actual value to search for
     * @param Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function update($term, $id, Request $request)
    {
        $user = User::where($term, $id)->first();
        if (! $user) {
            throw new NotFoundHttpException('The resource does not exist.');
        }

        // Normalize input and validate the request
        $request = $this->registrar->normalize($request);
        $this->registrar->validate($request, $user);

        $user = $this->registrar->register($request->all(), $user);

        // Should we try to make a Drupal account for this user?
        if ($request->has('create_drupal_user') && $request->has('password') && ! $user->drupal_id) {
            $user = $this->registrar->createDrupalUser($user, $request->input('password'));
            $user->save();
        }

        return $this->item($user);
    }

    /**
     * Delete a user resource.
     * DELETE /users/:id
     *
     * @param $id - User ID
     * @return \Illuminate\Http\Response
     * @throws NotFoundHttpException
     */
    public function destroy($id)
    {
        $user = User::where('_id', $id)->first();

        if (! $user) {
            throw new NotFoundHttpException('The resource does not exist.');
        }

        $user->delete();

        return $this->respond('No Content.');
    }
}
